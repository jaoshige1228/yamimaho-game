<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\EnemySkillPicker;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\MasterDataProvider;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 空間の裂け目の先のボス戦（慈愛・伝説・奏楽・光導の 4 人の魔法使い）。
 * 敵は enemy_1 = 慈愛 / enemy_2 = 伝説 / enemy_3 = 奏楽 / enemy_4 = 光導。
 */
class RiftBossTest extends TestCase
{
    use RefreshDatabase;

    private const CODES = ['rift_mercy', 'rift_legend', 'rift_music', 'rift_guide'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    // ---- データ ----

    public function test_every_mage_is_faster_than_every_level_35_party_member(): void
    {
        $masters = new MasterDataProvider;
        $growth = new LevelGrowthService;
        $partySpeeds = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $code) {
            $partySpeeds[$code] = $growth->statsForLevel(\App\Models\CharacterMaster::where('code', $code)->firstOrFail(), 35)['spd'];
        }

        foreach (self::CODES as $code) {
            $this->assertGreaterThan(max($partySpeeds), (int) $masters->findEnemy($code, 3)['spd'], "{$code} の素早さは Lv35 のパーティー全員より高い");
        }
    }

    public function test_the_four_mages_always_act_before_every_level_35_party_member(): void
    {
        $state = $this->realState();

        $order = $state['turn_order'];
        $this->assertSame(['enemy_3', 'enemy_4', 'enemy_1', 'enemy_2'], array_slice($order, 0, 4), '素早さ順（奏楽 → 光導 → 慈愛 → 伝説）で、全員がPCより先');
        $this->assertSame('enemy_3', $state['current_actor']);

        $engine = $this->engine();
        $result = $engine->runEnemyTurnsUntilPlayer($state);
        $actors = array_column(array_filter($result['events'], fn ($e) => $e['type'] === 'turn_start'), 'actor');
        $this->assertSame(['enemy_3', 'enemy_4', 'enemy_1', 'enemy_2'], $actors, '開幕の敵フェーズで 4 人とも先に動く');
        $this->assertTrue($result['state']['awaiting_input']);
    }

    public function test_rewards_and_display_data(): void
    {
        $masters = new MasterDataProvider;
        $exp = $gold = 0;
        foreach (self::CODES as $code) {
            $enemy = $masters->findEnemy($code, 3);
            $exp += $enemy['exp_reward'];
            $gold += $enemy['gold_reward'];
            $this->assertSame($code, $enemy['sprite'], '画像のキーはコードと同じ（enemies/ の WebP）');
            $this->assertFileExists(base_path("../frontend/public/assets/enemies/{$code}.webp"));
        }
        $this->assertSame(40000, $exp);
        $this->assertSame(30000, $gold);
        $this->assertSame(
            ['慈愛の魔法使い', '伝説の魔法使い', '奏楽の魔法使い', '光導の魔法使い'],
            array_map(fn (string $code) => $masters->findEnemy($code, 3)['name'], self::CODES),
        );
    }

    public function test_spells_are_separate_data_from_the_party_spells_with_the_same_names(): void
    {
        $masters = new MasterDataProvider;
        $spell = fn (string $code): array => $masters->findSpell($code);

        // 名前は PC と同じ。コードとデータは別（PC が覚える魔法の一覧には入らない）
        $pairs = [
            'rift_stone_shot' => 'pc4_stone_shot', 'rift_meteor_shower' => 'pc4_meteor_shower', 'rift_steel_wall' => 'pc4_steel_wall',
            'rift_life_ward' => 'pc4_life_ward', 'rift_great_explosion' => 'pc3_great_explosion', 'rift_ragaon_beat' => 'pc3_ragaon_beat',
            'rift_black_hole' => 'pc3_black_hole', 'rift_world_tree_drop' => 'pc2_world_tree_drop', 'rift_vine_whip' => 'pc2_vine_whip',
            'rift_vine_spear' => 'pc2_vine_spear', 'rift_quick_brew' => 'pc2_quick_brew', 'rift_spirit_water' => 'pc1_spirit_water',
            'rift_spirit_rainbow' => 'pc1_spirit_rainbow', 'rift_water_blade' => 'pc1_water_blade', 'rift_water_fierce_blade' => 'pc1_water_fierce_blade',
        ];
        foreach ($pairs as $rift => $pc) {
            $this->assertSame($spell($pc)['label'], $spell($rift)['label'], "{$rift} は {$pc} と同じ名前");
        }
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $character) {
            $this->assertSame([], array_filter(
                $masters->spellIdsForCharacter($character, array_keys($pairs)),
                fn (string $id) => str_starts_with($id, 'rift_'),
            ));
        }

        $this->assertSame(['enemy_single', 'damage', 'earth'], [$spell('rift_stone_shot')['target_type'], $spell('rift_stone_shot')['effect'], $spell('rift_stone_shot')['element']]);
        $this->assertSame(['enemy_all', 'damage'], [$spell('rift_meteor_shower')['target_type'], $spell('rift_meteor_shower')['effect']]);
        $this->assertSame(['enemy_single', 'damage'], [$spell('rift_heaven_strike')['target_type'], $spell('rift_heaven_strike')['effect']]);
        $this->assertSame(['ally_all', 'buff_def', 'def_up_2'], [$spell('rift_steel_wall')['target_type'], $spell('rift_steel_wall')['effect'], $spell('rift_steel_wall')['buff']]);
        $this->assertSame(['ally_all', 'life_ward'], [$spell('rift_life_ward')['target_type'], $spell('rift_life_ward')['effect']]);
        $this->assertSame(['enemy_single', 'damage'], [$spell('rift_great_explosion')['target_type'], $spell('rift_great_explosion')['effect']]);
        $this->assertSame(['enemy_single', 'debuff_def', 'def_down_3'], [$spell('rift_ragaon_beat')['target_type'], $spell('rift_ragaon_beat')['effect'], $spell('rift_ragaon_beat')['buff']], '敵版は自分に掛からない（debuff_def_both ではない）');
        $this->assertSame(['enemy_all', 'damage'], [$spell('rift_black_hole')['target_type'], $spell('rift_black_hole')['effect']]);
        $this->assertSame(['enemy_all', 'damage'], [$spell('rift_evil_infinity')['target_type'], $spell('rift_evil_infinity')['effect']]);
        $this->assertGreaterThan($spell('rift_black_hole')['coefficient'], $spell('rift_evil_infinity')['coefficient'] * 2, '超特大（大ダメージのブラックホールより上）');
        $this->assertSame(['enemy_all', 'debuff_def', 'def_down_2', 'skill'], [$spell('rift_resentment_melody')['target_type'], $spell('rift_resentment_melody')['effect'], $spell('rift_resentment_melody')['buff'], $spell('rift_resentment_melody')['cast_style']]);
        $this->assertSame(['ally_all', 'buff', 'atk_up_1', 'skill'], [$spell('rift_counter_melody')['target_type'], $spell('rift_counter_melody')['effect'], $spell('rift_counter_melody')['buff'], $spell('rift_counter_melody')['cast_style']]);
        $this->assertSame(['ally_single', 'revive_chance', 100], [$spell('rift_world_tree_drop')['target_type'], $spell('rift_world_tree_drop')['effect'], $spell('rift_world_tree_drop')['power']]);
        $this->assertSame(['ally_single', 'heal_mag', 'skill'], [$spell('rift_quick_brew')['target_type'], $spell('rift_quick_brew')['effect'], $spell('rift_quick_brew')['cast_style']]);
        $this->assertSame(['ally_single', 'heal_mag'], [$spell('rift_spirit_water')['target_type'], $spell('rift_spirit_water')['effect']]);
        $this->assertSame(['ally_all', 'heal_mag'], [$spell('rift_spirit_rainbow')['target_type'], $spell('rift_spirit_rainbow')['effect']]);
        $this->assertGreaterThan($spell('rift_spirit_rainbow')['coefficient'], $spell('rift_spirit_water')['coefficient'], '精霊の水は中回復、精霊の虹は小回復');
        $this->assertSame(['ally_all', 'buff_evasion_set', 'evasion_set_20'], [$spell('rift_devil_illusion')['target_type'], $spell('rift_devil_illusion')['effect'], $spell('rift_devil_illusion')['buff']]);
        $this->assertSame(['evasion_set', 20, 3], [config('battle.buffs.evasion_set_20.type'), config('battle.buffs.evasion_set_20.value'), config('battle.buffs.evasion_set_20.turns')]);
    }

    public function test_skill_lists_and_rules_follow_the_design(): void
    {
        $masters = new MasterDataProvider;
        $skills = fn (string $enemy) => collect($masters->enemySkillsFor($enemy))->pluck('weight', 'skill_code')->all();

        $this->assertEqualsCanonicalizing(['rift_life_ward', 'rift_steel_wall', 'rift_stone_shot', 'rift_meteor_shower', 'rift_heaven_strike'], array_keys($skills('rift_mercy')));
        $this->assertEqualsCanonicalizing(['rift_great_explosion', 'rift_ragaon_beat', 'rift_black_hole', 'rift_evil_infinity'], array_keys($skills('rift_legend')));
        $this->assertEqualsCanonicalizing(['rift_world_tree_drop', 'rift_resentment_melody', 'rift_counter_melody', 'rift_vine_whip', 'rift_vine_spear', 'rift_quick_brew'], array_keys($skills('rift_music')));
        $this->assertEqualsCanonicalizing(['rift_devil_illusion', 'rift_spirit_water', 'rift_spirit_rainbow', 'rift_water_blade', 'rift_water_fierce_blade'], array_keys($skills('rift_guide')));

        // 決まったターンにだけ使う技は、抽選に入れない（重み 0）
        foreach (['rift_life_ward', 'rift_steel_wall', 'rift_evil_infinity', 'rift_world_tree_drop', 'rift_resentment_melody', 'rift_counter_melody', 'rift_devil_illusion'] as $code) {
            $weights = array_merge($skills('rift_mercy'), $skills('rift_legend'), $skills('rift_music'), $skills('rift_guide'));
            $this->assertSame(0, $weights[$code], $code);
        }
        // 光導は、回復ばかりにならないよう、回復の重みが攻撃より小さい
        $guide = $skills('rift_guide');
        $this->assertLessThan($guide['rift_water_blade'] + $guide['rift_water_fierce_blade'], $guide['rift_spirit_water'] + $guide['rift_spirit_rainbow']);
        $this->assertLessThanOrEqual(0.25, ($guide['rift_spirit_water'] + $guide['rift_spirit_rainbow']) / array_sum($guide), '回復は 2 割前後');

        $this->assertSame([], $masters->enemySkillRulesFor('goblin_that_does_not_exist'));
        $this->assertSame([], $masters->enemySkillRulesFor('bat'), '既存の敵はルールを持たない');
    }

    // ---- 行動のパターン ----

    public function test_the_mercy_mage_follows_its_turn_script(): void
    {
        $texts = $this->enemyAnnouncesByRound('enemy_1', 12);

        $this->assertSame('慈愛の魔法使いは命の加護を唱えた！', $texts[1]);
        $this->assertSame('慈愛の魔法使いは鋼の城壁を唱えた！', $texts[2]);
        foreach ([5, 8, 11] as $turn) {
            $this->assertSame('慈愛の魔法使いは鋼の城壁を唱えた！', $texts[$turn], "{$turn} ターン目");
        }
        $attacks = ['慈愛の魔法使いは石弾を唱えた！', '慈愛の魔法使いは流星群を唱えた！', '慈愛の魔法使いは天撃を唱えた！'];
        foreach ([3, 4, 6, 7, 9, 10, 12] as $turn) {
            $this->assertContains($texts[$turn], $attacks, "{$turn} ターン目は石弾・流星群・天撃のどれか");
        }
    }

    public function test_the_mercy_mage_wards_everyone_and_then_raises_defense_by_two_stages(): void
    {
        $engine = $this->engine();
        $state = $this->realState();
        $result = $engine->runEnemyTurnsUntilPlayer($state); // 1 ターン目: 命の加護

        $units = $result['state']['units'];
        foreach (['enemy_1', 'enemy_2', 'enemy_3', 'enemy_4'] as $id) {
            $this->assertTrue($units[$id]['life_ward'], "{$id} に命の加護");
        }
        $this->assertSame(0, $this->stageSum($units['enemy_1'], 'def'));

        $state = $this->roundOf($engine, $result['state']);
        $result = $engine->runEnemyTurnsUntilPlayer($state); // 2 ターン目: 鋼の城壁（防御+2段階）
        foreach (['enemy_1', 'enemy_2', 'enemy_3', 'enemy_4'] as $id) {
            $this->assertSame(2, $this->stageSum($result['state']['units'][$id], 'def'), "{$id} は防御 2 段階上昇");
        }
    }

    public function test_a_warded_mage_survives_a_lethal_hit_with_one_hp(): void
    {
        $engine = $this->engine();
        $result = $engine->runEnemyTurnsUntilPlayer($this->realState());
        $state = $result['state'];
        $state['units']['enemy_4']['hp'] = 5;

        $hit = $engine->applyPlayerAction($state, $state['current_actor'], 'punch', null, 'enemy_4');
        $this->assertSame(1, $hit['state']['units']['enemy_4']['hp']);
        $this->assertTrue($hit['state']['units']['enemy_4']['alive']);
        $this->assertFalse($hit['state']['units']['enemy_4']['life_ward']);
    }

    public function test_the_legend_mage_starts_the_evil_spell_on_turn_4_and_fires_on_turn_6(): void
    {
        $engine = $this->engine();
        $state = $this->realState();
        $rounds = $this->playRounds($engine, $state, 9);

        $legend = fn (int $round) => array_values(array_filter(
            $rounds[$round]['events'],
            fn (array $e) => ($e['actor'] ?? null) === 'enemy_2' && $e['type'] === 'announce',
        ));
        $normal = ['伝説の魔法使いは大爆発を唱えた！', '伝説の魔法使いはラグオンビートを唱えた！', '伝説の魔法使いはブラックホールを唱えた！'];

        foreach ([1, 2, 3, 7, 8, 9] as $round) {
            $this->assertContains($legend($round)[0]['text'], $normal, "{$round} ターン目は通常の魔法");
        }
        $this->assertSame('伝説の魔法使いはおぞましい魔法を唱えようとしている……。', $legend(4)[0]['text']);
        $this->assertSame('伝説の魔法使いはおぞましい魔法を詠唱し始めた！', $legend(5)[0]['text']);
        $this->assertSame('伝説の魔法使いは悪魔級殲滅魔法（イーヴィルインフィニティ）を唱え終えた！', $legend(6)[0]['text']);

        // 4・5 ターン目は、ほかの行動をしない（ダメージも出さない）。6 ターン目に生きている味方全員へダメージ
        foreach ([4, 5] as $round) {
            $this->assertCount(1, $legend($round), "{$round} ターン目は告知 1 つだけ");
            $this->assertSame([], $this->eventsBy($rounds[$round]['events'], 'enemy_2', 'damage'));
        }
        $damaged = array_column($this->eventsBy($rounds[6]['events'], 'enemy_2', 'damage'), 'target');
        $this->assertEqualsCanonicalizing(['pc1', 'pc2', 'pc3', 'pc4'], $damaged);
        $this->assertSame([], $this->eventsBy($rounds[7]['events'], 'enemy_2', 'damage', onlyType: 'spell_charge'));
        // 唱え終えたあとの詠唱の状態は残らない
        $this->assertArrayNotHasKey('charge', $rounds[8]['state']['units']['enemy_2']);
        // 演出: 1 つ目と 3 つ目は「唱え」を含む（魔法のフラッシュと効果音が出る）
        $this->assertStringContainsString('唱え', $legend(4)[0]['text']);
        $this->assertStringContainsString('唱え', $legend(6)[0]['text']);
        $this->assertStringNotContainsString('唱え', $legend(5)[0]['text']);
        $this->assertSame('dark', $legend(6)[0]['spell_element']);
        // 詠唱の 3 つの告知も、敵の技の告知として、画面が長めに残す
        foreach ([4, 5, 6] as $round) {
            $this->assertTrue($legend($round)[0]['skill'], "{$round} ターン目の告知");
        }
    }

    public function test_the_evil_spell_is_cancelled_when_the_legend_mage_is_defeated_while_chanting(): void
    {
        $texts = $this->announceTextsWhenLegendIsDefeatedAfterRound(4, allowRevive: false);

        $this->assertContains('伝説の魔法使いはおぞましい魔法を唱えようとしている……。', $texts);
        $this->assertNotContains('伝説の魔法使いはおぞましい魔法を詠唱し始めた！', $texts, '倒れたら行動しない');
        $this->assertNotContains('伝説の魔法使いは悪魔級殲滅魔法（イーヴィルインフィニティ）を唱え終えた！', $texts);
    }

    public function test_a_revived_legend_mage_does_not_resume_the_interrupted_spell(): void
    {
        // 奏楽の魔法使いが宇宙樹の雫で蘇生させても、詠唱は中断されたまま（続きから唱えたりしない）
        $texts = $this->announceTextsWhenLegendIsDefeatedAfterRound(4, allowRevive: true);

        $this->assertContains('奏楽の魔法使いは宇宙樹の雫を唱えた！', $texts);
        $this->assertNotContains('伝説の魔法使いはおぞましい魔法を詠唱し始めた！', $texts);
        $this->assertNotContains('伝説の魔法使いは悪魔級殲滅魔法（イーヴィルインフィニティ）を唱え終えた！', $texts);
    }

    public function test_the_evil_spell_is_heavy_but_never_lethal_for_a_full_hp_level_35_party(): void
    {
        // 満タンのレベル35・フル装備のパーティーに、悪魔級殲滅魔法を撃つ。対策なしでも致命傷にならない（魔力 ×0.9・威力 ×1.2 の調整後は、HP の約 3〜4 割が残った。
        // 2026-11-14 に 4 人の攻撃力〔魔力〕を ×1.03 にしたあとは、HP の約 2.8〜3.3 割が残る）
        foreach ($this->evilSpellRemainingRatios(defenseStage: 0) as $id => $ratio) {
            $this->assertGreaterThanOrEqual(0.2, $ratio, "{$id} は耐える（致命傷にならない）");
            $this->assertLessThanOrEqual(0.45, $ratio, "{$id} は HP の 5 割以上を失う（超特大ダメージ）");
        }

        // 実際の戦闘では、奏楽の怨恨の旋律（防御-2段階）が効いている。さらにラグオンビート（-3段階）が重なっても、ぎりぎり耐える
        // （攻撃力 ×1.03 のあとは、防御-2段階で HP の約 15〜20%、-3段階で約 9〜15% が残る）
        foreach ([-2, -3] as $stage) {
            foreach ($this->evilSpellRemainingRatios(defenseStage: $stage) as $id => $ratio) {
                $this->assertGreaterThanOrEqual(0.05, $ratio, "{$id}: 防御{$stage}段階でも耐える");
                $this->assertLessThanOrEqual(0.3, $ratio, "{$id}: 防御{$stage}段階では HP の 7 割以上を失う");
            }
        }
    }

    public function test_the_music_mage_follows_its_turn_script(): void
    {
        $texts = $this->enemyAnnouncesByRound('enemy_3', 12);

        foreach ([1, 5, 10] as $turn) {
            $this->assertSame('奏楽の魔法使いの怨恨の旋律！', $texts[$turn], "{$turn} ターン目");
        }
        foreach ([2, 7, 12] as $turn) {
            $this->assertSame('奏楽の魔法使いの逆襲の旋律！', $texts[$turn], "{$turn} ターン目");
        }
        $others = ['奏楽の魔法使いはツルの鞭を唱えた！', '奏楽の魔法使いはツルの大槍を唱えた！'];
        foreach ([3, 4, 6, 8, 9, 11] as $turn) {
            // 誰も傷ついていないので、迅速調合は引いても攻撃に引き直される（空振りしない）
            $this->assertContains($texts[$turn], $others, "{$turn} ターン目");
        }
    }

    public function test_the_music_mage_debuffs_everyone_and_buffs_the_mages_attack(): void
    {
        // 重みの抽選は常に先頭の技（伝説は大爆発）にして、伝説のラグオンビートの防御ダウンが混ざらないようにする
        $engine = new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 0),
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
        $result = $engine->runEnemyTurnsUntilPlayer($this->realState()); // 1 ターン目: 怨恨の旋律
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $this->assertSame(-2, $this->stageSum($result['state']['units'][$id], 'def'), "{$id} は防御 2 段階低下");
        }

        $state = $this->roundOf($engine, $result['state']);
        $result = $engine->runEnemyTurnsUntilPlayer($state); // 2 ターン目: 逆襲の旋律
        foreach (['enemy_1', 'enemy_2', 'enemy_3', 'enemy_4'] as $id) {
            $this->assertSame(1, $this->stageSum($result['state']['units'][$id], 'atk'), "{$id} は攻撃 1 段階上昇");
        }
    }

    public function test_the_music_mage_revives_a_fallen_mage_once_per_battle_with_top_priority(): void
    {
        $engine = $this->engine();
        $state = $this->realState();
        $state['units']['enemy_1']['hp'] = 0;
        $state['units']['enemy_1']['alive'] = false;

        // 1 ターン目は怨恨の旋律の番だが、味方（慈愛）が倒れているので、宇宙樹の雫が優先
        $first = $this->chooseSkill($engine, $state, 'enemy_3');
        $this->assertSame('rift_world_tree_drop', $first['skill_code']);
        // 戦闘で 1 回だけ。また倒れていても、2 回目は使わない（ターン表の怨恨の旋律に戻る＝ 2 ターン目は逆襲の旋律）
        $second = $this->chooseSkill($engine, $state, 'enemy_3');
        $this->assertSame('rift_counter_melody', $second['skill_code']);
        $this->assertTrue($state['units']['enemy_3']['once_used']['rift_world_tree_drop']);

        // 実際に唱えると、慈愛が HP 半分で戻る
        $engine2 = $this->engine();
        $state2 = $this->realState();
        $state2['units']['enemy_1']['hp'] = 0;
        $state2['units']['enemy_1']['alive'] = false;
        $result = $engine2->runEnemyTurnsUntilPlayer($state2);
        $this->assertTrue($result['state']['units']['enemy_1']['alive']);
        $this->assertSame((int) floor($result['state']['units']['enemy_1']['max_hp'] * config('battle.revive_hp_percent') / 100), $result['state']['units']['enemy_1']['hp']);
        $this->assertContains('revive', array_column($result['events'], 'type'));
    }

    public function test_the_music_mage_does_not_revive_when_nobody_has_fallen(): void
    {
        $engine = $this->engine();
        $state = $this->realState();
        $codes = [];
        for ($turn = 1; $turn <= 12; $turn++) {
            $codes[] = $this->chooseSkill($engine, $state, 'enemy_3')['skill_code'];
        }

        $this->assertNotContains('rift_world_tree_drop', $codes);
    }

    public function test_the_guide_mage_follows_its_turn_script_and_rarely_heals(): void
    {
        $texts = $this->enemyAnnouncesByRound('enemy_4', 12);

        foreach ([1, 6, 11] as $turn) {
            $this->assertSame('光導の魔法使いは悪魔の幻影群を唱えた！', $texts[$turn], "{$turn} ターン目");
        }
        $attacks = ['光導の魔法使いは水の刃を唱えた！', '光導の魔法使いは水の凶刃を唱えた！'];
        foreach ([2, 3, 4, 5, 7, 8, 9, 10, 12] as $turn) {
            // 誰も傷ついていないので、回復は使わない
            $this->assertContains($texts[$turn], $attacks, "{$turn} ターン目");
        }
    }

    public function test_the_guide_mage_covers_every_mage_with_twenty_percent_evasion(): void
    {
        $engine = $this->engine();
        $result = $engine->runEnemyTurnsUntilPlayer($this->realState()); // 1 ターン目: 悪魔の幻影群

        $method = new \ReflectionMethod(BattleEngine::class, 'effectiveEvasionRate');
        $method->setAccessible(true);
        foreach (['enemy_1', 'enemy_2', 'enemy_3', 'enemy_4'] as $id) {
            $unit = $result['state']['units'][$id];
            $this->assertArrayHasKey('evasion_set_20', $unit['buffs'], $id);
            $this->assertSame(20, $method->invoke($engine, $unit), "{$id} の回避率は 20% に固定");
        }
        $this->assertSame(1, $method->invoke($engine, array_merge($result['state']['units']['enemy_4'], ['buffs' => []])), '切れたら素の回避率');
    }

    public function test_healing_is_used_only_when_an_ally_is_below_seventy_percent(): void
    {
        $engine = $this->engine();
        $heals = ['rift_spirit_water', 'rift_spirit_rainbow'];

        // 全員満タン、または 70% 以上: 回復は選ばれない
        $state = $this->realState();
        $state['units']['enemy_1']['hp'] = (int) floor($state['units']['enemy_1']['max_hp'] * 0.8);
        $state['units']['enemy_4']['turns_taken'] = 1; // 幻影群の 1 ターン目は過ぎている
        for ($i = 0; $i < 200; $i++) {
            $copy = $state;
            $copy['units']['enemy_4']['turns_taken'] = 1;
            $this->assertNotContains($this->chooseSkill($engine, $copy, 'enemy_4')['skill_code'], $heals);
        }

        // 70% 未満の味方がいれば、回復も選ばれうる
        $state['units']['enemy_1']['hp'] = (int) floor($state['units']['enemy_1']['max_hp'] * 0.6);
        $picked = [];
        for ($i = 0; $i < 400; $i++) {
            $copy = $state;
            $copy['units']['enemy_4']['turns_taken'] = 1;
            $picked[] = $this->chooseSkill($engine, $copy, 'enemy_4')['skill_code'];
        }
        $healCount = count(array_filter($picked, fn (string $code) => in_array($code, $heals, true)));
        $this->assertGreaterThan(0, $healCount, '傷ついていれば回復もする');
        $this->assertLessThan(400 * 0.4, $healCount, '回復ばかりにならない');
    }

    public function test_ragaon_beat_lowers_only_the_target_when_a_mage_casts_it(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            enemyTargetPicker: static fn (array $units): string => 'pc1',
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
        $state = $this->realState();
        $spell = (new MasterDataProvider)->findSpell('rift_ragaon_beat');
        $method = new \ReflectionMethod(BattleEngine::class, 'applySpellEffect');
        $method->setAccessible(true);
        $args = [&$state, 'enemy_2', 'pc1', $spell, 'rift_ragaon_beat'];
        $method->invokeArgs($engine, $args);

        $this->assertSame(-3, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertSame(0, $this->stageSum($state['units']['enemy_2'], 'def'), '自分には掛からない');
    }

    // ---- 道具 ----

    /**
     * レベル35・大魔導の杖＆ローブのパーティーと、4 人の魔法使い（実際のマスターデータ）。
     * 行動の検証のために、全員のHPは大きくしてある（途中で倒れない）。
     *
     * @return array<string, mixed>
     */
    private function realState(bool $durable = true): array
    {
        $orchestrator = new BattleOrchestrator;
        $party = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $code) {
            $party[$code] = ['master_code' => $code, 'level' => 35, 'weapon' => 'staff_pro', 'armor' => 'robe_tuyosugi'];
        }
        $masters = new MasterDataProvider;
        $enemies = [];
        foreach (self::CODES as $i => $code) {
            $master = $masters->findEnemy($code, 3);
            $enemies[] = ['slot' => 'enemy_'.($i + 1), 'master_code' => $code, 'floor' => 3, 'name' => $master['name'], 'sprite' => $master['sprite']];
        }

        $state = $orchestrator->factory()->createScenario($party, $enemies, 'test');
        // 行動の検証用: 倒れないようにする（HP は最大 HP も一緒に大きくする）
        if ($durable) {
            foreach ($state['units'] as $id => $unit) {
                $state['units'][$id]['hp'] = $state['units'][$id]['max_hp'] = 9_999_999;
            }
        }

        return $state;
    }

    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => random_int(0, 99999)),
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * PC が全員こぶしで 1 回ずつ行動して、次の敵フェーズの手前まで進める。
     *
     * @param  array<string, mixed>  $state  敵フェーズが終わって、PC の入力を待っている状態
     * @return array<string, mixed>
     */
    private function roundOf(BattleEngine $engine, array $state): array
    {
        while (($state['awaiting_input'] ?? false) && $state['status'] === 'active') {
            $state = $engine->applyPlayerAction($state, $state['current_actor'], 'punch', null, 'enemy_1')['state'];
        }

        return $state;
    }

    /**
     * 指定したラウンド数だけ進めて、ラウンドごとのイベントと、その時点の state を返す。
     * 敵のターン数は、ラウンド数と同じ（全員が毎ラウンド 1 回動く）。
     *
     * @return array<int, array{events: list<array<string, mixed>>, state: array<string, mixed>}>
     */
    private function playRounds(BattleEngine $engine, array $state, int $rounds, ?callable $beforePlayers = null): array
    {
        $log = [];
        for ($round = 1; $round <= $rounds; $round++) {
            $result = $engine->runEnemyTurnsUntilPlayer($state);
            $state = $result['state'];
            $events = $result['events'];
            if ($beforePlayers !== null) {
                $state = $beforePlayers($round, $state);
            }
            while (($state['awaiting_input'] ?? false) && $state['status'] === 'active') {
                $step = $engine->applyPlayerAction($state, $state['current_actor'], 'punch', null, 'enemy_1');
                $state = $step['state'];
                $events = array_merge($events, $step['events']);
            }
            $log[$round] = ['events' => $events, 'state' => $state];
        }

        return $log;
    }

    /**
     * 満タンのパーティー（実際のHP）に、悪魔級殲滅魔法を 1 回ずつ撃ったあとの、HP の残りの割合。
     *
     * @return array<string, float>
     */
    private function evilSpellRemainingRatios(int $defenseStage): array
    {
        $engine = $this->engine();
        $state = $this->realState(durable: false);
        $spell = (new MasterDataProvider)->findSpell('rift_evil_infinity');
        $method = new \ReflectionMethod(BattleEngine::class, 'applySpellEffect');
        $method->setAccessible(true);

        $remaining = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = $state['units'][$id]['max_hp'];
            $state['units'][$id]['buffs'] = [];
            $state['units'][$id]['stages'] = $defenseStage === 0 ? [] : [['stat' => 'def', 'amount' => $defenseStage, 'turns' => 3, 'source' => 'test']];
            $args = [&$state, 'enemy_2', $id, $spell, 'rift_evil_infinity'];
            $method->invokeArgs($engine, $args);
            $remaining[$id] = $state['units'][$id]['hp'] / $state['units'][$id]['max_hp'];
        }

        return $remaining;
    }

    /**
     * 伝説の魔法使いを、指定のラウンドの敵フェーズのあとに倒して（実際のダメージ処理を通す）、その後 4 ラウンド進めたときの告知。
     *
     * @return list<string>
     */
    private function announceTextsWhenLegendIsDefeatedAfterRound(int $afterRound, bool $allowRevive): array
    {
        $engine = $this->engine();
        $state = $this->realState();
        if (! $allowRevive) {
            // 宇宙樹の雫は戦闘で 1 回だけ。使い切った扱いにして、蘇生させない
            $state['units']['enemy_3']['once_used']['rift_world_tree_drop'] = true;
        }
        $applyDamage = new \ReflectionMethod(BattleEngine::class, 'applyDamage');
        $applyDamage->setAccessible(true);

        $rounds = $this->playRounds($engine, $state, $afterRound + 4, function (int $round, array $state) use ($afterRound, $engine, $applyDamage): array {
            if ($round === $afterRound) {
                $state['units']['enemy_2']['life_ward'] = false;
                $args = [&$state, 'pc1', 'enemy_2', (int) $state['units']['enemy_2']['hp'], null, false];
                $applyDamage->invokeArgs($engine, $args);
                $this->assertFalse($state['units']['enemy_2']['alive']);
                $this->assertArrayNotHasKey('charge', $state['units']['enemy_2'], '倒れたら詠唱の状態は消える');
            }

            return $state;
        });

        $texts = [];
        foreach ($rounds as $round) {
            foreach ($round['events'] as $event) {
                if ($event['type'] === 'announce') {
                    $texts[] = $event['text'];
                }
            }
        }

        return $texts;
    }

    /**
     * その敵が各ラウンドに出した告知（最初の 1 つ）。
     *
     * @return array<int, string>
     */
    private function enemyAnnouncesByRound(string $enemyId, int $rounds): array
    {
        $texts = [];
        foreach ($this->playRounds($this->engine(), $this->realState(), $rounds) as $round => $data) {
            foreach ($data['events'] as $event) {
                if ($event['type'] === 'announce' && ($event['actor'] ?? null) === $enemyId) {
                    $texts[$round] = $event['text'];
                    break;
                }
            }
        }

        return $texts;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function eventsBy(array $events, string $actor, string $type, ?string $onlyType = null): array
    {
        return array_values(array_filter(
            $events,
            fn (array $e) => ($e['actor'] ?? null) === $actor && $e['type'] === ($onlyType ?? $type),
        ));
    }

    /**
     * 次に使う技を決める（実行はしない）。状態は更新される（ターンの数など）。
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function chooseSkill(BattleEngine $engine, array &$state, string $enemyId): array
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'chooseEnemySkill');
        $method->setAccessible(true);
        $args = [&$state, $enemyId];

        return $method->invokeArgs($engine, $args);
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function stageSum(array $unit, string $stat): int
    {
        $sum = 0;
        foreach ($unit['stages'] ?? [] as $entry) {
            if (($entry['stat'] ?? '') === $stat) {
                $sum += (int) $entry['amount'];
            }
        }

        return $sum;
    }
}
