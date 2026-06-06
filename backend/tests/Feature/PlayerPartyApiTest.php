<?php

namespace Tests\Feature;

use App\Models\ArmorMaster;
use App\Models\User;
use App\Models\UserCharacter;
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
            'gold',
            'items',
            'characters' => [
                '*' => [
                    'id',
                    'name',
                    'sprite',
                    'mag',
                    'def',
                    'vit',
                    'weapon' => ['code', 'name', 'mag_bonus', 'description'],
                    'armor' => ['code', 'name', 'def_bonus', 'description'],
                ],
            ],
        ]);

        $pc1 = collect($response->json('characters'))->firstWhere('id', 'pc1');
        $weapon = WeaponMaster::query()->where('code', 'staff_basic')->firstOrFail();
        $armor = ArmorMaster::query()->where('code', 'robe_basic')->firstOrFail();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $character = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();

        $this->assertSame('staff_basic', $pc1['weapon']['code']);
        $this->assertSame('robe_basic', $pc1['armor']['code']);
        $this->assertSame($character->mag + $weapon->mag_bonus, $pc1['mag']);
        $this->assertSame($character->def + $armor->def_bonus, $pc1['def']);
        $this->assertSame('学校から支給されたシンプルな杖。', $pc1['weapon']['description']);
        $this->assertStringContainsString('イケてる', $pc1['armor']['description']);
    }

    public function test_new_player_starts_with_starter_potions(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();

        $response = $this->getJson('/api/player/party')->assertOk();
        $items = collect($response->json('items'))->keyBy('code');

        $this->assertSame(4, $items->get('potion_hp_s')['quantity'] ?? 0);
        $this->assertSame(4, $items->get('potion_mp_s')['quantity'] ?? 0);
        $this->assertCount(2, $items);
    }

    public function test_player_stats_endpoint_matches_party(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();

        $party = $this->getJson('/api/player/party')->assertOk()->json('characters');
        $stats = $this->getJson('/api/player/stats')->assertOk()->json('characters');

        $this->assertSame($party, $stats);
    }
}
