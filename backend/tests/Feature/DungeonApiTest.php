<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_enter_preserves_dungeon_progress(): void
    {
        $user = $this->demoUser();
        UserDungeonProgress::query()->create([
            'user_id' => $user->id,
            'step' => 4,
        ]);

        $this->postJson('/api/dungeon/enter', ['floor' => 1])
            ->assertOk()
            ->assertJson(['step' => 4, 'floor' => 1]);

        $this->getJson('/api/dungeon')
            ->assertOk()
            ->assertJson(['step' => 4, 'floor' => 1]);
    }

    public function test_advance_starts_battle_with_step_based_enemies(): void
    {
        config(['game.dungeon.test_force' => 'battle']);

        $this->postJson('/api/dungeon/enter')->assertOk();

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'battle');
        $response->assertJsonPath('step', 1);

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertGreaterThanOrEqual(2, $enemies->count());
        $this->assertLessThanOrEqual(3, $enemies->count());
        $this->assertTrue($enemies->every(fn (array $u) => $u['master_code'] === 'bat'));
        $this->assertTrue(
            $enemies->every(
                fn (array $u) => $u['sprite'] === 'bat' && str_contains($u['name'], 'コーモリ'),
            ),
        );
    }

    public function test_player_party_includes_sprite_and_max_stats(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();

        $response = $this->getJson('/api/player/party')->assertOk();

        $response->assertJsonStructure([
            'characters' => [
                '*' => [
                    'id',
                    'slot_id',
                    'side',
                    'name',
                    'sprite',
                    'level',
                    'hp',
                    'max_hp',
                    'mp',
                    'max_mp',
                    'alive',
                ],
            ],
        ]);

        $characters = $response->json('characters');
        $this->assertCount(4, $characters);
        $this->assertTrue(collect($characters)->every(fn (array $c) => $c['side'] === 'ally' && $c['sprite'] !== ''));
    }

    public function test_dungeon_defeat_restores_party_to_full_and_resets_step(): void
    {
        $user = $this->demoUser();
        UserCharacter::query()
            ->where('user_id', $user->id)
            ->update(['hp' => 1, 'mp' => 1]);

        config(['game.dungeon.test_force' => 'battle']);

        $this->postJson('/api/dungeon/enter')->assertOk();
        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $battleId = $start->json('battle_id');
        $state = $start->json('state');

        $maxTurns = 120;
        while (($state['status'] ?? '') === 'active' && $maxTurns-- > 0) {
            $enemy = collect($state['units'])->firstWhere('side', 'enemy');
            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'punch',
                'target_id' => $enemy['id'] ?? 'enemy_1',
            ]);
            $response->assertOk();
            $state = $response->json('state');
        }

        $this->assertSame('defeat', $state['status']);

        $this->getJson('/api/dungeon')
            ->assertOk()
            ->assertJson(['step' => 0]);

        foreach (UserCharacter::query()->where('user_id', $user->id)->with('characterMaster')->get() as $character) {
            $maxHp = app(\App\Services\Growth\LevelGrowthService::class)
                ->statsForLevel($character->characterMaster, $character->level)['hp'];
            $maxMp = app(\App\Services\Growth\LevelGrowthService::class)
                ->statsForLevel($character->characterMaster, $character->level)['mp'];

            $this->assertSame($maxHp, $character->hp);
            $this->assertSame($maxMp, $character->mp);
        }
    }

    public function test_battle_victory_keeps_dungeon_step(): void
    {
        $user = $this->demoUser();

        config(['game.dungeon.test_force' => 'battle']);

        $this->postJson('/api/dungeon/enter')->assertOk();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['step' => 3],
        );

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $battleId = $start->json('battle_id');
        $state = $start->json('state');

        $maxTurns = 400;
        while (($state['status'] ?? '') === 'active' && $maxTurns-- > 0) {
            $enemy = collect($state['units'])
                ->where('side', 'enemy')
                ->where('alive', true)
                ->sortBy('hp')
                ->first();

            if ($enemy === null) {
                break;
            }

            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'punch',
                'target_id' => $enemy['id'],
            ]);
            $response->assertOk();
            $state = $response->json('state');
        }

        $this->assertSame('victory', $state['status']);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame(4, (int) $progress->step);
    }

    public function test_advance_returns_flavor_narrative_without_session(): void
    {
        config(['game.dungeon.test_force' => 'flavor']);

        $this->postJson('/api/battles/demo')->assertOk();
        $this->postJson('/api/dungeon/enter')->assertOk();

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $response->assertJsonPath('event', 'flavor');
        $response->assertJsonPath('segment.kind', 'story');
        $response->assertJsonPath('segment.lines', fn ($lines) => is_array($lines) && count($lines) >= 1);
        $response->assertJsonMissing(['session_id']);

        $text = json_encode($response->json('segment.lines'), JSON_UNESCAPED_UNICODE);
        $this->assertTrue(
            str_contains($text, '何も起きない')
            || str_contains($text, '悲鳴')
            || str_contains($text, '宝箱'),
        );

        $this->postJson('/api/dungeon/advance')->assertOk();
    }

    public function test_master_seeder_loads_dungeon_events(): void
    {
        $this->assertDatabaseHas('dungeon_event_masters', ['code' => 'trap_arrow']);
        $this->assertDatabaseHas('dungeon_event_masters', ['code' => 'treasure_chest']);
        $this->assertDatabaseHas('dungeon_event_masters', ['code' => 'healing_spring']);
        $this->assertDatabaseHas('dungeon_event_masters', ['code' => 'suspicious_spring']);
        $this->assertDatabaseHas('dungeon_event_masters', ['code' => 'mysterious_presence']);
        $this->assertDatabaseHas('dungeon_event_masters', ['code' => 'rainbow_spring']);
        $this->assertDatabaseHas('enemy_masters', ['code' => 'bat', 'floor' => 1]);
        $this->assertDatabaseHas('enemy_masters', ['code' => 'inu_moe', 'floor' => 1]);
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()
            ->where('email', config('game.demo_user_email'))
            ->firstOrFail();
    }

    protected function tearDown(): void
    {
        config(['game.dungeon.test_force' => null]);

        parent::tearDown();
    }
}
