<?php

namespace Tests\Feature;

use App\Models\DungeonEventNode;
use App\Services\Dungeon\Exploration\DungeonExplorationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 成功・失敗が判明するとき（= 成功・失敗の効果音が鳴るとき）、テキストの 1 行目に
 * 「ダイスロールのログ」（例: 1d100<=55 ＞ 70 ＞ 失敗）を必ず出す。
 */
class DungeonExplorationDiceLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        $this->postJson('/api/dungeon/enter')->assertOk();
    }

    public function test_failed_roll_shows_the_dice_log_in_the_first_line(): void
    {
        [$sessionId, $rate] = $this->startTrapArrow();
        config(['game.dungeon.test_roll' => 100]);

        $response = $this->continueSession($sessionId);

        $response->assertJsonPath('segment.stat_check_result', 'fail');
        $response->assertJsonPath('segment.lines.0.dice.text', "1d100<={$rate} ＞ 100 ＞ 失敗");
        $response->assertJsonPath('segment.lines.0.dice.result', 'fail');
        $response->assertJsonPath('segment.lines.0.dice.rate', $rate);
        $response->assertJsonPath('segment.lines.0.dice.roll', 100);
    }

    public function test_successful_roll_shows_the_dice_log_in_the_first_line(): void
    {
        [$sessionId, $rate] = $this->startTrapArrow();
        config(['game.dungeon.test_roll' => 1]);

        $response = $this->continueSession($sessionId);

        $response->assertJsonPath('segment.stat_check_result', 'success');
        $response->assertJsonPath('segment.lines.0.dice.text', "1d100<={$rate} ＞ 1 ＞ 成功");
        $response->assertJsonPath('segment.lines.0.dice.result', 'success');
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function boundaryRolls(): array
    {
        return [
            '出目が成功率ちょうど = 成功' => [0, '成功'],
            '出目が成功率 +1 = 失敗' => [1, '失敗'],
        ];
    }

    #[DataProvider('boundaryRolls')]
    public function test_a_roll_equal_to_the_rate_succeeds_and_one_above_fails(int $delta, string $label): void
    {
        [$sessionId, $rate] = $this->startTrapArrow();
        $this->assertLessThan(100, $rate);
        $roll = $rate + $delta;
        config(['game.dungeon.test_roll' => $roll]);

        $response = $this->continueSession($sessionId);

        $response->assertJsonPath('segment.lines.0.dice.text', "1d100<={$rate} ＞ {$roll} ＞ {$label}");
    }

    public function test_the_log_is_added_to_the_text_without_changing_it_and_only_once(): void
    {
        [$sessionId] = $this->startTrapArrow();
        config(['game.dungeon.test_roll' => 1]);

        $response = $this->continueSession($sessionId);

        $lines = $response->json('segment.lines');
        // 本文はそのまま（ログは別のフィールド）。ログは 1 行目にだけ付く
        $this->assertStringContainsString('避ける', $lines[0]['text']);
        $this->assertStringNotContainsString('1d100', $lines[0]['text']);
        $this->assertSame(1, collect($lines)->filter(fn (array $line) => isset($line['dice']))->count());
    }

    public function test_a_forced_test_result_still_gives_a_consistent_log(): void
    {
        foreach ([[true, '成功'], [false, '失敗']] as [$forced, $label]) {
            [$sessionId, $rate] = $this->startTrapArrow();
            config(['game.dungeon.test_stat_success' => $forced]);

            $response = $this->continueSession($sessionId);

            $dice = $response->json('segment.lines.0.dice');
            $this->assertSame($forced ? 'success' : 'fail', $dice['result']);
            $this->assertSame($forced, $dice['roll'] <= $dice['rate'], '出目と成功率が、結果と矛盾しない');
            $this->assertSame("1d100<={$dice['rate']} ＞ {$dice['roll']} ＞ {$label}", $dice['text']);
            $this->assertSame($rate, $dice['rate']);
            config(['game.dungeon.test_stat_success' => null]);
        }
    }

    public function test_a_normal_roll_is_a_real_d100_roll_that_matches_the_result(): void
    {
        for ($i = 0; $i < 30; $i++) {
            [$sessionId, $rate] = $this->startTrapArrow();

            $response = $this->continueSession($sessionId);

            $dice = $response->json('segment.lines.0.dice');
            $this->assertGreaterThanOrEqual(1, $dice['roll']);
            $this->assertLessThanOrEqual(100, $dice['roll']);
            $this->assertSame($rate, $dice['rate']);
            $this->assertSame($dice['roll'] <= $rate ? 'success' : 'fail', $dice['result']);
            $this->assertSame($dice['result'], $response->json('segment.stat_check_result'));
        }
    }

    public function test_dialogue_first_line_also_carries_the_log(): void
    {
        // スフィンクスの問題は、判定の直後がセリフ
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'sphinx_quiz',
            'game.dungeon.test_roll' => 1,
            'game.dungeon.test_stat_success' => true,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');

        $found = null;
        $step = $start;
        for ($i = 0; $i < 30 && $found === null; $i++) {
            if ($step->json('segment.kind') === 'choice') {
                $options = $step->json('segment.options');
                $step = $this->postJson('/api/dungeon/exploration/choose', [
                    'session_id' => $sessionId,
                    'slot_id' => $options[0]['id'] ?? $options[0]['slot_id'] ?? 'pc1',
                ])->assertOk();
            } else {
                $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
            }
            if ($step->json('segment.stat_check_result') !== null) {
                $found = $step;
            }
            if ($step->json('segment.kind') === 'complete') {
                break;
            }
        }

        $this->assertNotNull($found, '判定の結果に到達すること');
        $first = $found->json('segment.lines.0');
        $this->assertSame('dialogue', $first['type']);
        $this->assertSame('success', $first['dice']['result']);
        $this->assertMatchesRegularExpression('/^1d100<=\d+ ＞ \d+ ＞ 成功$/u', $first['dice']['text']);
    }

    public function test_each_ally_check_shows_a_log_on_every_result_line(): void
    {
        foreach ([[1, 'success', '成功'], [100, 'fail', '失敗']] as [$roll, $result, $label]) {
            $this->postJson('/api/dungeon/enter')->assertOk();
            config([
                'game.dungeon.test_force' => 'exploration',
                'game.dungeon.test_event_code' => 'flash_flood',
                'game.dungeon.test_roll' => $roll,
            ]);

            $start = $this->postJson('/api/dungeon/advance')->assertOk();
            $sessionId = $start->json('session_id');

            $withDice = [];
            $step = $start;
            for ($i = 0; $i < 20; $i++) {
                foreach ($step->json('segment.lines') ?? [] as $line) {
                    if (isset($line['dice'])) {
                        $withDice[] = $line['dice'];
                    }
                }
                if ($step->json('segment.kind') === 'complete') {
                    break;
                }
                $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
            }

            $this->assertCount(4, $withDice, "生存している 4 人それぞれの結果に 1 つずつ（{$label}）");
            foreach ($withDice as $dice) {
                $this->assertSame($result, $dice['result']);
                $this->assertSame("1d100<={$dice['rate']} ＞ {$roll} ＞ {$label}", $dice['text']);
            }
        }
    }

    public function test_the_log_goes_on_the_first_visible_line_and_never_on_a_fade_only_line(): void
    {
        $engine = app(DungeonExplorationEngine::class);
        $dice = ['rate' => 55, 'roll' => 70, 'success' => false];

        // 暗転だけの行（文が空）は飛ばして、その次の文に付ける
        $segment = $this->withResult($engine, [
            'kind' => 'story',
            'lines' => [
                ['type' => 'narration', 'text' => '', 'sfx' => 'screen_fade', 'screen_fade_ms' => 1000],
                ['type' => 'narration', 'text' => 'うっ！', 'sfx' => null],
            ],
        ], $dice);
        $this->assertArrayNotHasKey('dice', $segment['lines'][0]);
        $this->assertSame('1d100<=55 ＞ 70 ＞ 失敗', $segment['lines'][1]['dice']['text']);
        $this->assertSame('fail', $segment['stat_check_result']);

        // 表示される文がなければ、ログだけの行を作る（見えないまま流れない）
        $segment = $this->withResult($engine, ['kind' => 'story', 'lines' => []], $dice);
        $this->assertCount(1, $segment['lines']);
        $this->assertSame('', $segment['lines'][0]['text']);
        $this->assertSame('1d100<=55 ＞ 70 ＞ 失敗', $segment['lines'][0]['dice']['text']);

        // 戦闘などの文を持たない結果には、効果音のための結果だけを付ける（行は作らない）
        $segment = $this->withResult($engine, ['kind' => 'battle', 'boss' => true], ['rate' => 20, 'roll' => 1, 'success' => true]);
        $this->assertArrayNotHasKey('lines', $segment);
        $this->assertSame('success', $segment['stat_check_result']);
    }

    public function test_every_roll_in_the_events_is_followed_by_a_line_that_can_carry_the_log(): void
    {
        // 判定の直後に表示される文がないイベントがあると、ログを出す場所がなくなる。イベントを足したときの見張り
        $nodes = DungeonEventNode::query()->get()->keyBy(fn (DungeonEventNode $n) => $n->event_code.'/'.$n->node_key);

        $firstVisibleTypes = function (string $eventCode, ?string $key, int $depth = 0) use (&$firstVisibleTypes, $nodes): array {
            if ($key === null || $key === '' || $depth > 12) {
                return ['(なし)'];
            }
            $node = $nodes->get($eventCode.'/'.$key);
            if ($node === null) {
                return ['(未定義)'];
            }

            $hasText = trim((string) $node->text) !== ''
                || collect(['dialogue_pc1', 'dialogue_pc2', 'dialogue_pc3', 'dialogue_pc4'])
                    ->contains(fn (string $col) => trim((string) $node->{$col}) !== '');

            return match ($node->node_type) {
                'narration', 'dialogue' => $hasText ? [$node->node_type] : $firstVisibleTypes($eventCode, $node->next_default, $depth + 1),
                'stat_check_each_ally' => ['narration'],
                'chance_branch' => array_merge(
                    $firstVisibleTypes($eventCode, $node->next_on_success ?: $node->next_default, $depth + 1),
                    $firstVisibleTypes($eventCode, $node->next_on_fail ?: $node->next_default, $depth + 1),
                ),
                'check_party_level' => array_merge(
                    $firstVisibleTypes($eventCode, $node->next_on_success, $depth + 1),
                    $firstVisibleTypes($eventCode, $node->next_on_fail, $depth + 1),
                ),
                'grant_item', 'grant_gold', 'apply_damage', 'apply_damage_chosen', 'apply_damage_party', 'restore_party', 'assign_rescuer'
                    => $firstVisibleTypes($eventCode, $node->next_default, $depth + 1),
                default => [$node->node_type],
            };
        };

        $checked = 0;
        foreach ($nodes as $node) {
            if ($node->node_type !== 'stat_check') {
                continue;
            }
            foreach (['next_on_success' => '成功', 'next_on_fail' => '失敗'] as $column => $label) {
                $target = $node->{$column} ?: $node->next_default;
                foreach ($firstVisibleTypes($node->event_code, $target) as $type) {
                    $this->assertContains(
                        $type,
                        ['narration', 'dialogue'],
                        "{$node->event_code}/{$node->node_key} の{$label}の直後に表示される文がない（{$type}）",
                    );
                }
            }
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    /**
     * 罠の矢のイベントを始めて、判定の手前（成功率が出ている）で止まる。
     *
     * @return array{0: string, 1: int} セッション ID と、プレビューに出ている成功率
     */
    private function startTrapArrow(): array
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'trap_arrow',
            'game.dungeon.test_roll' => null,
            'game.dungeon.test_stat_success' => null,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $preview = collect($start->json('segment.lines'))
            ->first(fn (array $line) => str_contains((string) ($line['text'] ?? ''), '成功率：'));
        $this->assertNotNull($preview, '成功率が表示されること');
        $this->assertSame(1, preg_match('/成功率：(\d+)%/u', $preview['text'], $m));

        return [(string) $start->json('session_id'), (int) $m[1]];
    }

    private function continueSession(string $sessionId): TestResponse
    {
        return $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $segment
     * @param  array{rate: int, roll: int, success: bool}  $dice
     * @return array<string, mixed>
     */
    private function withResult(DungeonExplorationEngine $engine, array $segment, array $dice): array
    {
        $method = new ReflectionMethod($engine, 'segmentWithStatCheckResult');

        return $method->invoke($engine, $segment, $dice);
    }

    protected function tearDown(): void
    {
        config([
            'game.dungeon.test_force' => null,
            'game.dungeon.test_event_code' => null,
            'game.dungeon.test_roll' => null,
            'game.dungeon.test_stat_success' => null,
        ]);

        parent::tearDown();
    }
}
