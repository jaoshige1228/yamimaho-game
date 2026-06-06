<?php

namespace Tests\Feature;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserDungeonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonRetreatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_retreat_reduces_gold_and_keeps_dungeon_progress(): void
    {
        $user = $this->demoUser();
        $user->forceFill(['gold' => 100])->save();

        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'floor' => 1,
                'unlocked_floor' => 1,
                'step' => 7,
            ],
        );

        DungeonExplorationSession::query()->create([
            'user_id' => $user->id,
            'event_code' => 'healing_spring',
            'floor_at_start' => 1,
            'step_at_start' => 7,
            'context_json' => [],
        ]);

        $response = $this->postJson('/api/dungeon/retreat')->assertOk();

        $response->assertJsonPath('gold', 80);
        $response->assertJsonPath('step', 7);
        $response->assertJsonPath('floor', 1);
        $this->assertSame(0, DungeonExplorationSession::query()->where('user_id', $user->id)->count());
        $this->assertSame(80, (int) $user->fresh()->gold);
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
