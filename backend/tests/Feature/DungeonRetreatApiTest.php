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

    public function test_retreat_reduces_gold_and_resets_step(): void
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

        $response->assertJsonPath('gold', 90);
        $response->assertJsonPath('step', 0);
        $response->assertJsonPath('floor', 1);
        $this->assertSame(0, DungeonExplorationSession::query()->where('user_id', $user->id)->count());
        $this->assertSame(90, (int) $user->fresh()->gold);
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

    public function test_retreating_twice_costs_gold_only_once(): void
    {
        $user = $this->demoUser();
        $user->forceFill(['gold' => 1000])->save();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        // 「はい」の二重押し（通信のやり直し・古い画面からの再送も同じ）
        $first = $this->postJson('/api/dungeon/retreat')->assertOk();
        $second = $this->postJson('/api/dungeon/retreat')->assertOk();

        $first->assertJsonPath('gold', 900);
        $second->assertJsonPath('gold', 900)->assertJsonPath('step', 0)->assertJsonPath('in_dungeon', false);
        $this->assertSame(900, (int) $user->fresh()->gold, '2 回目は、所持金を減らさない');
    }

    public function test_retreat_outside_the_dungeon_does_nothing(): void
    {
        $user = $this->demoUser();
        $user->forceFill(['gold' => 1000])->save();
        $character = UserCharacter::query()->where('user_id', $user->id)->firstOrFail();
        $character->hp = 10;
        $character->save();

        $this->postJson('/api/dungeon/retreat')->assertOk()->assertJsonPath('gold', 1000);

        $this->assertSame(1000, (int) $user->fresh()->gold, 'ダンジョンの外では、ペナルティはかからない');
        $this->assertSame(10, (int) $character->fresh()->hp, '回復もしない（撤退の済んだあとの再送で、無料の回復にならない）');
    }

    public function test_retreat_during_an_unfinished_run_still_costs_gold_even_at_depth_zero(): void
    {
        $user = $this->demoUser();
        $user->forceFill(['gold' => 1000])->save();

        // 層に入っただけ（深さ 0）でも、ダンジョンの中にいるので、撤退のペナルティはかかる
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();
        $this->assertSame(0, (int) UserDungeonProgress::query()->where('user_id', $user->id)->value('step'));

        $this->postJson('/api/dungeon/retreat')->assertOk()->assertJsonPath('gold', 900);
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
