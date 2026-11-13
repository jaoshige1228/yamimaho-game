<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\EnemySkillPicker;
use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

/**
 * 3層の敵（バニッシャー・クリーナー・リーパー・クラッシャー・ゼロ・ナビ）の技と魔法。
 */
class BattleEngineFloor3EnemiesTest extends TestCase
{
    use StageHelpers;

    public function test_regular_enemy_skills_are_defined(): void
    {
        $masters = new MasterDataProvider;
        $skills = fn (string $code) => collect($masters->enemySkillsFor($code))->keyBy('skill_code');

        // バニッシャー: 通常攻撃と、強力な単体攻撃（2層ボスのドラゴンクロウ 1.5 より強い）
        $bunisher = $skills('bunisher');
        $this->assertSame(['attack', 'banish_blow'], $bunisher->keys()->all());
        $this->assertSame(['physical_single', 'ally_single'], [$bunisher['banish_blow']['action_type'], $bunisher['banish_blow']['target_type']]);
        $this->assertGreaterThan(1.5, $bunisher['banish_blow']['coefficient']);

        // クリーナー: 7 割の確率でデリート（即死の全体魔法）
        $cleaner = $skills('cleaner');
        $this->assertSame(70, $cleaner['cleaner_delete']['weight']);
        $this->assertSame(100, array_sum($cleaner->pluck('weight')->all()));
        $this->assertSame('spell', $cleaner['cleaner_delete']['action_type']);

        // リーパー: 少し高めの全体攻撃（通常の全体攻撃 0.9 より高い）
        $leaper = $skills('leaper');
        $this->assertSame(['physical_all', 'ally_all'], [$leaper['reap_gale']['action_type'], $leaper['reap_gale']['target_type']]);
        $this->assertGreaterThan(0.9, $leaper['reap_gale']['coefficient']);
        $this->assertLessThan(1.3, $leaper['reap_gale']['coefficient'], '「少し」高め');

        // クラッシャー: 平均的な全体攻撃（0.9）と単体攻撃（1.5）
        $crusher = $skills('crusher');
        $this->assertSame([0.9, 'physical_all'], [$crusher['crush_wave']['coefficient'], $crusher['crush_wave']['action_type']]);
        $this->assertSame([1.5, 'physical_single'], [$crusher['crush_fist']['coefficient'], $crusher['crush_fist']['action_type']]);
    }

    public function test_boss_skills_are_defined(): void
    {
        $masters = new MasterDataProvider;

        // ゼロ: 単体攻撃 2 つ、全体攻撃 1 つ、2 回行動する単体攻撃 1 つ（通常攻撃はしない）
        $zero = collect($masters->enemySkillsFor('zero'));
        $this->assertSame(
            ['physical_single', 'physical_single', 'physical_all', 'physical_double'],
            $zero->pluck('action_type')->all(),
        );
        $this->assertNotContains('attack', $zero->pluck('skill_code')->all());

        // ナビ: 攻撃技は一切なく、回復・防御バフ・防御デバフの魔法だけ
        $navi = collect($masters->enemySkillsFor('navi'));
        $this->assertSame(['navi_repair', 'navi_protect', 'navi_jamming'], $navi->pluck('skill_code')->all());
        $this->assertSame(['spell'], $navi->pluck('action_type')->unique()->values()->all());
    }

    public function test_enemy_only_spells_are_defined(): void
    {
        $masters = new MasterDataProvider;

        // デリート: 敵（プレイヤー側）全員に、1 人ずつ 4% の即死
        $delete = $masters->findSpell('cleaner_delete');
        $this->assertSame(['enemy_all', 'instant_death', 4], [$delete['target_type'], $delete['effect'], (int) $delete['power']]);

        // ナビの魔法は、2 層の邪教徒より控えめ（回復量少なめ・倍率低め）
        $repair = $masters->findSpell('navi_repair');
        $this->assertSame(['ally_single', 'heal_mag'], [$repair['target_type'], $repair['effect']]);
        $this->assertLessThan($masters->findSpell('cult_heal')['coefficient'], (float) $repair['coefficient']);

        $protect = $masters->findSpell('navi_protect');
        $this->assertSame(['ally_all', 'buff_def', 'def_up_1'], [$protect['target_type'], $protect['effect'], $protect['buff']]);
        $this->assertStringContainsString('味方全員の防御力を1段階上げる', $protect['description']);

        $jamming = $masters->findSpell('navi_jamming');
        $this->assertSame(['enemy_all', 'debuff_def', 'def_down_1'], [$jamming['target_type'], $jamming['effect'], $jamming['buff']]);
        $this->assertStringContainsString('敵全体の防御力を1段階下げる', $jamming['description']);
    }

    public function test_stat_profiles_follow_the_design(): void
    {
        $masters = new MasterDataProvider;
        $stat = fn (string $code, string $key) => (int) $masters->findEnemy($code, 3)[$key];

        // クリーナー: 素早さ高め、HP・防御・攻撃は低め（バニッシャー＝平均 と比べて）
        $this->assertGreaterThan($stat('bunisher', 'spd'), $stat('cleaner', 'spd'));
        foreach (['hp', 'def', 'str'] as $key) {
            $this->assertLessThan($stat('bunisher', $key), $stat('cleaner', $key), "クリーナーの {$key}");
        }

        // リーパー: 素早さと攻撃が高く、HP と防御は低め
        $this->assertGreaterThan($stat('bunisher', 'spd'), $stat('leaper', 'spd'));
        $this->assertGreaterThan($stat('bunisher', 'str'), $stat('leaper', 'str'));
        $this->assertLessThan($stat('bunisher', 'hp'), $stat('leaper', 'hp'));
        $this->assertLessThan($stat('bunisher', 'def'), $stat('leaper', 'def'));

        // クラッシャー: 素早さが低く、攻撃・HP・防御が高い
        $this->assertLessThan($stat('bunisher', 'spd'), $stat('crusher', 'spd'));
        foreach (['str', 'hp', 'def'] as $key) {
            $this->assertGreaterThan($stat('bunisher', $key), $stat('crusher', $key), "クラッシャーの {$key}");
        }

        // ゼロ: 全てのステータスが高い（3 層の通常の敵のどれより上）
        foreach (['hp', 'str', 'mag', 'def', 'spd'] as $key) {
            foreach (['bunisher', 'cleaner', 'leaper', 'crusher'] as $code) {
                $this->assertGreaterThan($stat($code, $key), $stat('zero', $key), "ゼロの {$key} > {$code}");
            }
        }
    }

    public function test_double_strike_hits_two_different_allies(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $result = $this->engine('zero_double')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_2'));

            $damages = $this->eventsOfType($result['events'], 'damage');
            $this->assertCount(2, $damages);
            $this->assertNotSame($damages[0]['target'], $damages[1]['target'], '同じ相手は選ばれない');
            // (300 - 40 / 2) × 1.3 = 364
            $this->assertSame([364, 364], array_column($damages, 'value'));
        }

        $announce = collect($this->engine('zero_double')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_2'))['events'])
            ->firstWhere('type', 'announce');
        $this->assertSame('ゼロの二連零撃！', $announce['text']);
    }

    public function test_double_strike_uses_both_hits_on_the_last_survivor(): void
    {
        $state = $this->bossState(actor: 'enemy_2');
        foreach (['pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['alive'] = false;
            $state['units'][$id]['hp'] = 0;
        }

        $result = $this->engine('zero_double')->runEnemyTurnsUntilPlayer($state);

        $damages = $this->eventsOfType($result['events'], 'damage');
        $this->assertSame(['pc1', 'pc1'], array_column($damages, 'target'));
        $this->assertSame(1000 - 728, $result['state']['units']['pc1']['hp']);
    }

    public function test_double_strike_stops_when_the_last_survivor_falls_on_the_first_hit(): void
    {
        $state = $this->bossState(actor: 'enemy_2');
        foreach (['pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['alive'] = false;
            $state['units'][$id]['hp'] = 0;
        }
        $state['units']['pc1']['hp'] = 100;

        $result = $this->engine('zero_double')->runEnemyTurnsUntilPlayer($state);

        $this->assertCount(1, $this->eventsOfType($result['events'], 'damage'), '倒れた相手には 2 回目を打たない');
        $this->assertSame('defeat', $result['state']['status']);
    }

    public function test_double_strike_with_two_survivors_hits_each_once_even_if_the_first_falls(): void
    {
        $state = $this->bossState(actor: 'enemy_2');
        foreach (['pc3', 'pc4'] as $id) {
            $state['units'][$id]['alive'] = false;
            $state['units'][$id]['hp'] = 0;
        }
        $state['units']['pc1']['hp'] = 100;
        $state['units']['pc2']['hp'] = 100;

        $result = $this->engine('zero_double')->runEnemyTurnsUntilPlayer($state);

        $damages = $this->eventsOfType($result['events'], 'damage');
        $this->assertEqualsCanonicalizing(['pc1', 'pc2'], array_column($damages, 'target'));
        $this->assertSame('defeat', $result['state']['status']);
    }

    public function test_zero_single_and_all_attacks(): void
    {
        $single = $this->engine('zero_smash')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_2'));
        $this->assertCount(1, $this->eventsOfType($single['events'], 'damage'));

        $all = $this->engine('zero_wave')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_2'));
        $this->assertEqualsCanonicalizing(['pc1', 'pc2', 'pc3', 'pc4'], array_column($this->eventsOfType($all['events'], 'damage'), 'target'));
    }

    public function test_delete_rolls_instant_death_separately_for_every_player(): void
    {
        // 1 人目だけ当たり、残りは外れ（1 人ごとに別の抽選）。敵の魔法は先に回避を判定するので、1 人あたり [回避の抽選, 即死の抽選] の 2 つを使う
        $rolls = [100, 1, 100, 100, 100, 100, 100, 100];
        $engine = $this->engine('cleaner_delete', hitRolls: $rolls);

        $result = $engine->runEnemyTurnsUntilPlayer($this->cleanerState());

        $damages = $this->eventsOfType($result['events'], 'damage');
        $this->assertCount(1, $damages);
        $this->assertTrue($damages[0]['instant_death']);
        $this->assertSame(1, count(array_filter($result['state']['units'], fn ($u) => $u['side'] === 'ally' && ! $u['alive'])));
        $this->assertCount(3, $this->eventsOfType($result['events'], 'spell_failed'));
        $this->assertSame('クリーナーはデリートを唱えた！', collect($result['events'])->firstWhere('type', 'announce')['text']);
    }

    public function test_delete_can_miss_everyone_or_hit_everyone(): void
    {
        $miss = $this->engine('cleaner_delete', hitRolls: [100])->runEnemyTurnsUntilPlayer($this->cleanerState());
        $this->assertSame([], $this->eventsOfType($miss['events'], 'damage'));
        $this->assertSame(4, count(array_filter($miss['state']['units'], fn ($u) => $u['side'] === 'ally' && $u['alive'])));

        // 4% ちょうどは当たり、5% は外れ（回避の抽選は毎回外れ）
        $edge = $this->engine('cleaner_delete', hitRolls: [100, 4, 100, 5, 100, 4, 100, 5])->runEnemyTurnsUntilPlayer($this->cleanerState());
        $this->assertCount(2, $this->eventsOfType($edge['events'], 'damage'));
    }

    public function test_navi_never_attacks_even_when_nobody_is_wounded(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $result = (new BattleEngine(
                damageRoll: static fn (): float => 1.0,
                hitRoll: static fn (): int => 100,
                critRoll: static fn (): int => 100,
            ))->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));

            $this->assertSame([], $this->eventsOfType($result['events'], 'damage'), 'ナビはダメージを与えない');
            $this->assertSame([], $this->eventsOfType($result['events'], 'miss'));
            $announce = collect($result['events'])->firstWhere('type', 'announce')['text'];
            $this->assertStringNotContainsString('攻撃', $announce);
            $seen[$announce] = true;
        }

        // 誰も傷ついていないので回復は使われず、防御バフと防御デバフだけ
        $this->assertEqualsCanonicalizing(
            ['ナビ Aはプロテクトを唱えた！', 'ナビ Aはジャミングを唱えた！'],
            array_keys($seen),
        );
    }

    public function test_navi_picks_another_support_when_its_heal_would_whiff(): void
    {
        $result = $this->engine('navi_repair')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));

        $this->assertSame([], $this->eventsOfType($result['events'], 'damage'));
        $this->assertSame([], $this->eventsOfType($result['events'], 'heal'));
        $this->assertNotEmpty($this->eventsOfType($result['events'], 'buff_applied'), '空振りさせず、バフかデバフに差し替える');
    }

    public function test_navi_heals_the_most_wounded_ally_a_little(): void
    {
        $state = $this->bossState(actor: 'enemy_1');
        $state['units']['enemy_2']['hp'] = 1500; // 1500/2000 = 75%
        $state['units']['enemy_3']['hp'] = 100;  // 100/200 = 50%（いちばん傷ついている）

        $result = $this->engine('navi_repair')->runEnemyTurnsUntilPlayer($state);

        $heals = $this->eventsOfType($result['events'], 'heal');
        $this->assertCount(1, $heals);
        $this->assertSame('enemy_3', $heals[0]['target']);
        // 魔力 150 × 1.0 = 150（不足ぶん 100 まで）
        $this->assertSame(100, $heals[0]['value']);

        $state['units']['enemy_3']['hp'] = 200;
        $result = $this->engine('navi_repair')->runEnemyTurnsUntilPlayer($state);
        $this->assertSame(150, $this->eventsOfType($result['events'], 'heal')[0]['value'], '回復量は魔力×1.0');
    }

    public function test_navi_protect_buffs_every_enemy_unit_and_jamming_debuffs_every_player(): void
    {
        $protect = $this->engine('navi_protect')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));
        $buffed = $this->eventsOfType($protect['events'], 'buff_applied');
        $this->assertEqualsCanonicalizing(['enemy_1', 'enemy_2', 'enemy_3'], array_column($buffed, 'target'));
        $this->assertSame(['def_up_1', 'def_up_1', 'def_up_1'], array_column($buffed, 'buff'));
        foreach (['enemy_1', 'enemy_2', 'enemy_3'] as $id) {
            $this->assertSame(1, $this->stageSum($protect['state']['units'][$id], 'def'), $id);
        }
        $this->assertSame(0, $this->stageSum($protect['state']['units']['pc1'], 'def'));

        $jamming = $this->engine('navi_jamming')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));
        $debuffed = $this->eventsOfType($jamming['events'], 'buff_applied');
        $this->assertEqualsCanonicalizing(['pc1', 'pc2', 'pc3', 'pc4'], array_column($debuffed, 'target'));
        $this->assertSame(['def_down_1', 'def_down_1', 'def_down_1', 'def_down_1'], array_column($debuffed, 'buff'));
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $this->assertSame(-1, $this->stageSum($jamming['state']['units'][$id], 'def'), $id);
        }
        $this->assertSame(0, $this->stageSum($jamming['state']['units']['enemy_1'], 'def'));
    }

    /**
     * 敵の種類ごとに決まったスキルを必ず引くエンジン（実際のスキル内容は CSV から読む）。
     *
     * @param  list<int>|null  $hitRolls  即死などの抽選結果を順に返す（足りなくなったら最後の値）
     */
    private function engine(string $skillCode, ?array $hitRolls = null): BattleEngine
    {
        $masters = new MasterDataProvider;
        $picker = new class($masters, $skillCode) extends EnemySkillPicker
        {
            public function __construct(private readonly MasterDataProvider $data, private readonly string $code)
            {
                parent::__construct($data);
            }

            public function pick(string $enemyCode): array
            {
                $skill = collect($this->data->enemySkillsFor($enemyCode))->firstWhere('skill_code', $this->code);

                return [
                    'skill_code' => $skill['skill_code'],
                    'label' => $skill['label'],
                    'action_type' => $skill['action_type'],
                    'target_type' => $skill['target_type'],
                    'coefficient' => (float) $skill['coefficient'],
                ];
            }
        };

        $rolls = $hitRolls ?? [100];

        return new BattleEngine(
            enemySkillPicker: $picker,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return count($rolls) > 1 ? array_shift($rolls) : $rolls[0];
            },
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function eventsOfType(array $events, string $type): array
    {
        return array_values(array_filter($events, fn (array $e) => $e['type'] === $type));
    }

    /**
     * クリーナー（enemy_1）と味方 4 人。
     *
     * @return array<string, mixed>
     */
    private function cleanerState(): array
    {
        $state = $this->bossState(actor: 'enemy_1');
        $state['units']['enemy_1']['name'] = 'クリーナー';
        $state['units']['enemy_1']['master_code'] = 'cleaner';

        return $state;
    }

    /**
     * ナビ A（enemy_1）・ゼロ（enemy_2）・ナビ B（enemy_3）と味方 4 人。全員 HP 満タン。
     *
     * @return array<string, mixed>
     */
    private function bossState(string $actor): array
    {
        return [
            'status' => 'active',
            'current_actor' => $actor,
            'awaiting_input' => false,
            'turn_order' => [$actor, 'pc1', 'pc2', 'pc3', 'pc4'],
            'turn_index' => 0,
            'units' => [
                'enemy_1' => $this->enemy('enemy_1', 'ナビ A', 'navi', hp: 200, str: 100, mag: 150),
                'enemy_2' => $this->enemy('enemy_2', 'ゼロ', 'zero', hp: 2000, str: 300, mag: 100),
                'enemy_3' => $this->enemy('enemy_3', 'ナビ B', 'navi', hp: 200, str: 100, mag: 150),
                'pc1' => $this->ally('pc1'),
                'pc2' => $this->ally('pc2'),
                'pc3' => $this->ally('pc3'),
                'pc4' => $this->ally('pc4'),
            ],
            'log' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enemy(string $id, string $name, string $code, int $hp, int $str, int $mag): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'side' => 'enemy',
            'master_code' => $code,
            'sprite' => '3_'.$code,
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 0,
            'max_mp' => 0,
            'str' => $str,
            'mag' => $mag,
            'pdef' => 70,
            'mdef' => 70,
            'def' => 70,
            'spd' => 50,
            'gold_reward' => 0,
            'exp_reward' => 0,
            'crit_rate' => 1,
            'evasion_rate' => 1,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ally(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'side' => 'ally',
            'sprite' => $id,
            'hp' => 1000,
            'max_hp' => 1000,
            'mp' => 10,
            'max_mp' => 10,
            'str' => 10,
            'mag' => 10,
            'def' => 40,
            'spd' => 10,
            'crit_rate' => 5,
            'evasion_rate' => 5,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
    }
}
