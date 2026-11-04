<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDungeonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonAdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_skip_battles_and_reset_progress(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['step' => 7, 'skip_battle_encounters' => false],
        );

        $this->postJson('/api/admins/dungeon-settings', [
            'skip_battles' => true,
            'force_dialogue_events' => false,
            'reset_progress' => true,
        ])
            ->assertOk()
            ->assertJson([
                'skip_battles' => true,
                'step' => 0,
                'floor' => 1,
            ]);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertTrue($progress->skip_battle_encounters);
        $this->assertSame(0, (int) $progress->step);

        config(['game.dungeon.test_force' => null]);

        $this->postJson('/api/dungeon/enter')->assertOk();
        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $this->assertNotSame('battle', $response->json('event'));
        $this->assertContains($response->json('event'), ['exploration', 'flavor']);
    }

    public function test_force_dialogue_events_always_starts_dialogue_exploration(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['step' => 0, 'force_dialogue_events' => false],
        );

        $this->postJson('/api/admins/dungeon-settings', [
            'skip_battles' => false,
            'force_dialogue_events' => true,
            'reset_progress' => false,
        ])
            ->assertOk()
            ->assertJson([
                'force_dialogue_events' => true,
            ]);

        config(['game.dungeon.test_force' => null]);

        $this->postJson('/api/dungeon/enter')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/dungeon/advance')->assertOk();
            $this->assertSame('exploration', $response->json('event'));
            $this->assertArrayHasKey('session_id', $response->json());

            $sessionId = $response->json('session_id');
            $service = app(\App\Services\Dungeon\Exploration\DungeonExplorationService::class);
            for ($j = 0; $j < 40; $j++) {
                $step = $service->continue($user, $sessionId);
                if (($step['segment']['kind'] ?? '') === 'complete') {
                    break;
                }
            }
        }
    }

    public function test_get_dungeon_settings_returns_current_flags(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'skip_battle_encounters' => true,
                'force_dialogue_events' => true,
                'step' => 4,
            ],
        );

        $this->getJson('/api/admins/dungeon-settings')
            ->assertOk()
            ->assertJson([
                'skip_battles' => true,
                'force_dialogue_events' => true,
                'step' => 4,
            ]);
    }

    public function test_dungeon_settings_defaults_missing_boolean_fields_to_false(): void
    {
        $this->postJson('/api/admins/dungeon-settings', [
            'skip_battles' => true,
        ])
            ->assertOk()
            ->assertJson([
                'skip_battles' => true,
                'force_dialogue_events' => false,
            ]);
    }

    public function test_admin_can_mark_floors_cleared(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $this->getJson('/api/admins/dungeon-settings')
            ->assertOk()
            ->assertJson(['cleared_floor' => 0, 'unlocked_floor' => 1]);

        // 1層: クリア済みになり、2層が解放される
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 1])
            ->assertOk()
            ->assertJson(['cleared_floor' => 1, 'unlocked_floor' => 2, 'message' => '1層をクリア済みにしました。']);

        // 3層: 2層もクリア済みとして扱われ、最終層なので解放層は 3 のまま
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 3])
            ->assertOk()
            ->assertJson(['cleared_floor' => 3, 'unlocked_floor' => 3]);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(3, (int) $progress->cleared_floor);
        $this->assertSame(3, (int) $progress->unlocked_floor);
    }

    public function test_admin_clear_never_lowers_cleared_floor(): void
    {
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 3])->assertOk();

        $this->postJson('/api/admins/dungeon-clear', ['floor' => 1])
            ->assertOk()
            ->assertJson(['cleared_floor' => 3, 'unlocked_floor' => 3, 'message' => '1層はすでにクリア済みです。']);
    }

    public function test_admin_clear_second_floor_unlocks_third_floor(): void
    {
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 2])
            ->assertOk()
            ->assertJson(['cleared_floor' => 2, 'unlocked_floor' => 3]);
    }

    public function test_admin_clear_rejects_unknown_floor(): void
    {
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 0])->assertStatus(422);
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 4])->assertStatus(422);
        $this->postJson('/api/admins/dungeon-clear', [])->assertStatus(422);
    }

    public function test_home_reset_clears_cleared_floor(): void
    {
        $this->postJson('/api/admins/dungeon-clear', ['floor' => 3])->assertOk();

        $this->postJson('/api/reset')->assertOk();

        $this->getJson('/api/admins/dungeon-settings')
            ->assertOk()
            ->assertJson(['cleared_floor' => 0, 'unlocked_floor' => 1]);
    }
}
