<?php

namespace App\Services\Dungeon;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\MasterDataProvider;
use App\Services\Player\GameNavigationService;
use App\Services\Player\ItemInventoryService;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Facades\DB;

class DungeonHealService
{
    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly ItemInventoryService $items = new ItemInventoryService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
        private readonly MasterDataProvider $masters = new MasterDataProvider,
        private readonly GameNavigationService $navigation = new GameNavigationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function healWithItem(User $user, string $itemCode, string $targetSlotId): array
    {
        $this->assertCanHeal($user);

        $party = $this->characters->partyInSlotOrder($user);
        $target = $party->get($targetSlotId);
        if ($target === null) {
            throw new \InvalidArgumentException('対象が見つかりません。');
        }

        $item = $this->items->findItem($itemCode);
        $maxStats = $this->growth->statsForLevel($target->characterMaster, $target->level);

        DB::transaction(function () use ($user, $target, $item, $maxStats): void {
            match ($item->effect) {
                'heal_hp' => $this->applyHpHeal($target, $maxStats['hp'], (int) $item->power),
                'heal_mp' => $this->applyMpHeal($target, $maxStats['mp'], (int) $item->power),
                'revive' => $this->applyRevive($target, $maxStats['hp'], (int) $item->power),
                default => throw new \InvalidArgumentException('このアイテムは使えません。'),
            };

            $this->items->consume($user, $item->code);
        });

        return $this->buildResponse($user, "{$item->name}を使った。");
    }

    /**
     * @return array<string, mixed>
     */
    public function healWithSpell(User $user, string $casterSlotId, string $spellId, string $targetSlotId): array
    {
        $this->assertCanHeal($user);

        $party = $this->characters->partyInSlotOrder($user);
        $caster = $party->get($casterSlotId);
        $target = $party->get($targetSlotId);
        if ($caster === null || $target === null) {
            throw new \InvalidArgumentException('対象が見つかりません。');
        }

        $masterCode = $caster->characterMaster->code;
        $spellIds = $this->masters->spellIdsForCharacter($masterCode);
        if (! in_array($spellId, $spellIds, true)) {
            throw new \InvalidArgumentException('その魔法は使えません。');
        }

        $spell = $this->masters->findSpell($spellId);
        $effect = $spell['effect'] ?? '';
        if (! in_array($effect, ['heal_mag', 'heal', 'revive_chance'], true)) {
            throw new \InvalidArgumentException('回復に使える魔法ではありません。');
        }

        $maxTarget = $this->growth->statsForLevel($target->characterMaster, $target->level);
        $mpCost = (int) ($spell['mp_cost'] ?? 0);
        if ($caster->mp < $mpCost) {
            throw new \InvalidArgumentException('MPが足りません。');
        }

        DB::transaction(function () use ($caster, $target, $spell, $effect, $maxTarget, $mpCost): void {
            match ($effect) {
                'heal_mag' => $this->applyHpHeal(
                    $target,
                    $maxTarget['hp'],
                    (int) floor((float) $caster->mag * 3),
                ),
                'heal' => $this->applyHpHeal($target, $maxTarget['hp'], (int) ($spell['power'] ?? 0)),
                'revive_chance' => $this->applyRevive($target, $maxTarget['hp'], (int) ($spell['power'] ?? 50)),
                default => throw new \InvalidArgumentException('この魔法は使えません。'),
            };

            $caster->mp -= $mpCost;
            $caster->save();
        });

        return $this->buildResponse($user, "{$spell['label']}を唱えた。");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSpells(User $user): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $spells = [];

        foreach ($party as $slotId => $character) {
            $masterCode = $character->characterMaster->code;
            foreach ($this->masters->spellIdsForCharacter($masterCode) as $spellId) {
                $spell = $this->masters->findSpell($spellId);
                $effect = $spell['effect'] ?? '';
                if (! in_array($effect, ['heal_mag', 'heal', 'revive_chance'], true)) {
                    continue;
                }

                $spells[] = [
                    'caster_slot_id' => $slotId,
                    'caster_name' => $character->characterMaster->name,
                    'id' => $spellId,
                    'label' => $spell['label'],
                    'description' => $spell['description'] ?? '',
                    'mp_cost' => (int) ($spell['mp_cost'] ?? 0),
                    'effect' => $effect,
                    'affordable' => $character->mp >= (int) ($spell['mp_cost'] ?? 0),
                ];
            }
        }

        return $spells;
    }

    private function assertCanHeal(User $user): void
    {
        if (! $this->progress->isInDungeon($user)) {
            throw new \InvalidArgumentException('ダンジョン内でのみ回復できます。');
        }

        if (DungeonExplorationSession::query()->where('user_id', $user->id)->exists()) {
            throw new \InvalidArgumentException('探索中は回復できません。');
        }

        if ($this->navigation->payload($user)['active_battle_id'] !== null) {
            throw new \InvalidArgumentException('戦闘中は回復できません。');
        }
    }

    private function applyHpHeal(UserCharacter $target, int $maxHp, int $amount): void
    {
        if ($target->hp <= 0) {
            throw new \InvalidArgumentException('戦闘不能の対象には回復できません。');
        }
        if ($target->hp >= $maxHp) {
            throw new \InvalidArgumentException('HPが満タンの対象には回復できません。');
        }

        $healed = min($amount, $maxHp - $target->hp);
        $target->hp += $healed;
        $target->save();
    }

    private function applyMpHeal(UserCharacter $target, int $maxMp, int $amount): void
    {
        if ($target->hp <= 0) {
            throw new \InvalidArgumentException('戦闘不能の対象には回復できません。');
        }
        if ($target->mp >= $maxMp) {
            throw new \InvalidArgumentException('MPが満タンの対象には回復できません。');
        }

        $restored = min($amount, $maxMp - $target->mp);
        $target->mp += $restored;
        $target->save();
    }

    private function applyRevive(UserCharacter $target, int $maxHp, int $percent): void
    {
        if ($target->hp > 0) {
            throw new \InvalidArgumentException('生存中の対象には蘇生できません。');
        }

        $target->hp = max(1, (int) floor($maxHp * $percent / 100));
        $target->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponse(User $user, string $message): array
    {
        $party = $this->characters->partyInSlotOrder($user);

        return [
            'message' => $message,
            'party' => $party->map(
                fn ($character, $slotId) => $this->characters->toPartyUnitPayload($character, $slotId),
            )->values()->all(),
            'items' => $this->items->inventoryPayload($user),
        ];
    }
}
