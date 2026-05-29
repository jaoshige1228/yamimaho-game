<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserCharacter;
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

        $this->postJson('/api/reset')->assertOk();

        $this->assertSame(0, BattleState::query()->count());
        $this->assertSame(
            4,
            UserCharacter::query()->where('user_id', $user->id)->where('level', 1)->count(),
        );
    }
}
