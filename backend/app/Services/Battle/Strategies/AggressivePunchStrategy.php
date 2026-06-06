<?php

namespace App\Services\Battle\Strategies;

use App\Services\Battle\BattleActionStrategy;

/**
 * 常にこぶしで最も HP の低い敵を攻撃する（バランス試行用の単純 AI）。
 */
class AggressivePunchStrategy implements BattleActionStrategy
{
    public function choose(array $state): array
    {
        $enemies = array_filter(
            $state['units'],
            fn (array $u): bool => $u['side'] === 'enemy' && $u['alive'],
        );

        usort($enemies, fn (array $a, array $b): int => $a['hp'] <=> $b['hp']);
        $target = $enemies[0] ?? null;

        if ($target === null) {
            return ['action' => 'punch', 'target_id' => 'enemy_1'];
        }

        return [
            'action' => 'punch',
            'target_id' => $target['id'],
        ];
    }
}
