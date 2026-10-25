<?php

namespace App\Services\Battle\Strategies;

use App\Services\Battle\BattleActionStrategy;
use App\Services\Battle\BattleFactory;
use App\Services\MasterData\MasterDataProvider;

/**
 * 主力ダメージ魔法を最も HP の低い敵に使う（バランス試行用 AI）。
 */
class PrimarySpellStrategy implements BattleActionStrategy
{
    public function __construct(
        private readonly MasterDataProvider $masters = new MasterDataProvider,
        private readonly BattleFactory $factory = new BattleFactory,
    ) {}

    public function choose(array $state): array
    {
        $actorId = $state['current_actor'] ?? null;
        if ($actorId === null || ! str_starts_with((string) $actorId, 'pc')) {
            return ['action' => 'punch', 'target_id' => 'enemy_1'];
        }

        $actor = $state['units'][$actorId] ?? null;
        if ($actor === null) {
            return ['action' => 'punch', 'target_id' => 'enemy_1'];
        }

        $target = $this->pickLowestHpEnemy($state);
        $spellId = $this->pickPrimaryDamageSpell($actor);

        if ($spellId !== null && $target !== null) {
            $spell = $this->factory->findSpell($spellId);
            $mpCost = (int) ($spell['mp_cost'] ?? 0);
            if (($actor['mp'] ?? 0) >= $mpCost) {
                return [
                    'action' => 'spell',
                    'spell_id' => $spellId,
                    'target_id' => $target['id'],
                ];
            }
        }

        return [
            'action' => 'punch',
            'target_id' => $target['id'] ?? 'enemy_1',
        ];
    }

    /**
     * @param  array<string, mixed>  $actor
     */
    private function pickPrimaryDamageSpell(array $actor): ?string
    {
        foreach ($actor['spells'] ?? [] as $spellId) {
            try {
                $spell = $this->masters->findSpell($spellId);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (($spell['effect'] ?? '') === 'damage') {
                return $spellId;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pickLowestHpEnemy(array $state): ?array
    {
        $enemies = array_filter(
            $state['units'],
            fn (array $u): bool => $u['side'] === 'enemy' && $u['alive'],
        );

        if ($enemies === []) {
            return null;
        }

        usort($enemies, fn (array $a, array $b): int => $a['hp'] <=> $b['hp']);

        return $enemies[0];
    }
}
