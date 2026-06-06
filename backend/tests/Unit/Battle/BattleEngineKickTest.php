<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineKickTest extends TestCase
{
    public function test_kick_misses_when_hit_roll_fails(): void
    {
        $rolls = [100];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
        );

        $state = $this->battleState();

        $result = $engine->applyPlayerAction($state, 'pc1', 'kick', null, 'enemy_1');
        $types = array_column($result['events'], 'type');

        $this->assertContains('miss', $types);
        $this->assertNotContains('damage', $types);
    }

    public function test_kick_hits_with_double_str_damage(): void
    {
        $rolls = [1, 100];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
            critRoll: static fn (): int => 100,
        );

        $state = $this->battleState();
        $state['units']['pc1']['str'] = 14;
        $state['units']['enemy_1']['def'] = 6;

        $result = $engine->applyPlayerAction($state, 'pc1', 'kick', null, 'enemy_1');
        $damage = collect($result['events'])->firstWhere('type', 'damage');

        $this->assertNotNull($damage);
        // (14 - 3) * 2.0 = 22
        $this->assertSame(22, $damage['value']);
    }

    /**
     * @return array<string, mixed>
     */
    private function battleState(): array
    {
        return [
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
    }
}
