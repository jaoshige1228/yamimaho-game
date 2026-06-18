<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDungeonProgress;
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
                'playable_floor' => 2,
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

    public function test_floor_two_boss_encounter_at_step_41(): void
    {
        config([
            'game.dungeon.floors.2.boss_step' => 41,
            'game.dungeon.test_force' => 'battle',
        ]);

        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 2, 'unlocked_floor' => 2, 'step' => 40, 'in_dungeon' => false],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 2])->assertOk();

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('boss_encounter', true);
        $response->assertJsonPath('floor', 2);
        $response->assertJsonPath('step', 41);
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

        $this->getJson('/api/player/navigation')
            ->assertOk()
            ->assertJsonPath('in_dungeon', false);
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
            'game.dungeon.floors.2.boss_step' => 41,
            'game.dungeon.playable_floor' => 2,
            'game.dungeon.test_force' => null,
        ]);

        parent::tearDown();
    }
}
