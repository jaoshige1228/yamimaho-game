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
                'playable_floor' => 1,
                'boss_step' => 3,
            ]);
    }

    public function test_enter_floor_two_is_forbidden_while_not_playable(): void
    {
        $user = $this->demoUser();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['floor' => 1, 'unlocked_floor' => 2, 'step' => 0],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => 2])
            ->assertStatus(403);
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

    public function test_boss_victory_unlocks_floor_two_but_floor_two_not_enterable(): void
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
        $this->assertFalse($event['next_floor_playable']);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertSame(2, (int) $progress->unlocked_floor);
        $this->assertSame(0, (int) $progress->step);

        $this->postJson('/api/dungeon/enter', ['floor' => 2])
            ->assertStatus(403);
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
            'game.dungeon.test_force' => null,
        ]);

        parent::tearDown();
    }
}
