<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\SpellCategory;
use App\Services\MasterData\CsvMasterReader;
use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

/**
 * 戦闘のコマンド画面で、魔法・特技を「攻撃」「回復」「補助」に大別する（spell_masters.csv の category 列）。
 */
class SpellCategoryTest extends TestCase
{
    public function test_every_spell_row_has_an_explicit_valid_category(): void
    {
        $rows = CsvMasterReader::read('spell_masters.csv');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertContains($row['category'] ?? '', SpellCategory::ALL, "{$row['code']} の category が空か、不正");
        }
    }

    public function test_the_category_of_each_spell_follows_what_it_does(): void
    {
        $masters = new MasterDataProvider;
        $expected = [
            // 攻撃: 敵にダメージ（HP 消費つき・即死も含む）
            'attack' => ['pc1_water_blade', 'pc1_water_fierce_blade', 'pc2_vine_whip', 'pc2_vine_spear', 'pc3_fire_shot', 'pc3_fire_burst',
                'pc3_great_explosion', 'pc3_fire_wave', 'pc3_dragon_breath', 'pc3_black_hole', 'pc4_stone_shot', 'pc4_rock_fall', 'pc4_meteor_shower'],
            // 回復: HP 回復・全回復・蘇生・MP 回復
            'heal' => ['pc1_heal_water', 'pc1_holy_water', 'pc1_spirit_water', 'pc1_spirit_rainbow', 'pc1_miracle_rainbow', 'pc1_miracle_drop',
                'pc2_world_tree', 'pc2_world_tree_breath', 'pc2_world_tree_drop', 'pc2_quick_brew', 'pc3_mana_focus', 'pc4_first_aid'],
            // 補助: バフ・デバフ・回避・障壁・加護
            'support' => ['pc1_water_veil', 'pc1_water_veil_all', 'pc2_vine_bind', 'pc2_burst_tree', 'pc2_great_burst_tree', 'pc2_fighting_melody',
                'pc3_ragaon_beat', 'pc4_earth_armor', 'pc4_earth_wall', 'pc4_steel_wall', 'pc4_shield_magic', 'pc4_life_ward'],
        ];

        foreach ($expected as $category => $codes) {
            foreach ($codes as $code) {
                $this->assertSame($category, $masters->findSpell($code)['category'], $code);
            }
        }

        // プレイヤーの魔法が、どれかの分類に入っている（上の表に漏れがない）
        $listed = array_merge(...array_values($expected));
        $playerSpells = collect(CsvMasterReader::read('character_spells.csv'))->pluck('spell_code')->unique()->all();
        $this->assertSame([], array_values(array_diff($playerSpells, $listed)), '分類の表にない魔法');
    }

    public function test_every_effect_used_by_a_spell_has_a_category_rule(): void
    {
        // 空の category は、効果の種類から決める（表にない効果は「補助」）。新しい効果を足したら、表にも足す（足し忘れを見張る）
        foreach (CsvMasterReader::read('spell_masters.csv') as $row) {
            $this->assertTrue(SpellCategory::knows($row['effect']), "{$row['code']} の効果 {$row['effect']} が、SpellCategory の表にない");
        }

        $this->assertSame('support', SpellCategory::forEffect('something_new'));
        $this->assertSame('heal', SpellCategory::resolve('', 'heal_mag'));
        $this->assertSame('heal', SpellCategory::resolve(null, 'mp_heal_hp_cost'));
        // 書いた値が正しければ、効果の種類より優先（例: 魔力集中を補助に動かしたいときは CSV の category を support にする）
        $this->assertSame('support', SpellCategory::resolve('support', 'mp_heal_hp_cost'));
        // 不正な値は、効果の種類から決める
        $this->assertSame('attack', SpellCategory::resolve('???', 'damage'));
    }

    public function test_commands_carry_the_category_of_each_spell_in_menu_order(): void
    {
        $engine = new BattleEngine(factory: new BattleFactory);
        $state = [
            'status' => 'active',
            'current_actor' => 'pc1',
            'awaiting_input' => true,
            'turn_order' => ['pc1', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc1' => [
                    'id' => 'pc1', 'name' => 'サフィリア', 'sprite' => 'PC1', 'master_code' => 'pc1', 'side' => 'ally', 'level' => 30,
                    'hp' => 100, 'max_hp' => 1000, 'mp' => 200, 'max_mp' => 200, 'str' => 10, 'mag' => 100, 'def' => 5, 'pdef' => 5, 'mdef' => 5,
                    'spd' => 10, 'crit_rate' => 0, 'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true, 'buffs' => [], 'stages' => [],
                    'statuses' => [],
                    'spells' => ['pc1_heal_water', 'pc1_water_blade', 'pc1_water_veil', 'pc1_miracle_drop', 'pc1_spirit_water'],
                ],
                'enemy_1' => [
                    'id' => 'enemy_1', 'name' => 'e', 'sprite' => 'bat', 'master_code' => 'bat', 'side' => 'enemy', 'hp' => 50, 'max_hp' => 50,
                    'mp' => 0, 'max_mp' => 0, 'str' => 1, 'mag' => 1, 'def' => 1, 'pdef' => 1, 'mdef' => 1, 'spd' => 1, 'crit_rate' => 0,
                    'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true, 'buffs' => [], 'stages' => [], 'statuses' => [], 'spells' => [],
                ],
            ],
            'meta' => [],
            'log' => [],
        ];

        $spells = $engine->publicState($state)['commands']['spells'];

        // 順番はサーバーでは変えない（並べ替えとラベル付けは画面）。各魔法に category が付く
        $this->assertSame(
            ['pc1_heal_water', 'pc1_water_blade', 'pc1_water_veil', 'pc1_miracle_drop', 'pc1_spirit_water'],
            array_column($spells, 'id'),
        );
        $this->assertSame(['heal', 'attack', 'support', 'heal', 'heal'], array_column($spells, 'category'));
    }
}
