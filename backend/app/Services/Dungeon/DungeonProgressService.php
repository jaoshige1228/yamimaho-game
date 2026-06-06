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

        return [
            'floor' => (int) $record->floor,
            'step' => (int) $record->step,
            'unlocked_floor' => (int) $record->unlocked_floor,
            'max_floor' => DungeonFloorConfig::maxFloor(),
            'playable_floor' => DungeonFloorConfig::playableFloor(),
            'boss_step' => DungeonFloorConfig::bossStep((int) $record->floor),
            'gold' => $this->gold->getGold($user),
            'in_dungeon' => (bool) $record->in_dungeon,
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
        $record->in_dungeon = false;
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
        $record->save();
    }

    public function reset(User $user): void
    {
        $record = $this->findOrCreate($user);
        $record->step = 0;
        $record->floor = 1;
        $record->in_dungeon = false;
        $record->save();
    }

    /** ホーム画面リセット用: 層・深さ・解放層・探索セッションを初期化 */
    public function resetAll(User $user): void
    {
        $record = $this->findOrCreate($user);
        $record->floor = 1;
        $record->step = 0;
        $record->unlocked_floor = 1;
        $record->skip_battle_encounters = false;
        $record->in_dungeon = false;
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

    /**
     * @return array<string, mixed>
     */
    public function onBossVictory(User $user, int $floor): array
    {
        $record = $this->findOrCreate($user);
        $maxFloor = DungeonFloorConfig::maxFloor();

        if ($floor < $maxFloor) {
            $record->unlocked_floor = max((int) $record->unlocked_floor, $floor + 1);
        }

        $record->step = 0;
        $record->save();

        $nextFloor = $floor + 1;
        $nextPlayable = $nextFloor <= DungeonFloorConfig::playableFloor()
            && $nextFloor <= (int) $record->unlocked_floor;

        return [
            'type' => 'dungeon_floor_cleared',
            'floor' => $floor,
            'unlocked_floor' => (int) $record->unlocked_floor,
            'next_floor' => $nextFloor <= $maxFloor ? $nextFloor : null,
            'next_floor_playable' => $nextPlayable,
            'text' => $nextPlayable
                ? "{$floor}層を踏破した！"
                : "{$floor}層を踏破した！　だが、さらに深い層はまだ準備が整っていない……",
        ];
    }

    private function findOrCreate(User $user): UserDungeonProgress
    {
        return UserDungeonProgress::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'floor' => 1,
                'unlocked_floor' => 1,
                'step' => 0,
                'skip_battle_encounters' => false,
                'in_dungeon' => false,
            ],
        );
    }
}
