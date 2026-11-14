<?php

namespace Tests\Feature;

use App\Models\DungeonEventNode;
use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use App\Services\Growth\LevelGrowthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 探索イベントが、通信のやり直し・再読込・古い画面からの操作で壊れないこと、
 * ダメージでパーティーが全滅したら探索が続かず全滅の終わり方になること（2026-11-15 の公開前点検）。
 */
class DungeonExplorationRobustnessTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> イベントを始めた応答（advance）に出ていた行の文面 */
    private array $startTexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        $this->postJson('/api/dungeon/enter')->assertOk();
        config(['game.dungeon.test_force' => 'exploration']);
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

    // ---- 選択肢（choose）の再送・不正な選択 ----

    public function test_choosing_again_after_the_choice_was_made_does_not_break_the_session(): void
    {
        $sessionId = $this->startEvent('treasure_chest', statSuccess: true);

        $this->choose($sessionId, 'pc1')->assertOk();
        $nodeAfterFirst = $this->currentNode($sessionId);
        $this->assertNotSame('choose', $nodeAfterFirst, '1 回目で、選択肢の先に進んでいる');

        // 応答が届かなかった・古い画面から押された、などの再送
        $again = $this->choose($sessionId, 'pc1')->assertOk();
        $again->assertJsonPath('segment.kind', 'story')->assertJsonPath('segment.lines', []);

        // 探索の状態はそのまま（存在しないノードに飛ばない・やり直しにもならない）
        $this->assertSame($nodeAfterFirst, $this->currentNode($sessionId));
        $this->assertNodeExists('treasure_chest', $this->currentNode($sessionId));

        // 空の場面を受けた画面は「続き」を送る。そこから最後まで進められる
        $this->finish($sessionId);
        $this->assertSame(0, DungeonExplorationSession::query()->count(), 'イベントは終わり、セッションも残らない');
    }

    public function test_choosing_when_no_choice_is_shown_does_not_move_the_session(): void
    {
        $sessionId = $this->startEvent('gold_pickup', statSuccess: true);
        $node = $this->currentNode($sessionId);

        $this->choose($sessionId, 'pc1')->assertOk()->assertJsonPath('segment.lines', []);

        $this->assertSame($node, $this->currentNode($sessionId));
        $this->assertNodeExists('gold_pickup', $this->currentNode($sessionId));
        $this->finish($sessionId);
    }

    public function test_a_choice_that_was_not_offered_is_refused_and_the_session_stays_at_the_choice(): void
    {
        $sessionId = $this->startEvent('treasure_chest', statSuccess: true);

        // 存在しないスロット／「スキップ」の無い選択肢でのスキップ
        $this->choose($sessionId, 'pc9')->assertStatus(404);
        $this->choose($sessionId, 'skip')->assertStatus(404);
        $this->assertSame('choose', $this->currentNode($sessionId));

        // 戦闘不能の味方は選択肢に出ないので、選べない
        UserCharacter::query()->where('user_id', $this->demoUser()->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc2'))
            ->update(['hp' => 0]);
        $this->choose($sessionId, 'pc2')->assertStatus(404);
        $this->assertSame('choose', $this->currentNode($sessionId));

        // 出ている選択肢（生きている味方）は、今までどおり選べる
        $this->choose($sessionId, 'pc1')->assertOk();
        $this->assertNotSame('choose', $this->currentNode($sessionId));
    }

    public function test_a_yes_no_choice_only_accepts_yes_or_no(): void
    {
        $sessionId = $this->startEvent('mysterious_presence', statSuccess: true);
        $this->advanceTo($sessionId, 'choose');

        $this->choose($sessionId, 'pc1')->assertStatus(404);
        $this->assertSame('choose', $this->currentNode($sessionId));

        $this->choose($sessionId, 'no')->assertOk();
        $this->assertNotSame('choose', $this->currentNode($sessionId));
    }

    // ---- 選択肢を出している最中の「続き」（再読込） ----

    public function test_continuing_while_a_choice_is_shown_shows_the_same_choice_again_instead_of_losing_the_event(): void
    {
        $sessionId = $this->startEvent('treasure_chest', statSuccess: true);
        $this->assertSame('choose', $this->currentNode($sessionId));

        // 再読込すると、画面は探索の「続き」を自動で送る
        $reloaded = $this->continueEvent($sessionId)->assertOk();
        $reloaded->assertJsonPath('segment.kind', 'choice');
        $this->assertCount(4, $reloaded->json('segment.options'));
        $this->assertSame(1, DungeonExplorationSession::query()->count(), 'セッションは消えない');
        $this->assertSame('choose', $this->currentNode($sessionId));

        // そのまま選べて、宝箱を最後まで開けられる
        $this->choose($sessionId, 'pc1')->assertOk();
        $this->finish($sessionId);
    }

    public function test_continuing_while_a_yes_no_choice_is_shown_shows_the_same_choice_again(): void
    {
        $sessionId = $this->startEvent('mysterious_presence', statSuccess: true);
        $this->advanceTo($sessionId, 'choose');

        $reloaded = $this->continueEvent($sessionId)->assertOk();
        $reloaded->assertJsonPath('segment.kind', 'choice');
        $this->assertSame(['yes', 'no'], array_column($reloaded->json('segment.options'), 'slot_id'));
        $this->assertSame('choose', $this->currentNode($sessionId));
    }

    // ---- ダメージで全員が戦闘不能になったら ----

    public function test_when_the_damage_knocks_out_the_whole_party_the_scene_is_shown_and_then_the_run_ends(): void
    {
        $this->setPartyHp(1);
        $user = $this->demoUser();
        UserDungeonProgress::query()->where('user_id', $user->id)->update(['step' => 7]);

        $sessionId = $this->startEvent('flash_flood', statSuccess: false);
        $texts = $this->collectTexts($sessionId, $final);

        // ダメージの場面は最後まで見せて、最後の一行は「全滅した……」
        $this->assertContains('全滅した……', $texts);
        $this->assertSame('全滅した……', end($texts));
        $this->assertSame('game_over', $final->json('segment.kind'), '全滅の終わり方に進む');

        // 全滅の終わり方（戦闘に負けたときと同じ）: 深さ・層のリセットと全回復、ダンジョンの外へ
        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int) $progress->step);
        $this->assertSame(1, (int) $progress->floor);
        $this->assertFalse((bool) $progress->in_dungeon);
        $this->assertSame(0, DungeonExplorationSession::query()->count());
        $this->assertTrue($this->partyIsAtFullHp(), 'パーティーは全回復している');
        $this->assertIsArray($final->json('party'));
    }

    public function test_the_last_survivor_falling_into_a_pitfall_ends_the_run(): void
    {
        // 生きているのはサフィリアだけ。落とし穴（99999）に落ちたら、全員が戦闘不能になる
        $this->setPartyHp(0);
        UserCharacter::query()->where('user_id', $this->demoUser()->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->update(['hp' => 200]);

        $sessionId = $this->startEvent('pitfall', statSuccess: false);
        $texts = $this->collectTexts($sessionId, $final);

        $this->assertSame('全滅した……', end($texts));
        $this->assertSame('game_over', $final->json('segment.kind'));
        $this->assertTrue($this->partyIsAtFullHp());
    }

    public function test_reloading_during_the_wipe_scene_still_ends_the_run(): void
    {
        $this->setPartyHp(1);
        $sessionId = $this->startEvent('flash_flood', statSuccess: false);

        // 場面の途中で再読込（セッションは残っているので、画面は「続き」を送る）。何度送っても、全滅の終わり方になる
        $this->collectTexts($sessionId, $final, stopAfterWipeScene: true);
        $this->assertSame(1, DungeonExplorationSession::query()->count());

        $this->continueEvent($sessionId)->assertOk()->assertJsonPath('segment.kind', 'game_over');
        $this->assertSame(0, DungeonExplorationSession::query()->count());
        $this->assertTrue($this->partyIsAtFullHp());
    }

    public function test_damage_that_leaves_someone_standing_does_not_end_the_run(): void
    {
        // HP が満タンなら、鉄砲水（1 層は 1 人 100 ダメージ）で全員が倒れることはない
        $sessionId = $this->startEvent('flash_flood', statSuccess: false);
        $texts = $this->collectTexts($sessionId, $final);

        $this->assertNotContains('全滅した……', $texts);
        $this->assertSame('complete', $final->json('segment.kind'));
        $this->assertTrue(UserDungeonProgress::query()->where('user_id', $this->demoUser()->id)->value('in_dungeon') == 1, 'ダンジョンの中のまま');
    }

    // ---- 補助 ----

    private function startEvent(string $eventCode, bool $statSuccess): string
    {
        config([
            'game.dungeon.test_event_code' => $eventCode,
            'game.dungeon.test_stat_success' => $statSuccess,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $this->startTexts = array_map(fn (array $line) => (string) ($line['text'] ?? ''), $start->json('segment.lines') ?? []);

        return (string) $start->json('session_id');
    }

    private function choose(string $sessionId, string $slotId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/dungeon/exploration/choose', ['session_id' => $sessionId, 'slot_id' => $slotId]);
    }

    private function continueEvent(string $sessionId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId]);
    }

    private function currentNode(string $sessionId): ?string
    {
        return DungeonExplorationSession::query()->findOrFail($sessionId)->current_node_key;
    }

    private function assertNodeExists(string $eventCode, ?string $nodeKey): void
    {
        $this->assertNotNull($nodeKey);
        $this->assertTrue(
            DungeonEventNode::query()->where('event_code', $eventCode)->where('node_key', $nodeKey)->exists(),
            "{$eventCode} に存在しないノード {$nodeKey} に進んでいる",
        );
    }

    /** 選択肢以外の場面を「続き」で送って、指定のノードに来るまで進める（選択肢は出さない） */
    private function advanceTo(string $sessionId, string $nodeKey): void
    {
        for ($i = 0; $i < 30 && $this->currentNode($sessionId) !== $nodeKey; $i++) {
            $this->continueEvent($sessionId)->assertOk();
        }
        $this->assertSame($nodeKey, $this->currentNode($sessionId));
    }

    /** 最後まで「続き」を送って、イベントを終わらせる（選択肢が出たら、先頭の味方を選ぶ） */
    private function finish(string $sessionId): void
    {
        for ($i = 0; $i < 60; $i++) {
            if (DungeonExplorationSession::query()->whereKey($sessionId)->doesntExist()) {
                return;
            }
            $this->continueEvent($sessionId)->assertOk();
        }
        $this->fail('イベントが終わらない');
    }

    /**
     * 終わりまで進めながら、表示された行の文面を集める（選択肢は出ない前提）。
     *
     * @param  mixed  $final  最後の応答（TestResponse）が入る
     * @return list<string>
     */
    private function collectTexts(string $sessionId, &$final, bool $stopAfterWipeScene = false): array
    {
        $texts = $this->startTexts;
        $response = null;
        for ($i = 0; $i < 60; $i++) {
            // 始めた応答の時点で、全滅の場面まで出ていることがある（鉄砲水は、始まってすぐ判定が済む）
            if ($stopAfterWipeScene && in_array('全滅した……', $texts, true)) {
                break;
            }
            $response = $this->continueEvent($sessionId)->assertOk();
            foreach ($response->json('segment.lines') ?? [] as $line) {
                $texts[] = (string) ($line['text'] ?? '');
            }
            $kind = $response->json('segment.kind');
            if (in_array($kind, ['complete', 'game_over'], true)) {
                break;
            }
        }
        $final = $response;

        return $texts;
    }

    private function setPartyHp(int $hp): void
    {
        UserCharacter::query()->where('user_id', $this->demoUser()->id)->update(['hp' => $hp]);
    }

    private function partyIsAtFullHp(): bool
    {
        $growth = app(LevelGrowthService::class);

        return UserCharacter::query()->where('user_id', $this->demoUser()->id)->get()
            ->every(fn (UserCharacter $c) => (int) $c->hp === (int) $growth->statsForCharacter($c)['hp']);
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
