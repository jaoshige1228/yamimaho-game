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
            'reset_progress' => true,
        ])
            ->assertOk()
            ->assertJson([
                'skip_battles' => true,
                'step' => 0,
            ]);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertTrue($progress->skip_battle_encounters);
        $this->assertSame(0, (int) $progress->step);

        config(['game.dungeon.test_force' => null]);

        $this->postJson('/api/dungeon/enter')->assertOk();
        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $this->assertSame('exploration', $response->json('event'));
    }
}
