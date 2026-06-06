<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\EnemySkillPicker;
use Tests\TestCase;

class BattleEngineEnemySkillTest extends TestCase
{
    public function test_flame_breath_hits_player_allies_not_self(): void
    {
        $picker = new class extends EnemySkillPicker
        {
            public function pick(string $enemyCode): array
            {
                return [
                    'skill_code' => 'flame_breath',
                    'label' => '火炎放射',
                    'action_type' => 'physical_all',
                    'target_type' => 'ally_all',
                    'coefficient' => 0.8,
                ];
            }
        };

        $engine = new BattleEngine(
            enemySkillPicker: $picker,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );

        $state = [
            'status' => 'active',
            'current_actor' => 'enemy_1',
            'awaiting_input' => false,
            'turn_order' => ['enemy_1', 'pc1', 'pc2'],
            'turn_index' => 0,
            'units' => [
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => 'イフリーヌ',
                    'side' => 'enemy',
                    'master_code' => 'inu_moe',
                    'hp' => 400,
                    'max_hp' => 400,
                    'str' => 60,
                    'mag' => 11,
                    'def' => 30,
                    'spd' => 20,
                    'crit_rate' => 1,
                    'evasion_rate' => 1,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
                'pc1' => [
                    'id' => 'pc1',
                    'name' => 'PC1',
                    'side' => 'ally',
                    'hp' => 50,
                    'max_hp' => 50,
                    'str' => 10,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 10,
                    'crit_rate' => 5,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
                'pc2' => [
                    'id' => 'pc2',
                    'name' => 'PC2',
                    'side' => 'ally',
                    'hp' => 50,
                    'max_hp' => 50,
                    'str' => 10,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 8,
                    'crit_rate' => 5,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
            ],
            'log' => [],
        ];

        $result = $engine->runEnemyTurnsUntilPlayer($state);
        $damages = array_values(array_filter(
            $result['events'],
            fn (array $e): bool => ($e['type'] ?? '') === 'damage',
        ));

        $this->assertCount(2, $damages);
        $this->assertSame(400, $result['state']['units']['enemy_1']['hp']);
        $this->assertLessThan(50, $result['state']['units']['pc1']['hp']);
        $this->assertLessThan(50, $result['state']['units']['pc2']['hp']);
    }
}
