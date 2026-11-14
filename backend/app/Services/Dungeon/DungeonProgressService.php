<?php

namespace App\Services\Dungeon;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserDungeonProgress;
use App\Services\Player\PartyGoldService;
use App\Services\Player\UserCharacterService;

class DungeonProgressService
{
    public function __construct(
        private readonly PartyGoldService $gold = new PartyGoldService,
        private readonly UserCharacterService $characters = new UserCharacterService,
    ) {}
    public function getStep(User $user): int
    {
        return (int) ($this->findOrCreate($user)->step ?? 0);
    }

    public function getFloor(User $user): int
    {
        return (int) ($this->findOrCreate($user)->floor ?? 1);
    }

    public function getUnlockedFloor(User $user): int
    {
        return (int) ($this->findOrCreate($user)->unlocked_floor ?? 1);
    }

    /** 全ての層（最終層まで）を踏破したか */
    public function hasClearedAllFloors(User $user): bool
    {
        return $this->getClearedFloor($user) >= DungeonFloorConfig::maxFloor();
    }

    /** クリア済みの最深層（0 = 未クリア） */
    public function getClearedFloor(User $user): int
    {
        return (int) ($this->findOrCreate($user)->cleared_floor ?? 0);
    }

    public function isFloorPlayable(User $user, int $floor): bool
    {
        if ($floor < 1 || $floor > DungeonFloorConfig::maxFloor()) {
            return false;
        }

        if ($floor > DungeonFloorConfig::playableFloor()) {
            return false;
        }

        return $floor <= $this->getUnlockedFloor($user);
    }

    public function isInDungeon(User $user): bool
    {
        return (bool) $this->findOrCreate($user)->in_dungeon;
    }

    /**
     * @return array<string, int|bool>
     */
    public function statusPayload(User $user): array
    {
        $record = $this->findOrCreate($user);
        $session = DungeonExplorationSession::query()
            ->where('user_id', $user->id)
            ->first();

        return [
            'floor' => (int) $record->floor,
            'step' => (int) $record->step,
            'unlocked_floor' => (int) $record->unlocked_floor,
            'cleared_floor' => (int) $record->cleared_floor,
            'all_floors_cleared' => (int) $record->cleared_floor >= DungeonFloorConfig::maxFloor(),
            // 空間の裂け目でボス戦が始まったことがある（層選択画面に「時空の裂け目へ」を出す）
            'rift_reached' => (bool) $record->rift_reached,
            'max_floor' => DungeonFloorConfig::maxFloor(),
            'playable_floor' => DungeonFloorConfig::playableFloor(),
            'boss_step' => DungeonFloorConfig::bossStep((int) $record->floor),
            'gold' => $this->gold->getGold($user),
            'in_dungeon' => (bool) $record->in_dungeon,
            'exploration_session_id' => $session?->id,
        ];
    }

    public function decreaseStep(User $user, int $amount, int $minimum = 1): int
    {
        $record = $this->findOrCreate($user);
        $record->step = max($minimum, (int) $record->step - $amount);
        $record->save();

        return (int) $record->step;
    }

    /**
     * @return array<string, int|bool>
     */
    public function retreat(User $user): array
    {
        DungeonExplorationSession::query()->where('user_id', $user->id)->delete();
        $user->refresh();
        $this->gold->applyRetreatPenalty($user);
        $this->characters->restorePartyToFull($user);
        $record = $this->findOrCreate($user);
        $record->step = 0;
        $record->in_dungeon = false;
        $record->once_event_codes = [];
        $record->save();
        $user->refresh();

        return $this->statusPayload($user->fresh());
    }

    public function enterFloor(User $user, int $floor): void
    {
        if (! $this->isFloorPlayable($user, $floor)) {
            throw new \InvalidArgumentException('この層にはまだ挑戦できません。');
        }

        $record = $this->findOrCreate($user);
        $record->floor = $floor;
        $record->in_dungeon = true;
        // 新しい挑戦の始まり。「1 回の挑戦で最大 1 回」のイベントは、また出るようになる
        $record->once_event_codes = [];
        $record->save();
    }

    /**
     * 今の挑戦（層に入ってから、撤退・全滅・ボス撃破で出るまで）で、すでに出た「1 回だけ」のイベントのコード。
     *
     * @return list<string>
     */
    public function onceEventCodes(User $user): array
    {
        return array_values(array_map('strval', (array) ($this->findOrCreate($user)->once_event_codes ?? [])));
    }

    /** 空間の裂け目で、ボス戦が始まったことがあるか（一度なったら、ホーム画面のリセットまで、ずっと true）。 */
    public function hasReachedRift(User $user): bool
    {
        return (bool) $this->findOrCreate($user)->rift_reached;
    }

    /** 空間の裂け目でボス戦が始まったことを記録する。 */
    public function markRiftReached(User $user): void
    {
        $record = $this->findOrCreate($user);
        if (! $record->rift_reached) {
            $record->rift_reached = true;
            $record->save();
        }
    }

    /** 「1 回だけ」のイベントが出たことを、今の挑戦に記録する（次の抽選からは出ない）。 */
    public function markOnceEvent(User $user, string $eventCode): void
    {
        $record = $this->findOrCreate($user);
        $codes = array_values(array_map('strval', (array) ($record->once_event_codes ?? [])));
        if (! in_array($eventCode, $codes, true)) {
            $codes[] = $eventCode;
            $record->once_event_codes = $codes;
            $record->save();
        }
    }

    public function reset(User $user): void
    {
        $record = $this->findOrCreate($user);
        $record->step = 0;
        $record->floor = 1;
        $record->in_dungeon = false;
        $record->once_event_codes = [];
        $record->save();
    }

    /** ホーム画面リセット用: 層・深さ・解放層・探索セッションを初期化 */
    public function resetAll(User $user): void
    {
        $record = $this->findOrCreate($user);
        $record->floor = 1;
        $record->step = 0;
        $record->unlocked_floor = 1;
        $record->cleared_floor = 0;
        $record->skip_battle_encounters = false;
        $record->force_dialogue_events = false;
        $record->in_dungeon = false;
        $record->once_event_codes = [];
        $record->rift_reached = false;
        $record->save();

        DungeonExplorationSession::query()->where('user_id', $user->id)->delete();
    }

    public function resetStep(User $user): void
    {
        $record = $this->findOrCreate($user);
        $record->step = 0;
        $record->save();
    }

    public function increment(User $user): int
    {
        $record = $this->findOrCreate($user);
        $record->step = min(65535, (int) $record->step + 1);
        $record->save();

        return (int) $record->step;
    }

    public function shouldSkipBattles(User $user): bool
    {
        return (bool) $this->findOrCreate($user)->skip_battle_encounters;
    }

    public function setSkipBattles(User $user, bool $skip): void
    {
        $record = $this->findOrCreate($user);
        $record->skip_battle_encounters = $skip;
        $record->save();
    }

    public function shouldForceDialogueEvents(User $user): bool
    {
        return (bool) $this->findOrCreate($user)->force_dialogue_events;
    }

    public function setForceDialogueEvents(User $user, bool $force): void
    {
        $record = $this->findOrCreate($user);
        $record->force_dialogue_events = $force;
        $record->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function onBossVictory(User $user, int $floor): array
    {
        DungeonExplorationSession::query()->where('user_id', $user->id)->delete();

        $record = $this->findOrCreate($user);
        $maxFloor = DungeonFloorConfig::maxFloor();

        // その層を初めてクリアしたかどうか（クリア済みの層のボスを倒し直しても、最大MPの加算は一度だけ）
        $firstClear = (int) $record->cleared_floor < $floor;
        $this->applyFloorCleared($record, $floor);

        $record->step = 0;
        $record->in_dungeon = false;
        $record->once_event_codes = [];
        $record->save();

        if ($firstClear) {
            $this->characters->grantFirstClearMaxMpBonus($user, $floor);
        }

        // 待機画面へ戻るので、撤退・敗北時と同様にパーティを全回復する（レベルアップ反映後・最大MP加算後の最大値）
        $this->characters->restorePartyToFull($user);

        $nextFloor = $floor + 1;
        $nextPlayable = $nextFloor <= DungeonFloorConfig::playableFloor()
            && $nextFloor <= (int) $record->unlocked_floor;
        // 最後の層（3 層）を踏破したときは、「さらに深い層」はそもそも無い。層はあるのに準備ができていないときだけ、準備中と伝える
        $hasDeeperFloor = $nextFloor <= $maxFloor;

        return [
            'type' => 'dungeon_floor_cleared',
            'floor' => $floor,
            'unlocked_floor' => (int) $record->unlocked_floor,
            'next_floor' => $nextFloor <= $maxFloor ? $nextFloor : null,
            'next_floor_playable' => $nextPlayable,
            'text' => $nextPlayable || ! $hasDeeperFloor
                ? "{$floor}層を踏破した！"
                : "{$floor}層を踏破した！　だが、さらに深い層はまだ準備が整っていない……",
        ];
    }

    /**
     * 管理画面用: 指定した層をクリア済みにする（元に戻す手段はない）。
     * その層より浅い層もクリア済みになり、次の層が解放される。クリア済みの層を下げることはしない。
     */
    public function markFloorCleared(User $user, int $floor): void
    {
        if ($floor < 1 || $floor > DungeonFloorConfig::maxFloor()) {
            throw new \InvalidArgumentException('その層は存在しません。');
        }

        $record = $this->findOrCreate($user);
        $this->applyFloorCleared($record, $floor);
        $record->save();
    }

    /** 層のクリアを記録し、次の層（最終層をクリアしたときは最終層まで）を解放する（保存は呼び出し側） */
    private function applyFloorCleared(UserDungeonProgress $record, int $floor): void
    {
        $record->cleared_floor = max((int) $record->cleared_floor, $floor);
        $record->unlocked_floor = max(
            (int) $record->unlocked_floor,
            min($floor + 1, DungeonFloorConfig::maxFloor()),
        );
    }

    private function findOrCreate(User $user): UserDungeonProgress
    {
        return UserDungeonProgress::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'floor' => 1,
                'unlocked_floor' => 1,
                'cleared_floor' => 0,
                'step' => 0,
                'skip_battle_encounters' => false,
                'force_dialogue_events' => false,
                'in_dungeon' => false,
            ],
        );
    }
}
