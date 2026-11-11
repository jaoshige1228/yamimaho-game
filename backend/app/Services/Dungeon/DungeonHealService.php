<?php

namespace App\Services\Dungeon;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Battle\SpellHealing;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\MasterDataProvider;
use App\Services\Player\EquipmentService;
use App\Services\Player\GameNavigationService;
use App\Services\Player\ItemInventoryService;
use App\Services\Player\SpellLearningService;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Facades\DB;

class DungeonHealService
{
    /** 探索中に使える魔法の effect */
    private const FIELD_SPELL_EFFECTS = ['heal_mag', 'heal', 'heal_full', 'revive_chance', 'revive_full'];

    /** 味方全体にかけるときに「回復」として扱う effect */
    private const HEAL_EFFECTS = ['heal_mag', 'heal', 'heal_full'];

    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly ItemInventoryService $items = new ItemInventoryService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
        private readonly MasterDataProvider $masters = new MasterDataProvider,
        private readonly GameNavigationService $navigation = new GameNavigationService,
        private readonly EquipmentService $equipment = new EquipmentService,
        private readonly SpellLearningService $spellLearning = new SpellLearningService,
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
        $maxStats = $this->growth->statsForCharacter($target);

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
    public function healWithSpell(User $user, string $casterSlotId, string $spellId, ?string $targetSlotId = null): array
    {
        $this->assertCanHeal($user);

        $party = $this->characters->partyInSlotOrder($user);
        $caster = $party->get($casterSlotId);
        try {
            $spell = $this->masters->findSpell($spellId);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('その魔法は使えません。');
        }
        $effect = $spell['effect'] ?? '';
        // 味方全体の回復（精霊の虹など）は対象を選ばない
        $allTarget = ($spell['target_type'] ?? '') === 'ally_all' && in_array($effect, self::HEAL_EFFECTS, true);
        $target = $allTarget || $targetSlotId === null || $targetSlotId === '' ? null : $party->get($targetSlotId);
        if ($caster === null || (! $allTarget && $target === null)) {
            throw new \InvalidArgumentException('対象が見つかりません。');
        }

        if ($caster->hp <= 0) {
            throw new \InvalidArgumentException('戦闘不能のキャラクターは魔法を使えません。');
        }

        $spellIds = $this->spellLearning->spellIdsFor($caster);
        if (! in_array($spellId, $spellIds, true)) {
            throw new \InvalidArgumentException('その魔法は使えません。');
        }

        if (! in_array($effect, self::FIELD_SPELL_EFFECTS, true)) {
            throw new \InvalidArgumentException('回復に使える魔法ではありません。');
        }

        $mpCost = (int) ($spell['mp_cost'] ?? 0);
        if ($caster->mp < $mpCost) {
            throw new \InvalidArgumentException('MPが足りません。');
        }

        // 回復する相手。全体回復は、傷ついている（生きていてHPが満タンでない）味方全員
        $targets = $allTarget
            ? $party->filter(fn (UserCharacter $c) => $c->hp > 0 && $c->hp < $this->growth->statsForCharacter($c)['hp'])->values()->all()
            : [$target];
        if ($targets === []) {
            throw new \InvalidArgumentException('回復できる対象がいません。');
        }

        DB::transaction(function () use ($caster, $targets, $spell, $effect, $mpCost): void {
            foreach ($targets as $member) {
                $maxHp = $this->growth->statsForCharacter($member)['hp'];
                match ($effect) {
                    'heal_mag' => $this->applyHpHeal(
                        $member,
                        $maxHp,
                        // 戦闘中と同じく、武器の魔力ボーナスを含む実効魔力で計算する
                        SpellHealing::healMagAmount((float) $this->equipment->effectiveCombatStats($caster)['mag'], $spell, $caster->characterMaster->code, (int) $caster->level),
                    ),
                    'heal' => $this->applyHpHeal($member, $maxHp, (int) ($spell['power'] ?? 0)),
                    'heal_full' => $this->applyHpHeal($member, $maxHp, $maxHp),
                    // 蘇生後の HP は戦闘中と同じ割合（power は戦闘中の成功率）
                    'revive_chance' => $this->applyRevive($member, $maxHp, (int) config('battle.revive_hp_percent', 50)),
                    'revive_full' => $this->applyRevive($member, $maxHp, 100),
                    default => throw new \InvalidArgumentException('この魔法は使えません。'),
                };
            }

            $caster->mp -= $mpCost;
            $caster->save();
        });

        // 魔法ではない特技（迅速調合など）は「唱えた」と言わない
        $verb = ($spell['cast_style'] ?? '') === 'skill' ? '使った' : '唱えた';

        return $this->buildResponse($user, "{$spell['label']}を{$verb}。");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSpells(User $user): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $spells = [];

        foreach ($party as $slotId => $character) {
            foreach ($this->spellLearning->spellIdsFor($character) as $spellId) {
                $spell = $this->masters->findSpell($spellId);
                $effect = $spell['effect'] ?? '';
                if (! in_array($effect, self::FIELD_SPELL_EFFECTS, true)) {
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
                    'target_type' => $spell['target_type'] ?? 'ally_single',
                    'affordable' => $character->hp > 0
                        && $character->mp >= (int) ($spell['mp_cost'] ?? 0),
                    // 画面に出す「回復量の見込み」。戦闘中と同じく、武器の魔力ボーナスを含む実効魔力で計算する。全回復・蘇生は null
                    'amount' => match ($effect) {
                        'heal_mag' => SpellHealing::healMagAmount((float) $this->equipment->effectiveCombatStats($character)['mag'], $spell, $character->characterMaster->code, (int) $character->level),
                        'heal' => (int) ($spell['power'] ?? 0),
                        default => null,
                    },
                    // 蘇生したときの HP（最大HPに対する割合 %）。蘇生の魔法だけ
                    'revive_percent' => match ($effect) {
                        'revive_chance' => (int) config('battle.revive_hp_percent', 50),
                        'revive_full' => 100,
                        default => null,
                    },
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
