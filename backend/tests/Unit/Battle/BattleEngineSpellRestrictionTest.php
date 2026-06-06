<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use InvalidArgumentException;
use Tests\TestCase;

class BattleEngineSpellRestrictionTest extends TestCase
{
    private function baseState(array $targetOverrides = []): array
    {
        return [
            'status' => 'active',
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'Healer',
                    'alive' => true,
                    'hp' => 50,
                    'max_hp' => 100,
                    'mp' => 50,
                    'max_mp' => 50,
                    'mag' => 20,
                    'spells' => ['pc1_heal_water', 'pc2_world_tree'],
                    'buffs' => [],
                ],
                'pc2' => array_merge([
                    'id' => 'pc2',
                    'side' => 'ally',
                    'name' => 'Target',
                    'alive' => true,
                    'hp' => 100,
                    'max_hp' => 100,
                    'mp' => 20,
                    'max_mp' => 20,
                    'mag' => 10,
                    'spells' => [],
                    'buffs' => [],
                ], $targetOverrides),
            ],
            'log' => [],
        ];
    }

    public function test_heal_on_full_hp_is_rejected(): void
    {
        $engine = new BattleEngine(new BattleFactory);

        $this->expectException(InvalidArgumentException::class);
        $engine->applyPlayerAction(
            $this->baseState(),
            'pc1',
            'spell',
            'pc1_heal_water',
            'pc2',
        );
    }

    public function test_revive_on_alive_target_is_rejected(): void
    {
        $engine = new BattleEngine(new BattleFactory);

        $this->expectException(InvalidArgumentException::class);
        $engine->applyPlayerAction(
            $this->baseState(),
            'pc1',
            'spell',
            'pc2_world_tree',
            'pc2',
        );
    }

    public function test_damage_spell_is_usable_when_enemy_alive(): void
    {
        $engine = new BattleEngine(new BattleFactory);
        $method = new \ReflectionMethod(BattleEngine::class, 'spellHasValidTarget');
        $method->setAccessible(true);

        $spell = [
            'effect' => 'damage',
            'target_type' => 'enemy_single',
        ];
        $units = [
            'pc3' => ['side' => 'ally', 'alive' => true],
            'enemy_1' => ['side' => 'enemy', 'alive' => true],
        ];

        $this->assertTrue($method->invoke($engine, $spell, $units));
    }
}
