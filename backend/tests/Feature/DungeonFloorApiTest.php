<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\EnemySkillPicker;
use App\Services\Dungeon\DungeonAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonFloorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        config([
            'game.dungeon.floors.1.boss_step' => 3,
            'game.dungeon.floors.2.boss_step' => 5,
            'game.dungeon.test_force' => null,
        ]);
    }

    public function test_dungeon_status_includes_floor_fields(): void
    {
        $this->getJson('/api/dungeon')
            ->assertOk()
            ->assertJson([
                'floor' => 1,
                'step' => 0,
                'unlocked_floor' => 1,
                'max_floor' => 3,
                'playable_floor' => 3,
                'boss_step' => 3,
            ]);
    }

    public function test_enter_floor_two_is_forbidden_while_not_playable(): void
    {
        config(['game.dungeon.playable_floor' => 1]);

        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 1, 'unlocked_floor' => 2, 'step' => 0],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 2])
            ->assertStatus(403);
    }

    public function test_enter_floor_two_when_unlocked_and_playable(): void
    {
        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 1, 'unlocked_floor' => 2, 'step' => 0],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 2])
            ->assertOk()
            ->assertJsonPath('floor', 2);
    }

    public function test_boss_encounter_at_boss_step(): void
    {
        config(['game.dungeon.test_force' => 'exploration']);

        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        UserDungeonProgress::query()
            ->where('user_id', $this->demoUser()->id)
            ->update(['step' => 2]);

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('boss_encounter', true);
        $response->assertJsonPath('floor', 1);
        $response->assertJsonPath('step', 3);
    }

    public function test_floor_two_boss_encounter_at_step_31_is_dragon_with_two_cultists(): void
    {
        config([
            'game.dungeon.floors.2.boss_step' => 31,
            'game.dungeon.test_force' => 'battle',
        ]);
        // Lv1 のデモパーティは、ドラゴンブレスを引くと開幕で全滅して 1 層に戻ってしまう。
        // 敵の初手は必ず先頭の技（通常攻撃）にして、編成の確認だけを行う。
        $this->app->bind(DungeonAdvanceService::class, fn () => new DungeonAdvanceService(
            orchestrator: new BattleOrchestrator(
                engine: new BattleEngine(enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 0)),
            ),
        ));

        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 2, 'unlocked_floor' => 2, 'step' => 30, 'in_dungeon' => false],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 2])->assertOk();

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('boss_encounter', true);
        $response->assertJsonPath('floor', 2);
        $response->assertJsonPath('step', 31);

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertSame(['mahou', 'dragon', 'mahou'], $enemies->pluck('master_code')->all());
        $this->assertSame(['邪教徒 A', 'エルドラゴン', '邪教徒 B'], $enemies->pluck('name')->all());
        $response->assertJsonPath('state.meta.boss', true);
        $response->assertJsonPath('state.meta.can_flee', false);
    }

    public function test_floor_three_is_enterable_and_its_boss_at_step_31_is_zero_with_two_navis(): void
    {
        config([
            'game.dungeon.floors.3.boss_step' => 31,
            'game.dungeon.test_force' => 'battle',
        ]);
        // ナビは攻撃しないが、ゼロは Lv1 のデモパーティを開幕で全滅させうる。初手は必ず先頭の技にして、編成の確認だけを行う
        $this->app->bind(DungeonAdvanceService::class, fn () => new DungeonAdvanceService(
            orchestrator: new BattleOrchestrator(
                engine: new BattleEngine(enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 0)),
            ),
        ));

        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 3, 'unlocked_floor' => 3, 'step' => 30, 'in_dungeon' => false],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 3])->assertOk()->assertJsonPath('floor', 3);

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('boss_encounter', true);
        $response->assertJsonPath('floor', 3);
        $response->assertJsonPath('step', 31);
        $response->assertJsonPath('state.meta.floor', 3);
        $response->assertJsonPath('state.meta.boss', true);
        $response->assertJsonPath('state.meta.can_flee', false);

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertSame(['navi', 'zero', 'navi'], $enemies->pluck('master_code')->all());
        $this->assertSame(['3_navi', '3_zero', '3_navi'], $enemies->pluck('sprite')->all());
    }

    public function test_floor_three_regular_battle_uses_floor_three_enemies(): void
    {
        config(['game.dungeon.test_force' => 'battle']);
        $this->app->bind(DungeonAdvanceService::class, fn () => new DungeonAdvanceService(
            orchestrator: new BattleOrchestrator(
                engine: new BattleEngine(enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 0)),
            ),
        ));

        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 3, 'unlocked_floor' => 3, 'step' => 0, 'in_dungeon' => false],
        );
        $this->postJson('/api/dungeon/enter', ['floor' => 3])->assertOk();

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('state.meta.floor', 3);
        $response->assertJsonPath('state.meta.boss', false);

        $codes = collect($response->json('state.units'))->where('side', 'enemy')->pluck('master_code')->unique()->all();
        $this->assertSame(['bunisher'], $codes, '深さ 1 の 3 層は、バニッシャーだけ');
    }

    public function test_floor_two_mid_boss_triggers_exploration_at_step_20(): void
    {
        config(['game.dungeon.test_force' => null]);

        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 2, 'unlocked_floor' => 2, 'step' => 19, 'in_dungeon' => false],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 2])->assertOk();

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'exploration');
        $response->assertJsonPath('step', 20);
        $response->assertJsonPath('segment.kind', 'story');
        $this->assertDatabaseHas('dungeon_exploration_sessions', [
            'user_id' => $user->id,
            'event_code' => 'floor2_mid_boss',
        ]);
    }

    public function test_navigation_reports_the_floor_being_explored(): void
    {
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();
        $this->getJson('/api/player/navigation')
            ->assertOk()
            ->assertJsonPath('in_dungeon', true)
            ->assertJsonPath('dungeon_floor', 1);

        UserDungeonProgress::query()
            ->where('user_id', $this->demoUser()->id)
            ->update(['floor' => 2, 'unlocked_floor' => 2]);

        $this->getJson('/api/player/navigation')
            ->assertOk()
            ->assertJsonPath('dungeon_floor', 2);
    }

    public function test_boss_victory_unlocks_floor_two_and_floor_two_is_enterable(): void
    {
        $user = $this->demoUser();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        UserDungeonProgress::query()
            ->where('user_id', $user->id)
            ->update(['step' => 3]);

        $event = app(\App\Services\Dungeon\DungeonProgressService::class)
            ->onBossVictory($user, 1);

        $this->assertSame('dungeon_floor_cleared', $event['type']);
        $this->assertSame(2, $event['unlocked_floor']);
        $this->assertTrue($event['next_floor_playable']);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertSame(2, (int) $progress->unlocked_floor);
        $this->assertSame(0, (int) $progress->step);
        $this->assertFalse((bool) $progress->in_dungeon);

        $this->getJson('/api/player/navigation')
            ->assertOk()
            ->assertJsonPath('in_dungeon', false);

        $this->postJson('/api/dungeon/enter', ['floor' => 2])
            ->assertOk()
            ->assertJsonPath('floor', 2);
    }

    public function test_boss_battle_victory_exits_dungeon_and_emits_floor_cleared(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = $this->demoUser();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $orchestrator = app(\App\Services\Battle\BattleOrchestrator::class);
        $result = $orchestrator->createBattleForUser($user, [[
            'slot' => 'enemy_1',
            'master_code' => 'inu_moe',
            'floor' => 1,
            'name' => 'イフリーヌ',
            'sprite' => 'inu_moe',
        ]], [
            'source' => 'dungeon',
            'floor' => 1,
            'step' => 3,
            'boss' => true,
            'can_flee' => false,
        ]);

        $state = $result['state'];
        $state['units']['enemy_1']['hp'] = 1;

        $action = $orchestrator->submitPlayerAction(
            $state,
            'punch',
            null,
            'enemy_1',
            $user,
        );

        $this->assertSame('victory', $action['state']['status']);
        $this->assertTrue(
            collect($action['events'])->contains(
                fn (array $event): bool => ($event['type'] ?? '') === 'dungeon_floor_cleared',
            ),
        );

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertSame(0, (int) $progress->step);
        $this->assertFalse((bool) $progress->in_dungeon);
        $this->assertSame(1, (int) $progress->cleared_floor);
        $this->assertSame(2, (int) $progress->unlocked_floor);

        $this->getJson('/api/player/navigation')
            ->assertOk()
            ->assertJsonPath('in_dungeon', false);
    }

    public function test_boss_battle_victory_restores_party_hp_and_mp_to_full(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = $this->demoUser();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $orchestrator = app(\App\Services\Battle\BattleOrchestrator::class);
        $result = $orchestrator->createBattleForUser($user, [[
            'slot' => 'enemy_1',
            'master_code' => 'inu_moe',
            'floor' => 1,
            'name' => 'イフリーヌ',
            'sprite' => 'inu_moe',
        ]], [
            'source' => 'dungeon',
            'floor' => 1,
            'step' => 3,
            'boss' => true,
            'can_flee' => false,
        ]);

        // 満身創痍で撃破する: 全員 HP10・MP5、うち 1 人（手番でない味方）は戦闘不能
        $state = $result['state'];
        $koSlot = collect($state['units'])
            ->where('side', 'ally')
            ->pluck('id')
            ->first(fn (string $id) => $id !== $state['current_actor']);
        foreach ($state['units'] as $id => $unit) {
            if ($unit['side'] !== 'ally') {
                continue;
            }
            $state['units'][$id]['hp'] = $id === $koSlot ? 0 : 10;
            $state['units'][$id]['mp'] = 5;
            $state['units'][$id]['alive'] = $id !== $koSlot;
        }
        UserCharacter::query()
            ->whereKey($state['units'][$koSlot]['user_character_id'])
            ->update(['hp' => 0]);
        $state['units']['enemy_1']['hp'] = 1;

        $action = $orchestrator->submitPlayerAction($state, 'punch', null, 'enemy_1', $user);

        $this->assertSame('victory', $action['state']['status']);
        $this->assertTrue(
            collect($action['events'])->contains(
                fn (array $event): bool => ($event['type'] ?? '') === 'dungeon_floor_cleared',
            ),
        );

        // 待機画面が読む API で、レベルアップ後の最大値まで全回復していること
        $party = $this->getJson('/api/player/party')->assertOk()->json('characters');
        $this->assertCount(4, $party);
        foreach ($party as $character) {
            $this->assertSame($character['max_hp'], $character['hp'], "{$character['name']} の HP が全回復していること");
            $this->assertSame($character['max_mp'], $character['mp'], "{$character['name']} の MP が全回復していること");
            $this->assertTrue($character['alive'], "{$character['name']} が戦闘不能のままでないこと");
        }
    }

    public function test_first_clear_of_floor_3_adds_40_max_mp_to_saphiria_only_once(): void
    {
        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 1, 'unlocked_floor' => 3, 'cleared_floor' => 2, 'step' => 0, 'in_dungeon' => false],
        );
        $bonus = fn (): array => UserCharacter::query()->where('user_id', $user->id)->get()
            ->mapWithKeys(fn (UserCharacter $c) => [$c->characterMaster->code => (int) $c->max_mp_bonus])->all();
        $before = $bonus();

        $this->winFloor3Boss($user);

        $after = $bonus();
        $this->assertSame($before['pc1'] + 40, $after['pc1'], 'サフィリアの最大MPが +40');
        foreach (['pc2', 'pc3', 'pc4'] as $code) {
            $this->assertSame($before[$code], $after[$code], "{$code} は変わらない");
        }

        // 待機画面で、増えた最大MPまで全回復している（戦闘中の経験値でレベルが上がっていても成り立つ）
        $saphiria = UserCharacter::query()->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))->firstOrFail();
        $levelOnlyMp = app(\App\Services\Growth\LevelGrowthService::class)->statsForLevel($saphiria->characterMaster, $saphiria->level)['mp'];
        $pc1 = collect($this->getJson('/api/player/party')->assertOk()->json('characters'))->firstWhere('id', 'pc1');
        $this->assertSame($levelOnlyMp + $before['pc1'] + 40, $pc1['max_mp']);
        $this->assertSame($pc1['max_mp'], $pc1['mp']);

        // クリア済みの 3 層のボスをもう一度倒しても、増えない
        $this->winFloor3Boss($user);
        $this->assertSame($after, $bonus());
    }

    public function test_clearing_floor_2_does_not_add_the_floor_3_max_mp_bonus(): void
    {
        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 2, 'unlocked_floor' => 2, 'cleared_floor' => 1, 'step' => 0, 'in_dungeon' => false],
        );

        app(\App\Services\Dungeon\DungeonProgressService::class)->onBossVictory($user, 2);

        $this->assertSame(0, (int) UserCharacter::query()->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))->firstOrFail()->max_mp_bonus);
    }

    /**
     * 3 層のボス戦に勝つ。ボスの撃破処理は敵の種類によらないので、攻撃してこないナビを HP1 にして、こぶしで倒す
     * （ゼロだと、レベル1のパーティが開幕に全滅して操作できなくなることがある）。
     */
    private function winFloor3Boss(User $user): void
    {
        UserDungeonProgress::query()->where('user_id', $user->id)->update(['floor' => 3, 'in_dungeon' => true, 'step' => 31]);

        $orchestrator = app(BattleOrchestrator::class);
        $result = $orchestrator->createBattleForUser($user, [[
            'slot' => 'enemy_1',
            'master_code' => 'navi',
            'floor' => 3,
            'name' => 'ナビ',
            'sprite' => '3_navi',
        ]], [
            'source' => 'dungeon',
            'floor' => 3,
            'step' => 31,
            'boss' => true,
            'can_flee' => false,
        ]);

        $state = $result['state'];
        $state['units']['enemy_1']['hp'] = 1;
        $state['units']['enemy_1']['evasion_rate'] = 0;
        $action = $orchestrator->submitPlayerAction($state, 'punch', null, 'enemy_1', $user);

        $this->assertSame('victory', $action['state']['status']);
    }

    private function demoUser(): User
    {
        return User::query()
            ->where('email', config('game.demo_user_email'))
            ->firstOrFail();
    }

    protected function tearDown(): void
    {
        config([
            'game.dungeon.floors.1.boss_step' => 31,
            'game.dungeon.floors.2.boss_step' => 31,
            'game.dungeon.playable_floor' => 3,
            'game.dungeon.test_force' => null,
        ]);

        parent::tearDown();
    }
}
