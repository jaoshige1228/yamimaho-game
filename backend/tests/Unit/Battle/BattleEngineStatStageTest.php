<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

/**
 * 防御・攻撃のバフ／デバフは「段階（-3〜+3）」で管理する。
 * 重ねがけは合算、持続ターンは付与1回ごとに独立、バフとデバフは相殺、上限を超えた分は無駄になる。
 */
class BattleEngineStatStageTest extends TestCase
{
    use StageHelpers;

    private const STAGE_SPELLS = [
        'pc4_earth_armor', 'pc4_earth_wall', 'pc4_steel_wall',
        'pc2_vine_bind', 'pc2_burst_tree', 'pc2_great_burst_tree', 'pc3_ragaon_beat',
    ];

    public function test_stage_multipliers_follow_the_table(): void
    {
        $table = config('battle.stage.multipliers');

        $this->assertSame([1 => 1.2, 2 => 1.4, 3 => 1.7, -1 => 0.8, -2 => 0.65, -3 => 0.5], $table['def']);
        $this->assertSame([1 => 1.2, 2 => 1.4, 3 => 1.6, -1 => 0.8, -2 => 0.65, -3 => 0.5], $table['atk']);
        $this->assertSame(3, config('battle.stage.max'));
        $this->assertSame(3, config('battle.stage.turns'));

        // 実際の防御・攻撃の計算にも、この倍率が使われる（防御 100 / 筋力 100 / 魔力 100）
        foreach ([1, 2, 3, -1, -2, -3] as $stage) {
            $def = $this->effective($this->unit(['stages' => [$this->stageEntry('def', $stage)]]), 'def');
            $atk = $this->effective($this->unit(['stages' => [$this->stageEntry('atk', $stage)]]), 'str');
            $this->assertEqualsWithDelta(100 * $table['def'][$stage], $def, 0.0001, "防御 {$stage}");
            $this->assertEqualsWithDelta(100 * $table['atk'][$stage], $atk, 0.0001, "攻撃 {$stage}");
        }
    }

    public function test_defense_stage_applies_to_every_defense_stat_and_attack_stage_to_str_and_mag(): void
    {
        $def = $this->unit(['stages' => [$this->stageEntry('def', 2)]]);
        foreach (['def', 'pdef', 'mdef'] as $stat) {
            $this->assertEqualsWithDelta(140.0, $this->effective($def, $stat), 0.0001, $stat);
        }
        foreach (['str', 'mag', 'spd'] as $stat) {
            $this->assertEqualsWithDelta(100.0, $this->effective($def, $stat), 0.0001, "{$stat} は防御の段階では変わらない");
        }

        $atk = $this->unit(['stages' => [$this->stageEntry('atk', -2)]]);
        foreach (['str', 'mag'] as $stat) {
            $this->assertEqualsWithDelta(65.0, $this->effective($atk, $stat), 0.0001, $stat);
        }
        foreach (['def', 'pdef', 'mdef', 'spd'] as $stat) {
            $this->assertEqualsWithDelta(100.0, $this->effective($atk, $stat), 0.0001, "{$stat} は攻撃の段階では変わらない");
        }
    }

    public function test_stages_stack_and_each_cast_keeps_its_own_duration(): void
    {
        // ユーザーの例: 鋼の城壁（防御+2）→ 土の鎧（防御+1）で +3。先に鋼の城壁が切れて +1、最後に土の鎧が切れて 0
        $engine = $this->engine();
        $state = $this->state();

        $state = $this->cast($engine, $state, 'pc4', 'pc4_steel_wall')['state'];
        $this->assertSame(2, $this->stageSum($state['units']['pc1'], 'def'));
        $this->tick($engine, $state, 'pc1'); // サフィリアが行動（鋼の城壁の残り 3 → 2）

        $state = $this->cast($engine, $state, 'pc4', 'pc4_earth_armor', 'pc1')['state'];
        $this->assertSame(3, $this->stageSum($state['units']['pc1'], 'def'), '重ねがけで 3 段階上昇');
        $this->assertEqualsWithDelta(170.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);
        $this->tick($engine, $state, 'pc1'); // 鋼の城壁 2 → 1、土の鎧 3 → 2
        $this->assertSame(3, $this->stageSum($state['units']['pc1'], 'def'));

        $this->tick($engine, $state, 'pc1'); // 鋼の城壁が先に切れる
        $this->assertSame(1, $this->stageSum($state['units']['pc1'], 'def'), '鋼の城壁が切れて 1 段階上昇に戻る');
        $this->assertEqualsWithDelta(120.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);

        $this->tick($engine, $state, 'pc1'); // 土の鎧も切れる
        $this->assertSame(0, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertSame([], $state['units']['pc1']['stages']);
        $this->assertEqualsWithDelta(100.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);
    }

    public function test_the_visible_stage_is_capped_but_every_cast_keeps_its_full_amount(): void
    {
        // +2 のあとにもう +2 をかけると、見た目は +3 まで。でも、あとの効果は +2 のまま記録される
        $engine = $this->engine();
        $state = $this->state();

        $state = $this->cast($engine, $state, 'pc4', 'pc4_steel_wall')['state'];
        $this->tick($engine, $state, 'pc1');
        $second = $this->cast($engine, $state, 'pc4', 'pc4_steel_wall');
        $state = $second['state'];

        $this->assertEqualsWithDelta(170.0, $this->effective($state['units']['pc1'], 'def'), 0.0001, '見た目は +3 まで');
        $this->assertSame([2, 2], array_column($state['units']['pc1']['stages'], 'amount'), 'かけた量はそのまま残る');
        $event = collect($second['events'])->where('type', 'buff_applied')->firstWhere('target', 'pc1');
        $this->assertSame(['def', 2, 1], [$event['stat'], $event['delta'], $event['applied']], '見た目が変わった量は +1');

        // もう上限なので、さらにかけても見た目は変わらない（applied は 0）。でも効果は足されて、持続のあいだ上限を保つ
        $third = $this->cast($engine, $state, 'pc4', 'pc4_earth_armor', 'pc1');
        $state = $third['state'];
        $this->assertSame([2, 2, 1], array_column($state['units']['pc1']['stages'], 'amount'));
        $event = collect($third['events'])->where('type', 'buff_applied')->firstWhere('target', 'pc1');
        $this->assertSame([1, 0], [$event['delta'], $event['applied']]);

        // 先の +2 が切れても、あとからかけた +2 と +1 が残るので、+3 のまま（上限を超えた分は、あとの効果の持続のあいだ効く）
        $this->tick($engine, $state, 'pc1');
        $this->tick($engine, $state, 'pc1');
        $this->assertEqualsWithDelta(170.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);
        // 最後は、全部切れて元に戻る
        $this->tick($engine, $state, 'pc1');
        $this->assertEqualsWithDelta(100.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);
    }

    public function test_debuffs_are_capped_at_minus_three_too(): void
    {
        $engine = $this->engine();
        $state = $this->state();

        $state = $this->cast($engine, $state, 'pc3', 'pc3_ragaon_beat', 'enemy_1')['state'];
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['enemy_1'], 'def'), 0.0001);

        $state = $this->cast($engine, $state, 'pc2', 'pc2_great_burst_tree')['state'];
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['enemy_1'], 'def'), 0.0001, '-3 を超えては下がらない');
        $this->assertSame([-3, -2], array_column($state['units']['enemy_1']['stages'], 'amount'), 'かけた量はそのまま残る');
        $this->assertSame(-2, $this->stageSum($state['units']['enemy_2'], 'def'), '敵全体には 2 段階下がる');
        $this->assertEqualsWithDelta(65.0, $this->effective($state['units']['enemy_2'], 'def'), 0.0001);
    }

    /**
     * 防御が下がっている状態で、ラグオンビート（自分の防御も 3 段階下がる）を使っても、自分の防御はちゃんと 3 段階下がった状態になる。
     * 以前は、上限を超えた分を捨てて差分だけを記録していたため、先にかかっていた効果の最後の 1 ターンが切れると、
     * 新しい効果が薄まって、防御が変わらない（さらには上がる）ことがあった。
     * （持続がラウンドの終わりに全員いっしょに減るようになってからは、使った行動の終わりに古い効果が切れることはないが、
     * 古い効果が先に切れても -3 のままであることは、このあとの tick で確かめる）
     *
     * @return array<string, array{0: list<array{stat: string, amount: int, turns: int, source: string}>}>
     */
    public static function downStatesBeforeRagaon(): array
    {
        $down = static fn (int $amount, int $turns): array => ['stat' => 'def', 'amount' => $amount, 'turns' => $turns, 'source' => 'test'];

        return [
            '防御-1（残り3ターン）' => [[$down(-1, 3)]],
            '防御-1（残り1ターン。この行動の終わりに切れる）' => [[$down(-1, 1)]],
            '防御-2（残り1ターン）' => [[$down(-2, 1)]],
            '防御-1 が 2 つ（残り 1 ターンと 3 ターン）' => [[$down(-1, 1), $down(-1, 3)]],
            '防御-3（残り1ターン）' => [[$down(-3, 1)]],
        ];
    }

    /**
     * @param  list<array{stat: string, amount: int, turns: int, source: string}>  $existing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('downStatesBeforeRagaon')]
    public function test_ragaon_beat_always_leaves_the_caster_at_minus_three_defense(array $existing): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['pc3']['stages'] = $existing;

        $result = $this->cast($engine, $state, 'pc3', 'pc3_ragaon_beat', 'enemy_1');
        $state = $result['state'];

        // 使った直後に、自分も敵も -3 段階（防御 100 → 50）
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['pc3'], 'def'), 0.0001, '自分の防御');
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['enemy_1'], 'def'), 0.0001, '敵の防御');
        // 画面に出る段階も -3
        $this->assertSame(-3, $this->engine()->publicState($state)['units'][2]['stages']['def'] ?? null);

        // 新しくかけた効果は 3 ターン。ラウンドの終わりを 2 回迎えても（先にかかっていた効果が切れても）-3 のまま。3 回目で戻る
        $this->tick($engine, $state, 'pc3');
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['pc3'], 'def'), 0.0001, '1 回目のラウンドの終わり');
        $this->tick($engine, $state, 'pc3');
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['pc3'], 'def'), 0.0001, '2 回目のラウンドの終わり');
        $this->tick($engine, $state, 'pc3');
        $this->assertEqualsWithDelta(100.0, $this->effective($state['units']['pc3'], 'def'), 0.0001, '3 回目のラウンドの終わり');
    }

    public function test_a_new_cast_keeps_the_cap_for_its_whole_duration_even_if_an_older_effect_expires_first(): void
    {
        // 敵味方どちらも同じ: 防御+1（残り 1 ターン）のところに +3 をかける → +3。先の +1 が切れても、+3 のまま
        $engine = $this->engine();
        $state = $this->state();
        foreach (['pc1', 'enemy_1'] as $id) {
            $state['units'][$id]['stages'] = [$this->stageEntry('def', 1, 1)];
            $this->applyBuff($engine, $state, $id, 'def_up_3');
            $this->assertEqualsWithDelta(170.0, $this->effective($state['units'][$id], 'def'), 0.0001, "{$id} は +3");

            $this->tick($engine, $state, $id); // 先の +1 が切れる。+3 は残り 2 ターン
            $this->assertEqualsWithDelta(170.0, $this->effective($state['units'][$id], 'def'), 0.0001, "{$id} は +3 のまま");
        }

        // デバフも同じ: 防御-1（残り 1 ターン）のところに -3
        $state['units']['pc2']['stages'] = [$this->stageEntry('def', -1, 1)];
        $this->applyBuff($engine, $state, 'pc2', 'def_down_3');
        $this->tick($engine, $state, 'pc2');
        $this->assertEqualsWithDelta(50.0, $this->effective($state['units']['pc2'], 'def'), 0.0001);
    }

    public function test_buffs_and_debuffs_add_up(): void
    {
        // 鋼の城壁で +2 のところにジャミング（-1）で +1。さらに大爆樹（-2）で -1
        $engine = $this->engine();
        $state = $this->state();
        $state = $this->cast($engine, $state, 'pc4', 'pc4_steel_wall')['state'];
        $this->assertSame(2, $this->stageSum($state['units']['pc1'], 'def'));

        $this->applyBuff($engine, $state, 'pc1', 'def_down_1'); // ジャミング
        $this->assertSame(1, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertEqualsWithDelta(120.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);

        $this->applyBuff($engine, $state, 'pc1', 'def_down_2'); // 大爆樹
        $this->assertSame(-1, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertEqualsWithDelta(80.0, $this->effective($state['units']['pc1'], 'def'), 0.0001, '防御 1 段階減少');
    }

    public function test_a_debuff_that_runs_out_first_gives_the_buff_back(): void
    {
        // 古い +2 のあとに -1（合計 +1）。持続は独立しているので、-1 が先に切れることもある
        $engine = $this->engine();
        $state = $this->state();
        $this->applyBuff($engine, $state, 'pc1', 'def_up_2');
        $this->tick($engine, $state, 'pc1');
        $this->tick($engine, $state, 'pc1'); // +2 の残り 1
        $this->applyBuff($engine, $state, 'pc1', 'def_down_1'); // -1（残り 3）→ 合計 +1
        $this->assertSame(1, $this->stageSum($state['units']['pc1'], 'def'));

        $this->tick($engine, $state, 'pc1'); // +2 が切れて -1 だけが残る
        $this->assertSame(-1, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertEqualsWithDelta(80.0, $this->effective($state['units']['pc1'], 'def'), 0.0001);
    }

    public function test_attack_and_defense_stages_are_independent(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $this->applyBuff($engine, $state, 'pc1', 'atk_up_3');
        $this->applyBuff($engine, $state, 'pc1', 'def_down_2');

        $this->assertSame(3, $this->stageSum($state['units']['pc1'], 'atk'));
        $this->assertSame(-2, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertEqualsWithDelta(160.0, $this->effective($state['units']['pc1'], 'str'), 0.0001);
        $this->assertEqualsWithDelta(160.0, $this->effective($state['units']['pc1'], 'mag'), 0.0001);
        $this->assertEqualsWithDelta(65.0, $this->effective($state['units']['pc1'], 'pdef'), 0.0001);
    }

    public function test_ragaon_beat_lowers_target_and_caster_by_three_stages(): void
    {
        $result = $this->cast($this->engine(), $this->state(), 'pc3', 'pc3_ragaon_beat', 'enemy_1');
        $units = $result['state']['units'];

        $this->assertSame(-3, $this->stageSum($units['enemy_1'], 'def'));
        $this->assertSame(-3, $this->stageSum($units['pc3'], 'def'));
        $this->assertSame(0, $this->stageSum($units['enemy_2'], 'def'));
        $this->assertSame(0, $this->stageSum($units['pc1'], 'def'));
        $this->assertEqualsWithDelta(50.0, $this->effective($units['enemy_1'], 'def'), 0.0001);
    }

    public function test_a_critical_hit_ignores_the_defenders_stages(): void
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamageWithCritical');
        $method->setAccessible(true);
        $defender = $this->unit(['def' => 20, 'pdef' => 20, 'side' => 'enemy', 'stages' => [$this->stageEntry('def', 3)]]);

        // 通常: 防御 20 × 1.7 = 34 → 40 − 17 = 23
        $normal = new BattleEngine(factory: new BattleFactory, damageRoll: static fn (): float => 1.0, critRoll: static fn (): int => 100);
        $result = $method->invoke($normal, $this->unit(['str' => 40, 'crit_rate' => 0]), $defender, 'str', 1.0);
        $this->assertFalse($result['critical']);
        $this->assertSame(23, $result['damage']);

        // クリティカル: 防御の段階（×1.7）は無視される。防御は素の 20 のまま（40 − 20 / 2 = 30）
        $crit = new BattleEngine(factory: new BattleFactory, damageRoll: static fn (): float => 1.0, critRoll: static fn (): int => 1);
        $result = $method->invoke($crit, $this->unit(['str' => 40, 'crit_rate' => 100]), $defender, 'str', 1.0);
        $this->assertTrue($result['critical']);
        $this->assertSame(30, $result['damage']);
    }

    public function test_old_buff_keys_and_units_without_stages_do_not_break_anything(): void
    {
        $engine = $this->engine();

        // 古い戦闘に残った未定義のキー（def_up など）は無視される。stages が無いユニットでも計算できる
        $legacy = ['str' => 10, 'mag' => 20, 'def' => 50, 'buffs' => ['def_up' => 3, 'atk_up_familiar' => 2]];
        $this->assertEqualsWithDelta(50.0, $this->effective($legacy, 'def'), 0.0001);
        $this->assertEqualsWithDelta(10.0, $this->effective($legacy, 'str'), 0.0001);

        $state = $this->state();
        $state['units']['pc1']['buffs'] = ['def_up' => 3, 'evasion_set' => 3];
        $public = $engine->publicState($state);
        $pc1 = collect($public['units'])->firstWhere('id', 'pc1');
        $this->assertSame(['evasion_set' => 3], $pc1['buffs'], '回避以外の buffs は出さない');
        $this->assertSame(['def' => 0, 'atk' => 0], $pc1['stages']);
    }

    public function test_public_state_shows_the_clamped_stage_of_each_unit(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['pc1']['stages'] = [$this->stageEntry('def', 2), $this->stageEntry('def', 2)]; // 合計 +4 → 表示は +3
        $state['units']['pc2']['stages'] = [$this->stageEntry('atk', -2)];
        $state['units']['enemy_1']['stages'] = [$this->stageEntry('def', -3), $this->stageEntry('atk', 1)];

        $units = collect($engine->publicState($state)['units'])->keyBy('id');
        $this->assertSame(['def' => 3, 'atk' => 0], $units['pc1']['stages']);
        $this->assertSame(['def' => 0, 'atk' => -2], $units['pc2']['stages']);
        $this->assertSame(['def' => -3, 'atk' => 1], $units['enemy_1']['stages']);
        $this->assertSame(['def' => 0, 'atk' => 0], $units['pc3']['stages']);
    }

    public function test_buff_applied_events_carry_the_stat_and_stage(): void
    {
        $result = $this->cast($this->engine(), $this->state(), 'pc4', 'pc4_earth_armor', 'pc1');
        $event = collect($result['events'])->firstWhere('type', 'buff_applied');

        $this->assertSame(['pc4', 'pc1', 'def_up_1', 'def', 1, 1], [
            $event['actor'], $event['target'], $event['buff'], $event['stat'], $event['delta'], $event['applied'],
        ]);

        // 回避は従来どおり（段階の項目は付かない）
        $state = $this->state();
        $state['units']['pc1']['spells'] = ['pc1_water_veil'];
        $evasion = collect($this->cast($this->engine(), $state, 'pc1', 'pc1_water_veil', 'pc2')['events'])->firstWhere('type', 'buff_applied');
        $this->assertSame(['evasion_set', 'evasion'], [$evasion['buff'], $evasion['icon']]);
        $this->assertArrayNotHasKey('delta', $evasion);
    }

    public function test_the_defense_stage_changes_the_damage_taken(): void
    {
        $engine = new BattleEngine(factory: new BattleFactory, damageRoll: static fn (): float => 1.0, hitRoll: static fn (): int => 100, critRoll: static fn (): int => 100);
        $method = new \ReflectionMethod(BattleEngine::class, 'calcDamage');
        $method->setAccessible(true);
        $attacker = $this->unit(['str' => 200]);

        $damage = fn (int $stage) => $method->invoke(
            $engine,
            $attacker,
            $this->unit(['stages' => $stage === 0 ? [] : [$this->stageEntry('def', $stage)]]),
            'str',
            1.0,
        );

        // 防御 100: 段階なし 200−50=150、+3(×1.7) 200−85=115、-3(×0.5) 200−25=175
        $this->assertSame(150, $damage(0));
        $this->assertSame(115, $damage(3));
        $this->assertSame(175, $damage(-3));
    }

    // ---- 道具 ----

    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unit(array $overrides = []): array
    {
        return array_merge([
            'side' => 'ally',
            'str' => 100,
            'mag' => 100,
            'def' => 100,
            'pdef' => 100,
            'mdef' => 100,
            'spd' => 100,
            'crit_rate' => 0,
            'buffs' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function effective(array $unit, string $stat): float
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'effectiveStat');
        $method->setAccessible(true);

        return $method->invoke($this->engine(), $unit, $stat);
    }

    /** ラウンドの終わりの、そのユニット 1 体ぶんの持続ターンの経過（エンジンは、全員の行動が一周したときに全員ぶん呼ぶ）。 */
    private function tick(BattleEngine $engine, array &$state, string $unitId): void
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'tickBuffsForUnit');
        $method->setAccessible(true);
        $args = [&$state['units'][$unitId]];
        $method->invokeArgs($engine, $args);
    }

    /** 魔法の効果を介さず、buff_key の効果をそのまま付ける（敵のジャミングなどの代わり）。 */
    private function applyBuff(BattleEngine $engine, array &$state, string $unitId, string $buffKey): void
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'applyBuffToUnit');
        $method->setAccessible(true);
        $args = [&$state['units'][$unitId], $unitId, 'enemy_1', $buffKey, 'test'];
        $method->invokeArgs($engine, $args);
    }

    /**
     * 魔法を唱える（手番と入力待ちは毎回その人に戻す）。
     *
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function cast(BattleEngine $engine, array $state, string $actor, string $spell, ?string $target = null): array
    {
        $state['status'] = 'active';
        $state['current_actor'] = $actor;
        $state['awaiting_input'] = true;

        return $engine->applyPlayerAction($state, $actor, 'spell', $spell, $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $ally = static fn (string $id, string $name, int $spd) => [
            'id' => $id,
            'name' => $name,
            'sprite' => 'test',
            'side' => 'ally',
            'hp' => 500,
            'max_hp' => 500,
            'mp' => 200,
            'max_mp' => 200,
            'str' => 100,
            'mag' => 100,
            'def' => 100,
            'pdef' => 100,
            'mdef' => 100,
            'spd' => $spd,
            'crit_rate' => 0,
            'evasion_rate' => 0,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'stages' => [],
            'statuses' => [],
            'spells' => [...self::STAGE_SPELLS, 'pc1_water_veil'],
        ];
        $enemy = static fn (string $id, string $name) => [
            'id' => $id,
            'name' => $name,
            'sprite' => 'test',
            'side' => 'enemy',
            'hp' => 5000,
            'max_hp' => 5000,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 100,
            'mag' => 100,
            'def' => 100,
            'pdef' => 100,
            'mdef' => 100,
            'spd' => 1,
            'crit_rate' => 0,
            'evasion_rate' => 0,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'stages' => [],
            'statuses' => [],
            'spells' => [],
            'master_code' => 'bat',
        ];

        $units = [
            'pc1' => $ally('pc1', 'サフィリア', 10),
            'pc2' => $ally('pc2', 'ガルデ', 9),
            'pc3' => $ally('pc3', 'グレン', 8),
            'pc4' => $ally('pc4', 'シエナ', 7),
            'enemy_1' => $enemy('enemy_1', '敵A'),
            'enemy_2' => $enemy('enemy_2', '敵B'),
        ];

        return [
            'status' => 'active',
            'current_actor' => 'pc4',
            'awaiting_input' => true,
            'turn_order' => array_keys($units),
            'turn_index' => 0,
            'units' => $units,
            'log' => [],
        ];
    }
}
