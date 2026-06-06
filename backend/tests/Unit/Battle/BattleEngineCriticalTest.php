<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineCriticalTest extends TestCase
{
    public function test_critical_ignores_defender_def(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            critRoll: static fn (): int => 1,
        );

        $attacker = [
            'str' => 20,
            'mag' => 10,
            'def' => 5,
            'crit_rate' => 100,
            'buffs' => [],
        ];
        $defender = [
            'str' => 10,
            'mag' => 10,
            'def' => 40,
            'buffs' => [],
        ];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamageWithCritical');
        $method->setAccessible(true);

        $result = $method->invoke($engine, $attacker, $defender, 'str', 1.0);

        $this->assertTrue($result['critical']);
        $this->assertSame(20, $result['damage']);
    }
}
