<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\EnemySkillPicker;
use Tests\TestCase;

/**
 * バフ・デバフの持続は、ラウンドの終わり（全員の行動が一周した直後）に、全員いっしょに 1 ずつ減る（2026-11-14）。
 *
 * 以前は「そのユニット自身の行動のあとに 1 減る」だったので、掛けた本人は実質 2 ターンになり、
 * 同じ魔法でも掛けられた人の並び順によって切れる時刻（効く敵の行動の回数）がばらばらだった。
 * 「3ターン」は、掛けたラウンドを 1 ターン目と数える。切れたときは `buff_expired` を出し、画面はそれでバッジを消す。
 */
class BattleEngineBuffRoundTest extends TestCase
{
    use StageHelpers;

    /** 行動の順番: 味方 4 人と敵 2 人が混ざる（掛けた人より前に動く味方・後に動く味方・敵の前後の味方がいる） */
    private const ORDER = ['pc1', 'pc2', 'enemy_1', 'pc3', 'pc4', 'enemy_2'];

    public function test_effects_tick_only_at_the_end_of_the_round_for_everyone_at_once(): void
    {
        $engine = $this->engine();
        $state = $this->state();

        // 1 ラウンド目の頭（先頭のサフィリア）が、味方全体に鋼の城壁（防御 +2・3 ターン）
        $state = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc4_steel_wall')['state'];
        $this->assertSame([3, 3, 3, 3], $this->turnsOf($state), '唱えた本人も、ほかの味方と同じ 3 ターン');

        // ガルデの行動のあと・敵の行動のあと・グレンの行動のあと…どの行動のあとでも減らない
        $state = $this->playerThenEnemies($engine, $state, 'pc2')['state'];
        $this->assertSame([3, 3, 3, 3], $this->turnsOf($state), '行動のたびには減らない');
        $state = $this->playerThenEnemies($engine, $state, 'pc3')['state'];
        $this->assertSame([3, 3, 3, 3], $this->turnsOf($state));

        // 最後のシエナの行動と、そのあとの敵（最後に動く）で、1 ラウンドが終わる → 全員いっしょに 1 減る
        $result = $this->playerThenEnemies($engine, $state, 'pc4');
        $this->assertSame([2, 2, 2, 2], $this->turnsOf($result['state']), 'ラウンドの終わりに全員いっしょに減る');
        $this->assertSame([], $this->eventsOf($result['events'], 'buff_expired'), 'まだ切れていない');
    }

    public function test_a_three_turn_buff_covers_exactly_three_rounds_and_expires_for_everyone_together(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc4_steel_wall')['state'];

        $defenseAtEachEnemyAction = [];
        $expired = [];
        for ($round = 1; $round <= 3; $round++) {
            foreach (['pc2', 'pc3', 'pc4'] as $actor) {
                $result = $this->playerThenEnemies($engine, $state, $actor);
                $state = $result['state'];
                $expired = array_merge($expired, $this->eventsOf($result['events'], 'buff_expired'));
            }
            $defenseAtEachEnemyAction[$round] = $this->defenseStages($state);
            if ($round < 3) {
                // 次のラウンドの先頭（サフィリア）。何もしない（こぶし）
                $state = $engine->applyPlayerAction($state, 'pc1', 'punch', null, 'enemy_1')['state'];
            }
        }

        // 3 ラウンド目の終わりに、4 人とも同時に切れる（掛けた本人も同じ）。ラウンドの終わりに 1 回ずつ、4 人ぶんの buff_expired
        $this->assertSame(['pc1', 'pc2', 'pc3', 'pc4'], array_column($expired, 'target'));
        foreach ($expired as $event) {
            $this->assertSame(['def' => 0, 'atk' => 0], $event['stages'], $event['target']);
            $this->assertSame([], $event['buffs']);
        }
        $this->assertSame(['pc1' => 0, 'pc2' => 0, 'pc3' => 0, 'pc4' => 0], $defenseAtEachEnemyAction[3]);
        $this->assertSame([[], [], [], []], array_map(fn ($u) => $u['stages'], array_values(array_filter($state['units'], fn ($u) => $u['side'] === 'ally'))));
    }

    public function test_buffs_and_debuffs_on_enemies_tick_the_same_way(): void
    {
        $engine = $this->engine();
        $state = $this->state();

        // サフィリアが敵 1 体の防御を 1 段階下げる（ツルで縛る）。敵 1 は、このラウンドの途中で行動するが、そのあとも減らない
        $state = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc2_vine_bind', 'enemy_1')['state'];
        $state = $this->playerThenEnemies($engine, $state, 'pc2')['state'];
        $this->assertSame(3, $state['units']['enemy_1']['stages'][0]['turns'], '敵が行動しても減らない');

        $state = $this->playerThenEnemies($engine, $state, 'pc3')['state'];
        $state = $this->playerThenEnemies($engine, $state, 'pc4')['state'];
        $this->assertSame(2, $state['units']['enemy_1']['stages'][0]['turns'], 'ラウンドの終わりに減る');
    }

    public function test_evasion_buffs_tick_with_the_round_too(): void
    {
        $engine = $this->engine();
        $state = $this->state();

        $state = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_veil', 'pc1')['state'];
        $this->assertSame(['evasion_set' => 3], $state['units']['pc1']['buffs'], '自分に掛けても 3 ターン');

        foreach (['pc2', 'pc3', 'pc4'] as $actor) {
            $result = $this->playerThenEnemies($engine, $state, $actor);
            $state = $result['state'];
        }
        $this->assertSame(['evasion_set' => 2], $state['units']['pc1']['buffs']);
    }

    public function test_buff_applied_carries_the_state_after_the_cast_so_the_screen_does_not_have_to_add_up(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        // 敵はすでに防御 -2 段階。そこにラグオンビート（-3）→ 見た目は -3 で頭打ち（applied は -1）
        $state['units']['enemy_1']['stages'] = [$this->stageEntry('def', -2)];

        $result = $engine->applyPlayerAction($state, 'pc1', 'spell', 'pc3_ragaon_beat', 'enemy_1');
        $applied = $this->eventsOf($result['events'], 'buff_applied');

        // 敵へ（防御 -2 のところに -3 → 見た目 -3）と、自分へ（ラグオンビートの自分への防御ダウン → -3）
        $this->assertSame(['enemy_1', 'pc1'], array_column($applied, 'target'));
        $this->assertSame(-1, $applied[0]['applied']);
        $this->assertSame(['def' => -3, 'atk' => 0], $applied[0]['stages']);
        $this->assertSame([], $applied[0]['buffs']);
        $this->assertSame(['def' => -3, 'atk' => 0], $applied[1]['stages']);

        // 回避の効果は、残りターンつきで出る
        $result = $engine->applyPlayerAction($this->state(), 'pc1', 'spell', 'pc1_water_veil', 'pc2');
        $event = $this->eventsOf($result['events'], 'buff_applied')[0];
        $this->assertSame('pc2', $event['target']);
        $this->assertSame(['evasion_set' => 3], $event['buffs']);
        $this->assertSame(['def' => 0, 'atk' => 0], $event['stages']);
    }

    public function test_buff_expired_lists_what_is_left_and_is_only_sent_for_units_that_lost_something(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        // サフィリア: 防御 +1（残り 1）と防御 +2（残り 3）→ 切れるのは +1 だけで、+2 が残る
        $state['units']['pc1']['stages'] = [$this->stageEntry('def', 1, 1), $this->stageEntry('def', 2, 3)];
        // ガルデ: 攻撃 +1（残り 2）→ 減るだけで、切れない（イベントなし）
        $state['units']['pc2']['stages'] = [$this->stageEntry('atk', 1, 2)];
        // グレン: 回避（残り 1）→ 切れる
        $state['units']['pc3']['buffs'] = ['evasion_set' => 1];
        // シエナは倒れている（効果は付いていないし、イベントも出ない）
        $state['units']['pc4']['alive'] = false;
        $state['units']['pc4']['hp'] = 0;

        // ラウンドの最後の行動（敵 2）で 1 ラウンドが終わる
        $state['current_actor'] = 'enemy_2';
        $state['awaiting_input'] = false;
        $result = $engine->runEnemyTurnsUntilPlayer($state);
        $expired = collect($this->eventsOf($result['events'], 'buff_expired'))->keyBy('target');

        $this->assertSame(['pc1', 'pc3'], $expired->keys()->all());
        $this->assertSame(['def' => 2, 'atk' => 0], $expired['pc1']['stages']);
        $this->assertSame([], $expired['pc3']['buffs']);
        $this->assertSame(1, $result['state']['units']['pc2']['stages'][0]['turns'], '切れなくても 1 減る');
    }

    public function test_the_familiar_buff_given_at_the_round_end_is_not_ticked_the_same_moment(): void
    {
        // 使い魔の支援は、ラウンドの終わりの減らしのあと（次のラウンドの頭の扱い）。付けたばかりの攻撃バフは 3 ターンのまま
        $engine = new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: $this->attackOnlyPicker(),
            enemyTargetPicker: static fn (): string => 'pc1',
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
            // 発動の抽選（1〜100）は必ず当たり、行動の選択は「選択肢の最後」（= 味方全体の攻撃バフ）になるようにする
            familiarRoll: static fn (int $min, int $max): int => $max === 100 ? $min : $max,
        );
        $state = $this->state();
        $state['meta'] = ['familiar' => ['level' => 8]];

        $events = [];
        foreach (['pc2' => 'pc2', 'pc3' => 'pc3', 'pc4' => 'pc4'] as $actor) {
            // 1 ラウンド目の頭（サフィリア）は、何もしない
            if ($actor === 'pc2') {
                $state = $engine->applyPlayerAction($state, 'pc1', 'punch', null, 'enemy_1')['state'];
            }
            $result = $this->playerThenEnemies($engine, $state, $actor);
            $state = $result['state'];
            $events = array_merge($events, $result['events']);
        }

        $familiarBuffs = array_values(array_filter($this->eventsOf($events, 'buff_applied'), fn ($e) => $e['actor'] === 'familiar'));
        $this->assertCount(4, $familiarBuffs, '味方全員（4 人）に攻撃バフ');
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $entry = $state['units'][$id]['stages'][0];
            $this->assertSame(BattleEngine::FAMILIAR_SOURCE, $entry['source']);
            $this->assertSame(3, $entry['turns'], "{$id} の使い魔のバフは 3 ターンのまま");
        }
    }

    /**
     * 味方 1 人が、敵に「こぶし」。そのあと、敵のターンを次の味方の入力待ちまで進める。
     *
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function playerThenEnemies(BattleEngine $engine, array $state, string $actor): array
    {
        $target = $state['units']['enemy_1']['alive'] ? 'enemy_1' : 'enemy_2';
        $player = $engine->applyPlayerAction($state, $actor, 'punch', null, $target);
        $enemies = $engine->runEnemyTurnsUntilPlayer($player['state']);

        return ['state' => $enemies['state'], 'events' => array_merge($player['events'], $enemies['events'])];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function eventsOf(array $events, string $type): array
    {
        return array_values(array_filter($events, fn ($e) => $e['type'] === $type));
    }

    /**
     * 味方 4 人の、ガルデ以外も含めた防御の段階の合計。
     *
     * @return array<string, int>
     */
    private function defenseStages(array $state): array
    {
        $out = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $out[$id] = $this->stageSum($state['units'][$id], 'def');
        }

        return $out;
    }

    /**
     * 味方 4 人（pc1〜pc4）の、先頭の防御の効果の残りターン。
     *
     * @return list<int>
     */
    private function turnsOf(array $state): array
    {
        return array_map(fn (string $id) => (int) ($state['units'][$id]['stages'][0]['turns'] ?? 0), ['pc1', 'pc2', 'pc3', 'pc4']);
    }

    private function attackOnlyPicker(): EnemySkillPicker
    {
        return new class extends EnemySkillPicker
        {
            public function __construct() {}

            public function pick(string $enemyCode): array
            {
                return ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0];
            }
        };
    }

    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: $this->attackOnlyPicker(),
            enemyTargetPicker: static fn (): string => 'pc1',
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $ally = static fn (string $id, string $name, int $spd) => [
            'id' => $id, 'name' => $name, 'sprite' => 'test', 'side' => 'ally',
            'hp' => 5000, 'max_hp' => 5000, 'mp' => 500, 'max_mp' => 500,
            'str' => 100, 'mag' => 100, 'def' => 100, 'pdef' => 100, 'mdef' => 100, 'spd' => $spd,
            'crit_rate' => 0, 'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true,
            'buffs' => [], 'stages' => [], 'statuses' => [],
            'spells' => ['pc4_steel_wall', 'pc2_vine_bind', 'pc3_ragaon_beat', 'pc1_water_veil'],
        ];
        $enemy = static fn (string $id, string $name, int $spd) => [
            'id' => $id, 'name' => $name, 'sprite' => 'test', 'side' => 'enemy',
            'hp' => 90000, 'max_hp' => 90000, 'mp' => 0, 'max_mp' => 0,
            'str' => 100, 'mag' => 100, 'def' => 100, 'pdef' => 100, 'mdef' => 100, 'spd' => $spd,
            'crit_rate' => 0, 'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true,
            'buffs' => [], 'stages' => [], 'statuses' => [], 'spells' => [], 'master_code' => 'bat',
        ];

        $units = [
            'pc1' => $ally('pc1', 'サフィリア', 60),
            'pc2' => $ally('pc2', 'ガルデ', 50),
            'enemy_1' => $enemy('enemy_1', '敵A', 40),
            'pc3' => $ally('pc3', 'グレン', 30),
            'pc4' => $ally('pc4', 'シエナ', 20),
            'enemy_2' => $enemy('enemy_2', '敵B', 10),
        ];

        return [
            'status' => 'active',
            'current_actor' => 'pc1',
            'awaiting_input' => true,
            'turn_order' => self::ORDER,
            'turn_index' => 0,
            'units' => $units,
            'log' => [],
        ];
    }
}
