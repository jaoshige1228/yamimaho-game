<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserDungeonProgress;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Player\UserCharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 層選択画面の「時空の裂け目へ」。一度、探索イベントの裂け目でボス戦が始まったら、ダンジョンに潜らずに、
 * そのボス戦（慈愛・伝説・奏楽・光導の 4 人の魔法使い）を、何度でも直接始められる。
 */
class DungeonRiftEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
    }

    protected function tearDown(): void
    {
        config(['game.dungeon.test_force' => null, 'game.dungeon.test_event_code' => null]);

        parent::tearDown();
    }

    public function test_the_button_is_hidden_and_the_entry_is_refused_before_the_rift_boss_has_ever_started(): void
    {
        $this->assertFalse($this->getJson('/api/dungeon')->assertOk()->json('rift_reached'));

        $this->postJson('/api/dungeon/rift')->assertStatus(403)
            ->assertJsonPath('message', 'まだ時空の裂け目へは行けません。');
        $this->assertSame(0, BattleState::query()->count(), '戦闘は始まらない');
    }

    public function test_the_rift_boss_starting_through_the_event_unlocks_the_button_for_good(): void
    {
        $this->clearThreeFloors();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $response = $this->enterRiftThroughTheEvent();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('boss_encounter', true);

        $this->assertTrue($this->progress()->hasReachedRift($this->demoUser()), 'ボス戦が始まった時点で記録される');
        $this->assertTrue($this->getJson('/api/dungeon')->json('rift_reached'));

        // ダンジョンを出ても（撤退・全滅）、消えない
        $progress = $this->progress();
        $progress->retreat($this->demoUser());
        $this->assertTrue($this->getJson('/api/dungeon')->json('rift_reached'));
        $progress->reset($this->demoUser());
        $this->assertTrue($this->getJson('/api/dungeon')->json('rift_reached'));
    }

    public function test_an_event_that_does_not_reach_the_rift_battle_does_not_unlock_it(): void
    {
        // 「謎の気配」でも、3 層を踏破していなくて「足がすくんで動けなかった」ときは、戦闘にならない
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();
        $response = $this->enterRiftThroughTheEvent(expectBattle: false);

        $this->assertNotSame('battle', $response->json('event'));
        $this->assertFalse($this->progress()->hasReachedRift($this->demoUser()));
    }

    public function test_the_test_battle_does_not_unlock_the_button(): void
    {
        $this->postJson('/api/test_battles/start', ['preset' => 'rift_boss'])->assertOk();

        $this->assertFalse($this->progress()->hasReachedRift($this->demoUser()));
    }

    public function test_pressing_the_button_starts_the_same_rift_battle_directly_without_entering_the_dungeon(): void
    {
        $this->progress()->markRiftReached($this->demoUser());
        $this->clearThreeFloors();
        $this->assertFalse($this->getJson('/api/dungeon')->json('in_dungeon'));

        $response = $this->postJson('/api/dungeon/rift')->assertOk();

        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('boss_encounter', true);
        $state = BattleState::query()->findOrFail($response->json('battle_id'))->state;
        $enemies = collect($state['units'])->where('side', 'enemy');
        $this->assertSame(['rift_mercy', 'rift_legend', 'rift_music', 'rift_guide'], $enemies->pluck('master_code')->values()->all());
        $this->assertSame(
            ['慈愛の魔法使い', '伝説の魔法使い', '奏楽の魔法使い', '光導の魔法使い'],
            $enemies->pluck('name')->values()->all(),
        );
        // 探索イベントの裂け目と同じ戦闘: ボス戦・逃げられない・専用 BGM・勝ったときの文言が裂け目用
        $this->assertSame('dungeon', $state['meta']['source']);
        $this->assertTrue($state['meta']['boss']);
        $this->assertTrue($state['meta']['rift']);
        $this->assertFalse($state['meta']['can_flee']);
        $this->assertSame('last_boss', $state['meta']['bgm']);
        // 勝ったときの後始末は、最終層のボスと同じ（3 層）
        $this->assertSame(3, (int) $state['meta']['floor']);
        // ダンジョンには入らない（潜っていない状態のまま）
        $this->assertFalse($this->getJson('/api/dungeon')->json('in_dungeon'));
        $this->assertTrue($this->getJson('/api/dungeon')->json('rift_reached'));
    }

    public function test_it_cannot_be_started_while_in_a_battle_or_while_exploring(): void
    {
        $this->progress()->markRiftReached($this->demoUser());
        $this->clearThreeFloors();
        $this->growAndHealThePartyHealthy();

        // 戦闘中
        $first = $this->postJson('/api/dungeon/rift')->assertOk();
        $this->postJson('/api/dungeon/rift')->assertStatus(409)->assertJsonPath('message', 'いまは時空の裂け目へ行けません。');
        $this->assertSame(1, BattleState::query()->where('status', 'active')->count());

        // ダンジョンに潜っている間
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();
        $this->postJson('/api/dungeon/rift')->assertStatus(409);
        $this->assertNotNull($first->json('battle_id'));
    }

    public function test_winning_returns_to_the_waiting_screen_with_full_hp_and_the_button_stays(): void
    {
        $this->progress()->markRiftReached($this->demoUser());
        $this->clearThreeFloors();
        $this->growAndHealThePartyHealthy();

        $start = $this->postJson('/api/dungeon/rift')->assertOk();
        $result = $this->winAgainst($start->json('battle_id'));

        $this->assertSame('victory', $result['state']['status']);
        $cleared = collect($result['events'])->firstWhere('type', 'dungeon_floor_cleared');
        $this->assertNotNull($cleared);
        $this->assertSame('空間の裂け目の主たちを退けた！', $cleared['text']);
        $this->assertFalse($this->getJson('/api/dungeon')->json('in_dungeon'));
        $this->assertTrue($this->getJson('/api/dungeon')->json('rift_reached'), '勝っても、ボタンは残る');

        // 何度でも挑める
        $this->postJson('/api/dungeon/rift')->assertOk();
    }

    public function test_losing_keeps_the_button(): void
    {
        $this->progress()->markRiftReached($this->demoUser());
        $this->clearThreeFloors();

        $response = $this->postJson('/api/dungeon/rift')->assertOk();
        $battleId = $response->json('battle_id');

        // サフィリアだけが、HP 1 で残っている状態にして、倒されるまで進める
        $record = BattleState::query()->findOrFail($battleId);
        $state = $record->state;
        foreach (['pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = 0;
            $state['units'][$id]['alive'] = false;
        }
        $state['units']['pc1']['hp'] = 1;
        $state['units']['pc1']['alive'] = true;
        $record->state = $state;
        $record->save();

        for ($i = 0; $i < 12 && BattleState::query()->findOrFail($battleId)->state['status'] === 'active'; $i++) {
            $this->postJson("/api/battles/{$battleId}/actions", ['action' => 'punch', 'target_id' => 'enemy_1'])->assertOk();
        }
        $this->assertSame('defeat', BattleState::query()->findOrFail($battleId)->state['status']);

        $status = $this->getJson('/api/dungeon')->assertOk()->json();
        $this->assertTrue($status['rift_reached'], '負けても、ボタンは残る');
        $this->assertFalse($status['in_dungeon']);
        // 負けたあとは全回復（探索中の戦闘に負けたときと同じ）
        $this->assertTrue(
            app(UserCharacterService::class)->partyInSlotOrder($this->demoUser())->every(fn ($c) => (int) $c->hp > 0),
        );
        // 戦闘が終わったので、もう一度挑める
        $this->postJson('/api/dungeon/rift')->assertOk();
    }

    public function test_the_home_reset_forgets_that_the_rift_was_reached(): void
    {
        $this->progress()->markRiftReached($this->demoUser());
        $this->assertTrue($this->getJson('/api/dungeon')->json('rift_reached'));

        $this->postJson('/api/reset')->assertOk();

        $this->assertFalse($this->getJson('/api/dungeon')->json('rift_reached'));
        $this->postJson('/api/dungeon/rift')->assertStatus(403);
    }

    public function test_the_record_is_per_user(): void
    {
        $this->progress()->markRiftReached($this->demoUser());

        $other = User::factory()->create();
        $this->assertFalse($this->progress()->hasReachedRift($other));
    }

    private function progress(): DungeonProgressService
    {
        return app(DungeonProgressService::class);
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }

    /** 育てて、全回復しておく（裂け目の魔法使いは強いので、デモのレベル 1 のままだと、あっさり倒されて戦闘が続かない） */
    private function growAndHealThePartyHealthy(): void
    {
        $this->postJson('/api/admins/grant-exp', ['exp' => 400000])->assertOk();
        app(UserCharacterService::class)->restorePartyToFull($this->demoUser());
    }

    /** 3 層まで踏破した状態（裂け目の入口が開く条件） */
    private function clearThreeFloors(): void
    {
        $this->progress()->markFloorCleared($this->demoUser(), 3);
    }

    /**
     * 「謎の気配」を始めて「はい」を選び、戦闘が始まるまで進める。
     */
    private function enterRiftThroughTheEvent(bool $expectBattle = true): \Illuminate\Testing\TestResponse
    {
        config(['game.dungeon.test_force' => 'exploration', 'game.dungeon.test_event_code' => 'mysterious_presence']);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');

        $response = $start;
        for ($i = 0; $i < 12 && $response->json('segment.kind') !== 'choice'; $i++) {
            $response = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
        }
        $this->assertSame('choice', $response->json('segment.kind'));

        $response = $this->postJson('/api/dungeon/exploration/choose', ['session_id' => $sessionId, 'slot_id' => 'yes'])->assertOk();
        for ($i = 0; $i < 8 && $response->json('event') !== 'battle' && $response->json('segment.kind') !== 'complete'; $i++) {
            $response = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
        }
        if ($expectBattle) {
            $this->assertSame('battle', $response->json('event'));
        }

        return $response;
    }

    /**
     * 敵の HP を 1 にして、こぶしで倒しきる（毎回、HP 1・命の加護なし・回避なしにそろえてから殴る。
     * 慈愛の命の加護や、奏楽・光導の回復・蘇生で、倒しきれなくなるのを避ける）。
     *
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function winAgainst(string $battleId): array
    {
        $response = null;
        for ($i = 0; $i < 40; $i++) {
            $record = BattleState::query()->findOrFail($battleId);
            $state = $record->state;
            if ($state['status'] !== 'active') {
                break;
            }
            foreach ($state['units'] as $id => $unit) {
                if ($unit['side'] === 'enemy' && $unit['alive']) {
                    $state['units'][$id]['hp'] = 1;
                    $state['units'][$id]['evasion_rate'] = 0;
                    $state['units'][$id]['life_ward'] = false;
                    $state['units'][$id]['damage_shield'] = false;
                    $state['units'][$id]['buffs'] = [];
                }
            }
            $record->state = $state;
            $record->save();

            $enemy = collect($state['units'])->where('side', 'enemy')->where('alive', true)->first();
            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'punch',
                'target_id' => $enemy['id'],
            ])->assertOk();
        }

        $this->assertNotNull($response);

        return ['state' => $response->json('state'), 'events' => $response->json('events')];
    }
}
