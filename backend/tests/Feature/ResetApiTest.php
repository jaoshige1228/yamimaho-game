<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_battles_and_restores_party(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $this->postJson('/api/battles/demo')->assertOk();
        $this->assertGreaterThan(0, BattleState::query()->count());

        $user = User::query()->where('email', config('game.demo_user_email'))->first();
        $this->assertNotNull($user);

        $character = UserCharacter::query()->where('user_id', $user->id)->first();
        $character->update(['level' => 5, 'exp' => 99]);

        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'floor' => 1,
                'step' => 12,
                'unlocked_floor' => 2,
                'skip_battle_encounters' => true,
            ],
        );
        DungeonExplorationSession::query()->create([
            'id' => '00000000-0000-4000-8000-000000000001',
            'user_id' => $user->id,
            'event_code' => 'trap_arrow',
            'current_node_key' => 'start',
            'context' => [],
            'step_at_start' => 12,
            'floor_at_start' => 1,
        ]);

        $this->postJson('/api/reset')->assertOk();

        $this->assertSame(0, BattleState::query()->count());
        $this->assertSame(
            4,
            UserCharacter::query()->where('user_id', $user->id)->where('level', 1)->count(),
        );

        $dungeon = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($dungeon);
        $this->assertSame(1, (int) $dungeon->floor);
        $this->assertSame(0, (int) $dungeon->step);
        $this->assertSame(1, (int) $dungeon->unlocked_floor);
        $this->assertFalse((bool) $dungeon->skip_battle_encounters);
        $this->assertSame(0, DungeonExplorationSession::query()->where('user_id', $user->id)->count());
    }
}
