<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use ReflectionClass;
use Tests\TestCase;

class BattleEngineExpDropTest extends TestCase
{
    public function test_enemy_death_adds_exp_to_meta(): void
    {
        $factory = new BattleFactory;
        $engine = new BattleEngine($factory);

        $state = [
            'status' => 'active',
            'meta' => [],
            'units' => [
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'side' => 'enemy',
                    'name' => 'Bat',
                    'alive' => true,
                    'hp' => 10,
                    'max_hp' => 10,
                    'gold_reward' => 8,
                    'exp_reward' => 20,
                    'def' => 0,
                    'buffs' => [],
                ],
            ],
            'log' => [],
        ];

        $reflection = new ReflectionClass($engine);
        $method = $reflection->getMethod('applyDamage');
        $method->setAccessible(true);

        $method->invokeArgs($engine, [&$state, 'pc1', 'enemy_1', 10, null, false]);

        $this->assertSame(20, $state['meta']['exp_earned'] ?? 0);
    }
}
