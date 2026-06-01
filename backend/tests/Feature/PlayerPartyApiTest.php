<?php

namespace Tests\Feature;

use App\Models\ArmorMaster;
use App\Models\User;
use App\Models\WeaponMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerPartyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_player_party_includes_equipment_and_effective_stats(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();

        $response = $this->getJson('/api/player/party')->assertOk();

        $response->assertJsonStructure([
            'characters' => [
                '*' => [
                    'id',
                    'name',
                    'sprite',
                    'mag',
                    'def',
                    'weapon' => ['code', 'name', 'mag_bonus', 'description'],
                    'armor' => ['code', 'name', 'def_bonus', 'description'],
                ],
            ],
        ]);

        $pc1 = collect($response->json('characters'))->firstWhere('id', 'pc1');
        $weapon = WeaponMaster::query()->where('code', 'staff_basic')->firstOrFail();
        $armor = ArmorMaster::query()->where('code', 'robe_basic')->firstOrFail();

        $this->assertSame('staff_basic', $pc1['weapon']['code']);
        $this->assertSame('robe_basic', $pc1['armor']['code']);
        $this->assertSame(14 + $weapon->mag_bonus, $pc1['mag']);
        $this->assertSame(9 + $armor->def_bonus, $pc1['def']);
        $this->assertSame('学校から支給されたシンプルな杖。', $pc1['weapon']['description']);
        $this->assertStringContainsString('イケてる', $pc1['armor']['description']);
    }

    public function test_player_stats_endpoint_matches_party(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();

        $party = $this->getJson('/api/player/party')->assertOk()->json('characters');
        $stats = $this->getJson('/api/player/stats')->assertOk()->json('characters');

        $this->assertSame($party, $stats);
    }
}
