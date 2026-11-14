<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\MasterData\MasterDataProvider;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 2層のボスを倒して覚える新魔法の効果。
 * 精霊の水・精霊の虹・水の凶刃（サフィリア）、ツルの大槍・大爆樹・宇宙樹の雫・迅速調合（ガルデ）、
 * 竜の息吹き・大爆発（グレン）、流星群・鋼の城壁・命の加護（シエナ）。
 */
class BattleEngineSecondBossSpellsTest extends TestCase
{
    use StageHelpers;

    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    public function test_new_spells_have_the_requested_mp_and_effects(): void
    {
        $masters = new MasterDataProvider;
        $row = fn (string $code): array => $masters->findSpell($code);

        $this->assertSame([24, 'ally_single', 'heal_full'], [$row('pc1_spirit_water')['mp_cost'], $row('pc1_spirit_water')['target_type'], $row('pc1_spirit_water')['effect']]);
        $this->assertSame([32, 'ally_all', 'heal_mag', 1.87], [$row('pc1_spirit_rainbow')['mp_cost'], $row('pc1_spirit_rainbow')['target_type'], $row('pc1_spirit_rainbow')['effect'], $row('pc1_spirit_rainbow')['coefficient']]);
        $this->assertSame([12, 'enemy_single', 'damage', 'water', 3.2], [$row('pc1_water_fierce_blade')['mp_cost'], $row('pc1_water_fierce_blade')['target_type'], $row('pc1_water_fierce_blade')['effect'], $row('pc1_water_fierce_blade')['element'], $row('pc1_water_fierce_blade')['coefficient']]);

        $this->assertSame([12, 'enemy_single', 'damage', 'wood', 3.2], [$row('pc2_vine_spear')['mp_cost'], $row('pc2_vine_spear')['target_type'], $row('pc2_vine_spear')['effect'], $row('pc2_vine_spear')['element'], $row('pc2_vine_spear')['coefficient']]);
        $this->assertSame([32, 'enemy_all', 'debuff_def', 'def_down_2'], [$row('pc2_great_burst_tree')['mp_cost'], $row('pc2_great_burst_tree')['target_type'], $row('pc2_great_burst_tree')['effect'], $row('pc2_great_burst_tree')['buff']]);
        $this->assertSame([50, 'ally_single', 'revive_full'], [$row('pc2_world_tree_drop')['mp_cost'], $row('pc2_world_tree_drop')['target_type'], $row('pc2_world_tree_drop')['effect']]);
        $this->assertSame([14, 'ally_single', 'heal_mag', 2.5, 'skill'], [$row('pc2_quick_brew')['mp_cost'], $row('pc2_quick_brew')['target_type'], $row('pc2_quick_brew')['effect'], $row('pc2_quick_brew')['coefficient'], $row('pc2_quick_brew')['cast_style']]);

        $this->assertSame([30, 'enemy_all', 'damage', 'fire', 2.4], [$row('pc3_dragon_breath')['mp_cost'], $row('pc3_dragon_breath')['target_type'], $row('pc3_dragon_breath')['effect'], $row('pc3_dragon_breath')['element'], $row('pc3_dragon_breath')['coefficient']]);
        $this->assertSame([26, 'enemy_single', 'damage', 'fire', 4.5], [$row('pc3_great_explosion')['mp_cost'], $row('pc3_great_explosion')['target_type'], $row('pc3_great_explosion')['effect'], $row('pc3_great_explosion')['element'], $row('pc3_great_explosion')['coefficient']]);

        $this->assertSame([14, 'enemy_all', 'damage', 'earth', 1.7], [$row('pc4_meteor_shower')['mp_cost'], $row('pc4_meteor_shower')['target_type'], $row('pc4_meteor_shower')['effect'], $row('pc4_meteor_shower')['element'], $row('pc4_meteor_shower')['coefficient']]);
        $this->assertSame([36, 'ally_all', 'buff_def', 'def_up_2'], [$row('pc4_steel_wall')['mp_cost'], $row('pc4_steel_wall')['target_type'], $row('pc4_steel_wall')['effect'], $row('pc4_steel_wall')['buff']]);
        $this->assertSame([26, 'ally_single', 'life_ward'], [$row('pc4_life_ward')['mp_cost'], $row('pc4_life_ward')['target_type'], $row('pc4_life_ward')['effect']]);

        $this->assertSame(0.65, config('battle.stage.multipliers.def')[-2]);
        $this->assertSame(1.4, config('battle.stage.multipliers.def')[2]);
    }

    public function test_spirit_water_fully_heals_one_ally(): void
    {
        $state = $this->state('pc1', 'pc1_spirit_water');
        $state['units']['pc2']['hp'] = 10;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_water', 'pc2');

        $heal = collect($result['events'])->firstWhere('type', 'heal');
        $this->assertSame(40, $heal['value']);
        $this->assertSame(50, $result['state']['units']['pc2']['hp']);
        $this->assertSame(6, $result['state']['units']['pc1']['mp']);
    }

    public function test_spirit_water_cannot_target_a_full_hp_or_fallen_ally(): void
    {
        $full = $this->state('pc1', 'pc1_spirit_water');
        $full['units']['pc2']['hp'] = 50;
        try {
            $this->engine()->applyPlayerAction($full, 'pc1', 'spell', 'pc1_spirit_water', 'pc2');
            $this->fail('HP満タンの味方への回復が拒否されませんでした。');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('回復できない対象です。', $e->getMessage());
        }

        $fallen = $this->state('pc1', 'pc1_spirit_water');
        $fallen['units']['pc2']['hp'] = 0;
        $fallen['units']['pc2']['alive'] = false;
        try {
            $this->engine()->applyPlayerAction($fallen, 'pc1', 'spell', 'pc1_spirit_water', 'pc2');
            $this->fail('倒れた味方への回復が拒否されませんでした。');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('回復できない対象です。', $e->getMessage());
        }
    }

    public function test_spirit_rainbow_heals_every_wounded_living_ally_by_mag_times_two_point_two(): void
    {
        $state = $this->state('pc1', 'pc1_spirit_rainbow');
        $state['units']['pc1']['mp'] = 40; // 消費MP 32
        $state['units']['pc1']['hp'] = 30; // 不足20
        $state['units']['pc2']['hp'] = 10; // 不足40
        $state['units']['pc3']['hp'] = 50; // 満タン
        $state['units']['pc4']['hp'] = 0;  // 戦闘不能
        $state['units']['pc4']['alive'] = false;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_rainbow');

        // 魔力 15 × 1.87 = 28（切り捨て）。不足ぶんまでしか回復しない
        $heals = collect($result['events'])->where('type', 'heal')->pluck('value', 'target')->all();
        $this->assertSame(['pc1' => 20, 'pc2' => 28], $heals);
        $units = $result['state']['units'];
        $this->assertSame([50, 38, 50], [$units['pc1']['hp'], $units['pc2']['hp'], $units['pc3']['hp']]);
        $this->assertSame(0, $units['pc4']['hp']);
        $this->assertFalse($units['pc4']['alive'], '倒れた味方は戻らない');
        $this->assertSame(8, $units['pc1']['mp']);
    }

    public function test_spirit_rainbow_needs_at_least_one_wounded_ally(): void
    {
        $state = $this->state('pc1', 'pc1_spirit_rainbow');
        $state['units']['pc1']['mp'] = 40;
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = 50;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('回復できない対象です。');
        $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_rainbow');
    }

    public function test_spirit_rainbow_is_listed_as_usable_when_someone_is_wounded(): void
    {
        $state = $this->state('pc1', 'pc1_spirit_rainbow');
        $state['units']['pc1']['mp'] = 40;
        $find = fn (array $s): array => collect($this->engine()->publicState($s)['commands']['spells'])->firstWhere('id', 'pc1_spirit_rainbow');

        $listed = $find($state);
        $this->assertSame(['ally_all', 'heal_mag', true, true], [$listed['target_type'], $listed['effect'], $listed['affordable'], $listed['usable']]);

        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = 50;
        }
        $this->assertFalse($find($state)['usable'], '誰も傷ついていなければ使えない');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function damageSpells(): array
    {
        // [魔法, 使うキャラ, 敵1体あたりのダメージ, 敵に当たる数]
        // 敵の防御5 → (魔力 - 5/2) × 係数。魔力は pc1 = 15、ほか = 20
        return [
            '水の凶刃 (魔力15 × 3.2)' => ['pc1_water_fierce_blade', 'pc1', 40, 1],
            'ツルの大槍 (魔力20 × 3.2)' => ['pc2_vine_spear', 'pc2', 56, 1],
            '大爆発 (魔力20 × 4.5)' => ['pc3_great_explosion', 'pc3', 78, 1],
            '竜の息吹き (魔力20 × 2.4)' => ['pc3_dragon_breath', 'pc3', 42, 2],
            '流星群 (魔力20 × 1.7)' => ['pc4_meteor_shower', 'pc4', 29, 2],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damageSpells')]
    public function test_damage_spells_use_their_coefficients(string $spellId, string $actor, int $expected, int $hits): void
    {
        $state = $this->state($actor, $spellId);
        $state['units'][$actor]['mp'] = 50;

        $result = $this->engine()->applyPlayerAction($state, $actor, 'spell', $spellId, $hits === 1 ? 'enemy_1' : null);

        $damages = collect($result['events'])->where('type', 'damage');
        $this->assertCount($hits, $damages);
        foreach ($damages as $damage) {
            $this->assertSame($expected, $damage['value']);
            $this->assertSame('ally', $result['state']['units'][$actor]['side']);
            $this->assertSame('enemy', $result['state']['units'][$damage['target']]['side']);
        }
    }

    public function test_great_burst_tree_lowers_every_enemys_defense_by_two_stages(): void
    {
        $state = $this->state('pc2', 'pc2_great_burst_tree');
        $state['units']['pc2']['mp'] = 50;

        $result = $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_great_burst_tree');

        $units = $result['state']['units'];
        $this->assertSame(-2, $this->stageSum($units['enemy_1'], 'def'));
        $this->assertSame(-2, $this->stageSum($units['enemy_2'], 'def'));
        $this->assertSame(0, $this->stageSum($units['pc1'], 'def'));

        // 防御 100 の敵は 65 として計算される（2段階ダウン = ×0.65）
        $method = new \ReflectionMethod(BattleEngine::class, 'effectiveStat');
        $method->setAccessible(true);
        $this->assertEqualsWithDelta(65.0, $method->invoke($this->engine(), array_merge($units['enemy_1'], ['def' => 100]), 'def'), 0.0001);
    }

    public function test_world_tree_drop_always_revives_with_full_hp(): void
    {
        $state = $this->state('pc2', 'pc2_world_tree_drop');
        $state['units']['pc2']['mp'] = 60;
        $state['units']['pc4']['hp'] = 0;
        $state['units']['pc4']['alive'] = false;

        // ロール100でも（確率ではなく）必ず成功する
        $result = $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_world_tree_drop', 'pc4');

        $revive = collect($result['events'])->firstWhere('type', 'revive');
        $this->assertNotNull($revive);
        $this->assertSame(50, $revive['hp']);
        $this->assertNotContains('revive_failed', array_column($result['events'], 'type'));
        $this->assertTrue($result['state']['units']['pc4']['alive']);
        $this->assertSame(50, $result['state']['units']['pc4']['hp']);
        $this->assertSame(10, $result['state']['units']['pc2']['mp']);
    }

    public function test_world_tree_drop_cannot_target_a_living_ally(): void
    {
        $state = $this->state('pc2', 'pc2_world_tree_drop');
        $state['units']['pc2']['mp'] = 50;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('蘇生できない対象です。');
        $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_world_tree_drop', 'pc3');
    }

    public function test_quick_brew_heals_mag_times_two_and_a_half_and_is_announced_as_a_skill(): void
    {
        $state = $this->state('pc2', 'pc2_quick_brew');
        $state['units']['pc3']['max_hp'] = 200;
        $state['units']['pc3']['hp'] = 5;

        $result = $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_quick_brew', 'pc3');

        $announce = collect($result['events'])->firstWhere('type', 'announce');
        $this->assertSame('木の迅速調合！', $announce['text']);
        $this->assertStringNotContainsString('唱え', $announce['text']);

        // 魔力 20 × 2.5 = 50、消費MP 14
        $this->assertSame(50, collect($result['events'])->firstWhere('type', 'heal')['value']);
        $this->assertSame(55, $result['state']['units']['pc3']['hp']);
        $this->assertSame(16, $result['state']['units']['pc2']['mp']);
    }

    public function test_ordinary_spells_are_still_announced_as_cast(): void
    {
        $state = $this->state('pc1', 'pc1_heal_water');

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc2');

        $this->assertSame('水は癒しの水を唱えた！', collect($result['events'])->firstWhere('type', 'announce')['text']);
    }

    public function test_steel_wall_raises_defense_of_every_living_ally_by_two_stages(): void
    {
        $state = $this->state('pc4', 'pc4_steel_wall');
        $state['units']['pc4']['mp'] = 50;
        $state['units']['pc3']['hp'] = 0;
        $state['units']['pc3']['alive'] = false;

        $result = $this->engine()->applyPlayerAction($state, 'pc4', 'spell', 'pc4_steel_wall');

        $units = $result['state']['units'];
        foreach (['pc1', 'pc2'] as $id) {
            $this->assertSame(2, $this->stageSum($units[$id], 'def'), $id);
            $this->assertSame(3, $units[$id]['stages'][0]['turns'], $id);
        }
        // 唱えた本人も、ほかの味方と同じ長さ（持続はラウンドの終わりに全員いっしょに減る。唱えた本人だけ先に減ることはない）
        $this->assertSame(2, $this->stageSum($units['pc4'], 'def'));
        $this->assertSame(3, $units['pc4']['stages'][0]['turns']);
        $this->assertSame(0, $this->stageSum($units['pc3'], 'def'), '倒れた味方にはかからない');
        $this->assertSame(0, $this->stageSum($units['enemy_1'], 'def'));
        $this->assertSame(1.4, config('battle.stage.multipliers.def')[2]);
    }

    public function test_life_ward_marks_the_ally_and_shows_in_the_public_state(): void
    {
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc4']['mp'] = 50;

        $result = $this->engine()->applyPlayerAction($state, 'pc4', 'spell', 'pc4_life_ward', 'pc1');

        $this->assertTrue($result['state']['units']['pc1']['life_ward']);
        $this->assertSame(24, $result['state']['units']['pc4']['mp']);
        $applied = collect($result['events'])->firstWhere('type', 'life_ward_applied');
        $this->assertSame('pc1', $applied['target']);

        $public = collect($this->engine()->publicState($result['state'])['units'])->keyBy('id');
        $this->assertTrue($public['pc1']['life_ward']);
        $this->assertFalse($public['pc2']['life_ward']);
    }

    public function test_life_ward_leaves_one_hp_against_a_lethal_hit_once(): void
    {
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc1']['life_ward'] = true;
        $engine = $this->engine();

        $events = $this->applyDamage($engine, $state, 'enemy_1', 'pc1', 100);

        $this->assertSame(['damage', 'life_ward_triggered'], array_column($events, 'type'));
        $this->assertSame(29, $events[0]['value'], '残りHP30のうち29だけ受けて、HP1で踏みとどまる');
        $this->assertSame(1, $state['units']['pc1']['hp']);
        $this->assertTrue($state['units']['pc1']['alive']);
        $this->assertFalse($state['units']['pc1']['life_ward'], '発動したら加護は消える');

        // 2回目の致命的な攻撃では倒れる
        $events = $this->applyDamage($engine, $state, 'enemy_1', 'pc1', 100);
        $this->assertSame(['damage'], array_column($events, 'type'));
        $this->assertSame(0, $state['units']['pc1']['hp']);
        $this->assertFalse($state['units']['pc1']['alive']);
    }

    public function test_life_ward_triggers_when_damage_exactly_equals_hp(): void
    {
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc1']['life_ward'] = true;

        $this->applyDamage($this->engine(), $state, 'enemy_1', 'pc1', 30);

        $this->assertSame(1, $state['units']['pc1']['hp']);
        $this->assertFalse($state['units']['pc1']['life_ward']);
    }

    public function test_life_ward_stays_until_a_lethal_hit(): void
    {
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc1']['life_ward'] = true;
        $engine = $this->engine();

        $events = $this->applyDamage($engine, $state, 'enemy_1', 'pc1', 10);

        $this->assertSame(['damage'], array_column($events, 'type'));
        $this->assertSame(20, $state['units']['pc1']['hp']);
        $this->assertTrue($state['units']['pc1']['life_ward'], '倒れない攻撃では消えない（ターンが過ぎても永続）');

        // ターン経過でも消えない
        $method = new \ReflectionMethod(BattleEngine::class, 'tickBuffsForUnit');
        $method->setAccessible(true);
        $method->invokeArgs($engine, [&$state['units']['pc1']]);
        $this->assertTrue($state['units']['pc1']['life_ward']);
    }

    public function test_life_ward_at_one_hp_absorbs_the_hit_without_a_damage_number(): void
    {
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc1']['hp'] = 1;
        $state['units']['pc1']['life_ward'] = true;

        $events = $this->applyDamage($this->engine(), $state, 'enemy_1', 'pc1', 5);

        $this->assertSame(['life_ward_triggered'], array_column($events, 'type'));
        $this->assertSame(1, $state['units']['pc1']['hp']);
        $this->assertTrue($state['units']['pc1']['alive']);
    }

    public function test_barrier_is_used_before_the_life_ward(): void
    {
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc1']['life_ward'] = true;
        $state['units']['pc1']['damage_shield'] = true;

        $events = $this->applyDamage($this->engine(), $state, 'enemy_1', 'pc1', 100);

        $this->assertSame(['shield_break'], array_column($events, 'type'));
        $this->assertSame(30, $state['units']['pc1']['hp']);
        $this->assertTrue($state['units']['pc1']['life_ward'], '魔法障壁で防いだぶんは加護を消費しない');
    }

    public function test_life_ward_does_not_save_an_ally_who_is_not_hit_lethally_by_spells_either(): void
    {
        // 魔法の攻撃（敵のダメージ魔法）でも同じ経路で加護が働く
        $state = $this->state('pc4', 'pc4_life_ward');
        $state['units']['pc1']['hp'] = 5;
        $state['units']['pc1']['life_ward'] = true;
        $state['units']['enemy_1']['mag'] = 200;

        $method = new \ReflectionMethod(BattleEngine::class, 'applySpellEffect');
        $method->setAccessible(true);
        $spell = (new MasterDataProvider)->findSpell('pc3_fire_burst');
        $events = $method->invokeArgs($this->engine(), [&$state, 'enemy_1', 'pc1', $spell, 'pc3_fire_burst']);

        $this->assertContains('life_ward_triggered', array_column($events, 'type'));
        $this->assertSame(1, $state['units']['pc1']['hp']);
        $this->assertTrue($state['units']['pc1']['alive']);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function applyDamage(BattleEngine $engine, array &$state, string $actorId, string $targetId, int $damage): array
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'applyDamage');
        $method->setAccessible(true);

        return $method->invokeArgs($engine, [&$state, $actorId, $targetId, $damage, null, false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $actorId, string $spellId): array
    {
        $ally = static fn (string $id, string $name, int $spd) => [
            'id' => $id,
            'name' => $name,
            'sprite' => $id,
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
            'sprite' => 'bat',
            'side' => 'enemy',
            'hp' => 500,
            'max_hp' => 500,
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
