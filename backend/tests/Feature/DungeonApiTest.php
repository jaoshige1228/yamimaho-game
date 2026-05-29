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

        $this->postJson('/api/dungeon/enter')
            ->assertOk()
            ->assertJson(['step' => 4]);

        $this->getJson('/api/dungeon')
            ->assertOk()
            ->assertJson(['step' => 4]);
    }

    public function test_advance_steps_follow_message_and_battle_pattern(): void
    {
        $this->postJson('/api/dungeon/enter')->assertOk();

        $step1 = $this->postJson('/api/dungeon/advance')->assertOk();
        $step1->assertJson([
            'event' => 'message',
            'text' => '何も起きなかった',
            'step' => 1,
        ]);

        $step2 = $this->postJson('/api/dungeon/advance')->assertOk();
        $step2->assertJsonPath('event', 'battle');
        $step2->assertJsonPath('step', 2);
        $enemies = collect($step2->json('state.units'))->where('side', 'enemy')->values();
        $this->assertCount(2, $enemies);
        $this->assertTrue($enemies->every(fn (array $u) => $u['master_code'] === 'kappa'));

        $step3 = $this->postJson('/api/dungeon/advance')->assertOk();
        $step3->assertJson([
            'event' => 'story',
            'step' => 3,
        ]);
        $step3->assertJsonPath('lines.0.type', 'narration');
        $step3->assertJsonPath('lines.0.text', 'この先から変な匂いが漂ってくる……');
        $step3->assertJsonPath('lines.1.type', 'dialogue');
        $step3->assertJsonPath('lines.1.character', 'pc1');
        $step3->assertJsonPath('lines.1.text', 'な、なんか変な匂いがするよ！？');

        $step4 = $this->postJson('/api/dungeon/advance')->assertOk();
        $step4->assertJsonPath('event', 'battle');
        $step4->assertJsonPath('step', 4);
        $this->assertCount(
            3,
            collect($step4->json('state.units'))->where('side', 'enemy'),
        );

        $step5 = $this->postJson('/api/dungeon/advance')->assertOk();
        $step5->assertJson([
            'event' => 'message',
            'text' => '何も起きなかった',
            'step' => 5,
        ]);

        $step6 = $this->postJson('/api/dungeon/advance')->assertOk();
        $step6->assertJsonPath('event', 'battle');
        $step6->assertJsonPath('step', 6);
        $boss = collect($step6->json('state.units'))->firstWhere('side', 'enemy');
        $this->assertSame('sha', $boss['master_code'] ?? null);
        $this->assertSame('大佐', $boss['name'] ?? null);
    }

    public function test_player_party_includes_sprite_and_max_stats(): void
    {
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

        $this->postJson('/api/dungeon/enter')->assertOk();
        $this->postJson('/api/dungeon/advance')->assertOk();
        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $battleId = $start->json('battle_id');
        $state = $start->json('state');

        $maxTurns = 120;
        while (($state['status'] ?? '') === 'active' && $maxTurns-- > 0) {
            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'defend',
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

    public function test_boss_victory_resets_dungeon_progress(): void
    {
        $user = $this->demoUser();

        $this->postJson('/api/admins/grant-exp', ['exp' => 4000])->assertOk();

        $this->postJson('/api/dungeon/enter')->assertOk();
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['step' => 5],
        );

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $this->assertSame('battle', $start->json('event'));
        $battleId = $start->json('battle_id');
        $state = $start->json('state');
        $cleared = false;

        $maxTurns = 800;
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

            $eventTypes = array_column($response->json('events'), 'type');
            if (in_array('dungeon_cleared', $eventTypes, true)) {
                $cleared = true;
            }

            $state = $response->json('state');
        }

        $this->assertSame('victory', $state['status']);
        $this->assertTrue($cleared);

        $progress = UserDungeonProgress::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame(0, (int) $progress->step);
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()
            ->where('email', config('game.demo_user_email'))
            ->firstOrFail();
    }
}
