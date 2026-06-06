<?php

namespace Tests\Feature;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use App\Services\Growth\LevelGrowthService;
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

    public function test_retreat_restores_party_hp_and_mp_to_full(): void
    {
        $user = $this->demoUser();
        $character = UserCharacter::query()->where('user_id', $user->id)->firstOrFail();
        $character->hp = 10;
        $character->mp = 5;
        $character->save();

        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();
        $this->postJson('/api/dungeon/retreat')->assertOk();

        $character->refresh();
        $expected = app(LevelGrowthService::class)->statsForLevel(
            $character->characterMaster,
            $character->level,
        );

        $this->assertSame($expected['hp'], $character->hp);
        $this->assertSame($expected['mp'], $character->mp);
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
