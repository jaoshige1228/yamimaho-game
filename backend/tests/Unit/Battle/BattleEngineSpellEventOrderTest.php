<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineSpellEventOrderTest extends TestCase
{
    public function test_spell_damage_emits_announce_before_damage(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            critRoll: static fn (): int => 100,
        );

        $state = [
            'status' => 'active',
            'current_actor' => 'pc3',
            'awaiting_input' => true,
            'turn_order' => ['pc3', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc3' => [
                    'id' => 'pc3',
                    'name' => 'テスト',
                    'side' => 'ally',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 30,
                    'max_mp' => 30,
                    'alive' => true,
                    'spells' => ['pc3_fire_burst'],
                    'str' => 10,
                    'mag' => 20,
                    'def' => 5,
                    'spd' => 10,
                    'crit_rate' => 0,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'buffs' => [],
                    'statuses' => [],
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => '敵',
                    'side' => 'enemy',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 0,
                    'max_mp' => 0,
                    'alive' => true,
                    'spells' => [],
                    'str' => 8,
                    'mag' => 8,
                    'def' => 4,
                    'spd' => 5,
                    'crit_rate' => 0,
                    'evasion_rate' => 0,
                    'damage_shield' => false,
                    'buffs' => [],
                    'statuses' => [],
                ],
            ],
            'log' => [],
        ];

        $result = $engine->applyPlayerAction($state, 'pc3', 'spell', 'pc3_fire_burst', 'enemy_1');
        $types = array_column($result['events'], 'type');

        $announceIndex = array_search('announce', $types, true);
        $damageIndex = array_search('damage', $types, true);

        $this->assertNotFalse($announceIndex);
        $this->assertNotFalse($damageIndex);
        $this->assertLessThan($damageIndex, $announceIndex);
    }
}
