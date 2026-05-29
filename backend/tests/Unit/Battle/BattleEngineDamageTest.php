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

        $damage = $method->invoke($engine, $attacker, $defender, 'mag', 1.07);

        // (16 - 3) * 1.0 * 1.07 = 13.91 -> floor 13
        $this->assertSame(13, $damage);
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
