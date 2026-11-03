<?php

namespace Tests\Feature;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\EnemySkillPicker;
use App\Services\Dungeon\Exploration\ExplorationBattleLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 戦闘開始直後の敵フェーズ（先手の敵の行動）で全滅した場合も、
 * プレイヤーの行動で負けたときと同じ敗北処理（深さ・層のリセットと全回復）が行われること。
 */
class BattleOpeningDefeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_boss_wipe_in_opening_enemy_phase_resets_dungeon_and_restores_party(): void
    {
        $user = $this->demoUser();
        $this->enterDungeon($user, floor: 1, step: 31);
        $this->weakenParty($user);

        $result = $this->orchestratorWhereEnemiesUseLastSkill()->createBattleForUser($user, [[
            'slot' => 'enemy_1',
            'master_code' => 'inu_moe',
            'floor' => 1,
            'name' => 'イフリーヌ',
            'sprite' => 'inu_moe',
        ]], [
            'source' => 'dungeon',
            'floor' => 1,
            'step' => 31,
            'boss' => true,
            'can_flee' => false,
        ]);

        // イフリーヌ（素早さ 20）が先手で火炎放射を放ち、HP 1 の 4 人が一度も動けず全滅する
        $this->assertSame('defeat', $result['state']['status']);
        $this->assertFalse($result['state']['awaiting_input']);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int) $progress->step, '深さが 0 に戻ること');
        $this->assertSame(1, (int) $progress->floor);
        $this->assertFalse((bool) $progress->in_dungeon, 'ダンジョンから退出していること');
        $this->assertPartyIsFull();
    }

    public function test_mid_boss_wipe_in_opening_enemy_phase_discards_exploration_session(): void
    {
        $user = $this->demoUser();
        $this->enterDungeon($user, floor: 2, step: 20);
        $this->weakenParty($user);

        $session = DungeonExplorationSession::query()->create([
            'user_id' => $user->id,
            'event_code' => 'floor2_mid_boss',
            'current_node_key' => 'to_battle',
            'context' => ['awaiting_battle' => true],
            'step_at_start' => 20,
            'floor_at_start' => 2,
        ]);

        $launcher = new ExplorationBattleLauncher(orchestrator: $this->orchestratorWhereEnemiesUseLastSkill());
        $battle = $launcher->startMidBossBattle($user, 2, 20, ['npc_kasu', 'npc_gusu'], 'outro1', $session->id);

        // カース（素早さ 38）が先手で火炎波及を唱え、全滅する
        $this->assertSame('defeat', $battle['state']['status']);
        $this->assertSame(
            0,
            DungeonExplorationSession::query()->where('user_id', $user->id)->count(),
            '探索セッションが破棄されること',
        );

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int) $progress->step);
        $this->assertFalse((bool) $progress->in_dungeon);
        $this->assertPartyIsFull();
    }

    /**
     * 敵の技抽選を固定し、各敵が「最後の技」（イフリーヌ=火炎放射、カース=火炎波及）を必ず選ぶようにする。
     */
    private function orchestratorWhereEnemiesUseLastSkill(): BattleOrchestrator
    {
        return new BattleOrchestrator(
            engine: new BattleEngine(enemySkillPicker: new EnemySkillPicker(roll: fn () => 99)),
        );
    }

    private function enterDungeon(User $user, int $floor, int $step): void
    {
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => $floor, 'unlocked_floor' => $floor, 'step' => $step, 'in_dungeon' => true],
        );
    }

    private function weakenParty(User $user): void
    {
        UserCharacter::query()->where('user_id', $user->id)->update(['hp' => 1, 'mp' => 1]);
    }

    /** 待機画面が読む API で、全員が最大 HP/MP になっていること */
    private function assertPartyIsFull(): void
    {
        $party = $this->getJson('/api/player/party')->assertOk()->json('characters');
        $this->assertCount(4, $party);

        foreach ($party as $character) {
            $this->assertSame($character['max_hp'], $character['hp'], "{$character['name']} の HP が全回復していること");
            $this->assertSame($character['max_mp'], $character['mp'], "{$character['name']} の MP が全回復していること");
        }
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
