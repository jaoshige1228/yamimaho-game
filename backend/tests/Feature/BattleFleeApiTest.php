<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDungeonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattleFleeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_dungeon_battle_flee_decreases_step_by_three(): void
    {
        config(['game.dungeon.test_force' => 'battle']);

        $user = $this->demoUser();
        $this->postJson('/api/dungeon/enter')->assertOk();
        UserDungeonProgress::query()->where('user_id', $user->id)->update(['step' => 9]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $battleId = $start->json('battle_id');
        $state = $start->json('state');

        if (! ($state['awaiting_input'] ?? false)) {
            $state = $this->getJson("/api/battles/{$battleId}")->json('state');
        }

        $response = $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'flee',
        ])->assertOk();

        $response->assertJsonPath('state.status', 'fled');
        $response->assertJsonFragment(['type' => 'fled']);
        $this->assertSame(7, (int) UserDungeonProgress::query()->where('user_id', $user->id)->value('step'));
    }

    public function test_flee_step_does_not_go_below_one(): void
    {
        config(['game.dungeon.test_force' => 'battle']);

        $user = $this->demoUser();
        $this->postJson('/api/dungeon/enter')->assertOk();
        UserDungeonProgress::query()->where('user_id', $user->id)->update(['step' => 2]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $battleId = $start->json('battle_id');

        $this->postJson("/api/battles/{$battleId}/actions", ['action' => 'flee'])->assertOk();

        $this->assertSame(1, (int) UserDungeonProgress::query()->where('user_id', $user->id)->value('step'));
    }

    public function test_boss_battle_cannot_flee(): void
    {
        config(['game.dungeon.floors.1.boss_step' => 3]);

        $user = $this->demoUser();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        UserDungeonProgress::query()->where('user_id', $user->id)->update(['step' => 2]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();

        $start->assertJsonPath('boss_encounter', true);

        $battleId = $start->json('battle_id');
        $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'flee',
        ])->assertStatus(422);
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
