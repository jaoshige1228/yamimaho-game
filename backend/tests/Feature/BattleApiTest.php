<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_demo_battle_starts_with_party_and_enemies(): void
    {
        $response = $this->postJson('/api/battles/demo');

        $response->assertOk()
            ->assertJsonStructure([
                'battle_id',
                'state' => [
                    'status',
                    'current_actor',
                    'units',
                    'commands',
                ],
                'events',
            ]);

        $units = collect($response->json('state.units'));
        $this->assertSame(4, $units->where('side', 'ally')->count());
        $this->assertSame(3, $units->where('side', 'enemy')->count());
        $this->assertTrue($units->where('side', 'ally')->every(fn (array $u) => isset($u['user_character_id'])));
        $this->assertSame('active', $response->json('state.status'));
        $this->assertSame('bat', $units->firstWhere('id', 'enemy_1')['master_code']);
    }

    public function test_demo_battle_allies_use_equipment_bonuses(): void
    {
        $response = $this->postJson('/api/battles/demo')->assertOk();
        $pc1 = collect($response->json('state.units'))->firstWhere('id', 'pc1');

        $this->assertNotNull($pc1);
        $this->assertSame('staff_basic', $pc1['weapon']['code'] ?? null);
        $this->assertSame('robe_basic', $pc1['armor']['code'] ?? null);
        $this->assertSame(15, $pc1['mag']);
        $this->assertSame(10, $pc1['def']);
    }

    public function test_kappa2_demo_battle_uses_kappa2_enemies(): void
    {
        $response = $this->postJson('/api/battles/demo-kappa2')->assertOk();

        $units = collect($response->json('state.units'));
        $enemies = $units->where('side', 'enemy')->values();

        $this->assertSame(3, $enemies->count());
        $this->assertTrue($enemies->every(fn (array $u) => in_array($u['master_code'], ['snake', 'beetle'], true)));
        $this->assertSame(100, $enemies->first()['max_hp']);
    }

    public function test_player_can_punch_enemy(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $battleId = $start->json('battle_id');

        $state = $start->json('state');
        if (! $state['awaiting_input']) {
            $state = $this->getJson("/api/battles/{$battleId}")->json('state');
        }

        $actor = $state['current_actor'];
        $this->assertStringStartsWith('pc', $actor);

        $enemy = collect($state['units'])->firstWhere('side', 'enemy');

        $response = $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'punch',
            'target_id' => $enemy['id'],
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('events'));
    }

    public function test_spell_requires_mp(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $battleId = $start->json('battle_id');
        $actor = $start->json('state.current_actor');

        $response = $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'spell',
            'spell_id' => 'pc3_fire_heavy',
            'target_id' => 'enemy_1',
        ]);

        if ($actor === 'pc3') {
            $response->assertOk();
        } else {
            $response->assertStatus(422);
        }
    }
}
