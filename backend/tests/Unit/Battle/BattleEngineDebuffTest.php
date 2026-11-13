<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattleEngineDebuffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_def_down_increases_physical_damage(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
        );

        $attacker = ['str' => 20, 'mag' => 10, 'def' => 5, 'buffs' => []];
        $defender = ['str' => 10, 'mag' => 10, 'def' => 20, 'buffs' => []];
        $debuffed = ['str' => 10, 'mag' => 10, 'def' => 20, 'buffs' => [], 'stages' => [['stat' => 'def', 'amount' => -1, 'turns' => 3, 'source' => 'test']]];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);

        $normal = $method->invoke($engine, $attacker, $defender, 'str', 1.0);
        $lowered = $method->invoke($engine, $attacker, $debuffed, 'str', 1.0);

        $this->assertGreaterThan($normal, $lowered);
        // 防御1段階ダウン（×0.8）: 防御 20 → 16 なので 20 − 16 / 2 = 12
        $this->assertSame(0.8, config('battle.stage.multipliers.def')[-1]);
        $this->assertSame(10, $normal);
        $this->assertSame(12, $lowered);
    }

    public function test_def_up_reduces_physical_damage(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
        );

        $attacker = ['str' => 20, 'mag' => 10, 'def' => 5, 'buffs' => []];
        $defender = ['str' => 10, 'mag' => 10, 'def' => 20, 'buffs' => []];
        $buffed = ['str' => 10, 'mag' => 10, 'def' => 20, 'buffs' => [], 'stages' => [['stat' => 'def', 'amount' => 1, 'turns' => 3, 'source' => 'test']]];

        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);

        $normal = $method->invoke($engine, $attacker, $defender, 'str', 1.0);
        $raised = $method->invoke($engine, $attacker, $buffed, 'str', 1.0);

        $this->assertLessThan($normal, $raised);
        // 防御1段階アップ（×1.2）: 防御 20 → 24 なので 20 − 24 / 2 = 8
        $this->assertSame(1.2, config('battle.stage.multipliers.def')[1]);
        $this->assertSame(10, $normal);
        $this->assertSame(8, $raised);
    }

    public function test_vine_bind_applies_def_down_and_increases_follow_up_damage(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );

        $state = [
            'status' => 'active',
            'current_actor' => 'pc2',
            'awaiting_input' => true,
            'turn_order' => ['pc2', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc2' => [
                    'id' => 'pc2',
                    'name' => 'ガルデ',
                    'side' => 'ally',
                    'hp' => 100,
                    'max_hp' => 100,
                    'mp' => 45,
                    'max_mp' => 45,
                    'str' => 20,
                    'mag' => 15,
                    'def' => 8,
                    'spd' => 18,
                    'crit_rate' => 5,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => ['pc2_vine_bind', 'pc2_vine_whip'],
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => 'ヘビビ',
                    'side' => 'enemy',
                    'hp' => 200,
                    'max_hp' => 200,
                    'mp' => 0,
                    'max_mp' => 0,
                    'str' => 40,
                    'mag' => 11,
                    'def' => 20,
                    'spd' => 8,
                    'crit_rate' => 1,
                    'evasion_rate' => 1,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                    'master_code' => 'snake',
                ],
            ],
            'log' => [],
        ];

        $bind = $engine->applyPlayerAction($state, 'pc2', 'spell', 'pc2_vine_bind', 'enemy_1');
        $state = $bind['state'];

        $this->assertContains('buff_applied', array_column($bind['events'], 'type'));
        $this->assertSame([['stat' => 'def', 'amount' => -1, 'turns' => 3, 'source' => 'pc2_vine_bind']], $state['units']['enemy_1']['stages'] ?? null);

        $state['current_actor'] = 'pc2';
        $state['awaiting_input'] = true;

        $punch = $engine->applyPlayerAction($state, 'pc2', 'punch', null, 'enemy_1');
        $damage = collect($punch['events'])->firstWhere('type', 'damage');

        $this->assertNotNull($damage);
        $this->assertSame(12, $damage['value']);
    }
}
