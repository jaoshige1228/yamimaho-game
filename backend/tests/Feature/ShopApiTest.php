<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Player\PartyGoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_buy_weapon_for_character(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        app(PartyGoldService::class)->setGold($user, 1000);

        $response = $this->postJson('/api/shop/equipment/buy', [
            'slot_id' => 'pc1',
            'kind' => 'weapon',
            'code' => 'staff_advanced',
        ])->assertOk();

        $this->assertSame(200, $response->json('gold'));
        $this->assertContains('staff_advanced', $response->json('catalog.characters.0.owned_weapon_codes'));
    }
}
