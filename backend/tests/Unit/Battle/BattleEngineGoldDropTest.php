<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use ReflectionClass;
use Tests\TestCase;

class BattleEngineGoldDropTest extends TestCase
{
    public function test_enemy_death_adds_gold_to_meta(): void
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
                    'def' => 0,
                    'buffs' => [],
                ],
            ],
            'log' => [],
        ];

        $reflection = new ReflectionClass($engine);
        $method = $reflection->getMethod('applyDamage');
        $method->setAccessible(true);

        $events = $method->invokeArgs($engine, [&$state, 'pc1', 'enemy_1', 10, null, false]);

        $this->assertSame(8, $state['meta']['gold_earned'] ?? 0);
        $goldEvent = collect($events)->firstWhere('type', 'gold_gained');
        $this->assertNotNull($goldEvent);
        $this->assertSame(8, $goldEvent['amount']);
    }
}
