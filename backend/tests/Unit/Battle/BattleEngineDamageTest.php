<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineDamageTest extends TestCase
{
    public function test_punch_damage_uses_str_and_formula(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
        );

        $attacker = [
            'str' => 14,
            'mag' => 14,
            'def' => 9,
            'spd' => 12,
            'buffs' => [],
            'defending' => false,
        ];
        $defender = [
            'str' => 11,
            'mag' => 11,
            'def' => 6,
            'spd' => 8,
            'buffs' => [],
            'defending' => false,
        ];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);

        $damage = $method->invoke($engine, $attacker, $defender, 'str', 1.0);

        // (14 - 6/2) * 1.0 * 1.0 = 11
        $this->assertSame(11, $damage);
    }

    public function test_level_10_punch_uses_mastery_coefficient(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            critRoll: static fn (): int => 100,
        );

        $state = [
            'status' => 'active',
            'current_actor' => 'pc1',
            'awaiting_input' => true,
            'turn_order' => ['pc1', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'name' => 'PC1',
                    'side' => 'ally',
                    'level' => 10,
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 20,
                    'max_mp' => 20,
                    'str' => 14,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 10,
                    'crit_rate' => 0,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => '敵',
                    'side' => 'enemy',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 0,
                    'max_mp' => 0,
                    'str' => 8,
                    'mag' => 8,
                    'def' => 6,
                    'pdef' => 6,
                    'spd' => 5,
                    'crit_rate' => 0,
                    'evasion_rate' => 0,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                    'master_code' => 'bat',
                ],
            ],
            'log' => [],
        ];

        $result = $engine->applyPlayerAction($state, 'pc1', 'punch', null, 'enemy_1');
        $damage = collect($result['events'])->firstWhere('type', 'damage');

        $this->assertNotNull($damage);
        // (14 - 3) * 1.5 = 16.5 -> floor 16
        $this->assertSame(16, $damage['value']);
    }

    public function test_magic_damage_uses_mag_and_coefficient(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
        );

        $attacker = [
            'str' => 16,
            'mag' => 16,
            'def' => 8,
            'spd' => 13,
            'buffs' => [],
            'defending' => false,
        ];
        $defender = [
            'str' => 11,
            'mag' => 11,
            'def' => 6,
            'spd' => 8,
            'buffs' => [],
            'defending' => false,
        ];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);

        $damage = $method->invoke($engine, $attacker, $defender, 'mag', 1.07, 'magical');

        // (16 - 3) * 1.0 * 1.07 = 13.91 -> floor 13
        $this->assertSame(13, $damage);
    }

    public function test_enemy_uses_pdef_for_physical_and_mdef_for_magical(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
        );

        $attacker = [
            'side' => 'ally',
            'str' => 20,
            'mag' => 20,
            'def' => 5,
            'buffs' => [],
        ];
        $defender = [
            'side' => 'enemy',
            'str' => 10,
            'mag' => 10,
            'pdef' => 40,
            'mdef' => 4,
            'buffs' => [],
        ];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);

        $physical = $method->invoke($engine, $attacker, $defender, 'str', 1.0, 'physical');
        $magical = $method->invoke($engine, $attacker, $defender, 'mag', 1.0, 'magical');

        // (20 - 40/2) * 1.0 = 0 -> min 1
        $this->assertSame(1, $physical);
        // (20 - 4/2) * 1.0 = 18
        $this->assertSame(18, $magical);
    }

    public function test_damage_is_at_least_one(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 0.9,
        );

        $attacker = ['str' => 1, 'mag' => 1, 'def' => 20, 'spd' => 5, 'buffs' => [], 'defending' => false];
        $defender = ['str' => 20, 'mag' => 20, 'def' => 20, 'spd' => 5, 'buffs' => [], 'defending' => false];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);

        $damage = $method->invoke($engine, $attacker, $defender, 'str', 1.0);

        $this->assertSame(1, $damage);
    }
}
