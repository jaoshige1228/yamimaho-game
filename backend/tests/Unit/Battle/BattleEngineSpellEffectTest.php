<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineSpellEffectTest extends TestCase
{
    public function test_heal_mag_restores_mag_times_three(): void
    {
        $engine = new BattleEngine(factory: new BattleFactory);
        $state = $this->spellState('pc1_heal_water', 'pc1');

        $result = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc1');
        $heal = collect($result['events'])->firstWhere('type', 'heal');

        $this->assertNotNull($heal);
        $this->assertSame(20, $heal['value']);
    }

    public function test_shield_next_blocks_damage_once(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );

        $state = $this->spellState('pc4_shield_magic', 'pc4');
        $state = $engine->applyPlayerAction($state, 'pc4', 'spell', 'pc4_shield_magic', 'pc4')['state'];

        $this->assertTrue($state['units']['pc4']['damage_shield']);

        $method = new \ReflectionMethod(BattleEngine::class, 'applyDamage');
        $method->setAccessible(true);
        $events = $method->invokeArgs($engine, [&$state, 'enemy_1', 'pc4', 30, null, false]);

        $this->assertSame('shield_break', $events[0]['type']);
        $this->assertSame(50, $state['units']['pc4']['hp']);
    }

    public function test_revive_chance_can_revive_dead_ally(): void
    {
        $rolls = [1];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
        );

        $state = $this->spellState('pc2_world_tree', 'pc1');
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;

        $result = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc2_world_tree', 'pc2');

        $this->assertContains('revive', array_column($result['events'], 'type'));
        $this->assertTrue($result['state']['units']['pc2']['alive']);
        $this->assertSame(25, $result['state']['units']['pc2']['hp']);
    }

    /**
     * @return array<string, mixed>
     */
    private function spellState(string $spellId, string $actorId): array
    {
        $spellsFor = static fn (string $id): array => $id === $actorId ? [$spellId] : [];

        return [
            'status' => 'active',
            'current_actor' => $actorId,
            'awaiting_input' => true,
            'turn_order' => ['pc1', 'pc2', 'pc4', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'name' => '水',
                    'side' => 'ally',
                    'hp' => 30,
                    'max_hp' => 50,
                    'mp' => 30,
                    'max_mp' => 30,
                    'str' => 10,
                    'mag' => 15,
                    'def' => 5,
                    'spd' => 10,
                    'crit_rate' => 5,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => $spellsFor('pc1'),
                ],
                'pc2' => [
                    'id' => 'pc2',
                    'name' => '木',
                    'side' => 'ally',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 30,
                    'max_mp' => 30,
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
                    'spells' => $spellsFor('pc2'),
                ],
                'pc4' => [
                    'id' => 'pc4',
                    'name' => '土',
                    'side' => 'ally',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 30,
                    'max_mp' => 30,
                    'str' => 10,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 6,
                    'crit_rate' => 5,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => $spellsFor('pc4'),
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => '敵',
                    'side' => 'enemy',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 0,
                    'max_mp' => 0,
                    'str' => 20,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 5,
                    'crit_rate' => 1,
                    'evasion_rate' => 1,
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
