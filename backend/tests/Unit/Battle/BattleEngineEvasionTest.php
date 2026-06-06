<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineEvasionTest extends TestCase
{
    public function test_high_evasion_rate_causes_miss(): void
    {
        $rolls = [1];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 1;
            },
        );

        $state = $this->minimalState();
        $state['units']['enemy_1']['evasion_rate'] = 50;

        $method = new \ReflectionMethod(BattleEngine::class, 'resolvePhysicalHit');
        $method->setAccessible(true);

        $events = $method->invokeArgs($engine, [&$state, 'pc1', 'enemy_1', 1.0, null, true]);

        $this->assertSame('miss', $events[0]['type']);
    }

    public function test_low_roll_hits_despite_evasion(): void
    {
        $rolls = [100];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
        );

        $state = $this->minimalState();
        $state['units']['enemy_1']['evasion_rate'] = 5;

        $method = new \ReflectionMethod(BattleEngine::class, 'resolvePhysicalHit');
        $method->setAccessible(true);

        $events = $method->invokeArgs($engine, [&$state, 'pc1', 'enemy_1', 1.0, null, true]);

        $this->assertSame('damage', $events[0]['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalState(): array
    {
        return [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'name' => 'PC1',
                    'side' => 'ally',
                    'hp' => 50,
                    'max_hp' => 50,
                    'str' => 20,
                    'mag' => 10,
                    'def' => 5,
                    'crit_rate' => 0,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => '敵',
                    'side' => 'enemy',
                    'hp' => 50,
                    'max_hp' => 50,
                    'str' => 10,
                    'mag' => 10,
                    'def' => 10,
                    'crit_rate' => 0,
                    'evasion_rate' => 1,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                ],
            ],
            'log' => [],
        ];
    }
}
