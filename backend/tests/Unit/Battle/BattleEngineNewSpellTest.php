<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

/**
 * イフリーヌ撃破で覚える新魔法（神聖の水・水の幻影群・爆樹・宇宙樹の息吹・ブラックホール・ラグオンビート・岩落とし・土の城壁）の効果。
 */
class BattleEngineNewSpellTest extends TestCase
{
    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100, // 最悪の出目でも、確率で効果が決まる魔法が成功することを確かめる
            critRoll: static fn (): int => 100,
        );
    }

    public function test_holy_water_heals_mag_times_three_and_a_half(): void
    {
        $state = $this->state('pc1', 'pc1_holy_water');
        $state['units']['pc1']['max_hp'] = 200;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_holy_water', 'pc1');
        $heal = collect($result['events'])->firstWhere('type', 'heal');

        // 魔力 15 × 3.5 = 52（癒しの水は ×2 のまま）
        $this->assertSame(52, $heal['value']);
        $this->assertSame(82, $result['state']['units']['pc1']['hp']);
        $this->assertSame(18, $result['state']['units']['pc1']['mp']);
    }

    public function test_water_veil_all_sets_evasion_buff_on_every_living_ally(): void
    {
        $state = $this->state('pc1', 'pc1_water_veil_all');
        $state['units']['pc3']['hp'] = 0;
        $state['units']['pc3']['alive'] = false;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_veil_all');
        $units = $result['state']['units'];

        $this->assertArrayHasKey('evasion_set_small', $units['pc1']['buffs']);
        $this->assertArrayHasKey('evasion_set_small', $units['pc2']['buffs']);
        $this->assertArrayHasKey('evasion_set_small', $units['pc4']['buffs']);
        $this->assertSame([], $units['pc3']['buffs'], '戦闘不能の味方にはかからない');
        $this->assertSame([], $units['enemy_1']['buffs']);
        $this->assertSame(3, $units['pc2']['buffs']['evasion_set_small']);

        $buffEvents = array_filter($result['events'], fn ($e) => $e['type'] === 'buff_applied');
        $this->assertCount(3, $buffEvents);
    }

    public function test_evasion_buffs_use_the_strongest_fixed_rate(): void
    {
        $engine = $this->engine();
        $method = new \ReflectionMethod(BattleEngine::class, 'effectiveEvasionRate');
        $method->setAccessible(true);

        $small = ['evasion_rate' => 5, 'buffs' => ['evasion_set_small' => 3]];
        $both = ['evasion_rate' => 5, 'buffs' => ['evasion_set_small' => 3, 'evasion_set' => 3]];
        $reversed = ['evasion_rate' => 5, 'buffs' => ['evasion_set' => 3, 'evasion_set_small' => 3]];

        $this->assertSame(30, $method->invoke($engine, $small));
        $this->assertSame(60, $method->invoke($engine, $both));
        $this->assertSame(60, $method->invoke($engine, $reversed));
    }

    public function test_burst_tree_lowers_defense_of_all_enemies(): void
    {
        $state = $this->state('pc2', 'pc2_burst_tree');

        $result = $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_burst_tree');
        $units = $result['state']['units'];

        $this->assertSame(3, $units['enemy_1']['buffs']['def_down_small']);
        $this->assertSame(3, $units['enemy_2']['buffs']['def_down_small']);
        $this->assertArrayNotHasKey('def_down_small', $units['pc1']['buffs']);
        $this->assertSame(0.8, config('battle.buffs.def_down_small.multiplier'));
    }

    public function test_world_tree_breath_always_revives_with_half_hp(): void
    {
        $state = $this->state('pc2', 'pc2_world_tree_breath');
        $state['units']['pc4']['hp'] = 0;
        $state['units']['pc4']['alive'] = false;

        $result = $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_world_tree_breath', 'pc4');

        $this->assertContains('revive', array_column($result['events'], 'type'));
        $this->assertNotContains('revive_failed', array_column($result['events'], 'type'));
        $this->assertTrue($result['state']['units']['pc4']['alive']);
        $this->assertSame(25, $result['state']['units']['pc4']['hp']);
    }

    public function test_black_hole_hits_all_enemies_and_costs_half_of_max_hp(): void
    {
        $state = $this->state('pc3', 'pc3_black_hole');
        $state['units']['pc3']['hp'] = 100;
        $state['units']['pc3']['max_hp'] = 100;
        $state['units']['pc3']['mp'] = 40;
        foreach (['enemy_1', 'enemy_2'] as $id) {
            $state['units'][$id]['hp'] = 500;
            $state['units'][$id]['max_hp'] = 500;
        }

        $result = $this->engine()->applyPlayerAction($state, 'pc3', 'spell', 'pc3_black_hole');
        $units = $result['state']['units'];

        // (魔力20 - 防御5/2) × 3.0 = 52
        $damages = array_values(array_filter($result['events'], fn ($e) => $e['type'] === 'damage' && $e['target'] !== 'pc3'));
        $this->assertCount(2, $damages);
        $this->assertSame(52, $damages[0]['value']);
        $this->assertSame('dark', $damages[0]['element']);
        $this->assertSame(448, $units['enemy_1']['hp']);
        $this->assertSame(448, $units['enemy_2']['hp']);

        // 自分の HP を最大 HP の半分（50）消費する。対象が 2 体でも 1 回だけ
        $self = array_values(array_filter($result['events'], fn ($e) => $e['type'] === 'damage' && $e['target'] === 'pc3'));
        $this->assertCount(1, $self);
        $this->assertSame(50, $self[0]['value']);
        $this->assertSame(50, $units['pc3']['hp']);
        $this->assertTrue($units['pc3']['alive']);
        $this->assertSame(10, $units['pc3']['mp']);
    }

    public function test_black_hole_never_kills_the_caster(): void
    {
        $state = $this->state('pc3', 'pc3_black_hole');
        $state['units']['pc3']['hp'] = 20;
        $state['units']['pc3']['max_hp'] = 100;
        $state['units']['pc3']['mp'] = 40;
        foreach (['enemy_1', 'enemy_2'] as $id) {
            $state['units'][$id]['hp'] = 500;
            $state['units'][$id]['max_hp'] = 500;
        }

        $result = $this->engine()->applyPlayerAction($state, 'pc3', 'spell', 'pc3_black_hole');

        $this->assertSame(1, $result['state']['units']['pc3']['hp']);
        $this->assertTrue($result['state']['units']['pc3']['alive']);
    }

    public function test_ragaon_beat_lowers_defense_of_target_and_caster(): void
    {
        $state = $this->state('pc3', 'pc3_ragaon_beat');

        $result = $this->engine()->applyPlayerAction($state, 'pc3', 'spell', 'pc3_ragaon_beat', 'enemy_1');
        $units = $result['state']['units'];

        $this->assertArrayHasKey('def_down_huge', $units['enemy_1']['buffs']);
        $this->assertArrayHasKey('def_down_huge', $units['pc3']['buffs']);
        $this->assertArrayNotHasKey('def_down_huge', $units['enemy_2']['buffs']);
        $this->assertArrayNotHasKey('def_down_huge', $units['pc1']['buffs']);
        $this->assertSame(0.5, config('battle.buffs.def_down_huge.multiplier'));

        $targets = array_column(array_filter($result['events'], fn ($e) => $e['type'] === 'buff_applied'), 'target');
        $this->assertEqualsCanonicalizing(['enemy_1', 'pc3'], $targets);
    }

    public function test_rock_fall_deals_earth_damage_with_coefficient_three(): void
    {
        $state = $this->state('pc4', 'pc4_rock_fall');
        $state['units']['enemy_1']['hp'] = 500;
        $state['units']['enemy_1']['max_hp'] = 500;

        $result = $this->engine()->applyPlayerAction($state, 'pc4', 'spell', 'pc4_rock_fall', 'enemy_1');
        $damage = collect($result['events'])->firstWhere('type', 'damage');

        // (魔力20 - 防御5/2) × 3.0 = 52
        $this->assertSame(52, $damage['value']);
        $this->assertSame('earth', $damage['element']);
        $this->assertSame(18, $result['state']['units']['pc4']['mp']);
    }

    public function test_earth_wall_raises_defense_of_all_allies(): void
    {
        $state = $this->state('pc4', 'pc4_earth_wall');

        $result = $this->engine()->applyPlayerAction($state, 'pc4', 'spell', 'pc4_earth_wall');
        $units = $result['state']['units'];

        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $this->assertArrayHasKey('def_up_small', $units[$id]['buffs'], $id);
        }
        $this->assertArrayNotHasKey('def_up_small', $units['enemy_1']['buffs']);
        $this->assertSame(1.2, config('battle.buffs.def_up_small.multiplier'));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $actorId, string $spellId): array
    {
        $ally = static fn (string $id, string $name, int $spd) => [
            'id' => $id,
            'name' => $name,
            'side' => 'ally',
            'hp' => 30,
            'max_hp' => 50,
            'mp' => 30,
            'max_mp' => 50,
            'str' => 10,
            'mag' => $id === 'pc1' ? 15 : 20,
            'def' => 5,
            'spd' => $spd,
            'crit_rate' => 5,
            'evasion_rate' => 5,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
        $enemy = static fn (string $id, string $name) => [
            'id' => $id,
            'name' => $name,
            'side' => 'enemy',
            'hp' => 50,
            'max_hp' => 50,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 20,
            'mag' => 10,
            'def' => 5,
            'spd' => 1,
            'crit_rate' => 1,
            'evasion_rate' => 1,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
            'master_code' => 'bat',
        ];

        $units = [
            'pc1' => $ally('pc1', '水', 10),
            'pc2' => $ally('pc2', '木', 9),
            'pc3' => $ally('pc3', '火', 8),
            'pc4' => $ally('pc4', '土', 7),
            'enemy_1' => $enemy('enemy_1', '敵A'),
            'enemy_2' => $enemy('enemy_2', '敵B'),
        ];
        $units[$actorId]['spells'] = [$spellId];

        return [
            'status' => 'active',
            'current_actor' => $actorId,
            'awaiting_input' => true,
            'turn_order' => array_keys($units),
            'turn_index' => 0,
            'units' => $units,
            'log' => [],
        ];
    }
}
