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

        $this->assertSame(800, $response->json('gold'));
        $this->assertContains('staff_advanced', $response->json('catalog.characters.0.owned_weapon_codes'));
    }

    public function test_buying_stronger_gear_equips_it_automatically(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        app(PartyGoldService::class)->setGold($user, 10000);

        $weapon = $this->postJson('/api/shop/equipment/buy', [
            'slot_id' => 'pc1', 'kind' => 'weapon', 'code' => 'staff_advanced',
        ])->assertOk();
        $this->assertTrue($weapon->json('equipped'));
        $this->assertSame('staff_advanced', $weapon->json('catalog.characters.0.equipped_weapon.code'));

        $armor = $this->postJson('/api/shop/equipment/buy', [
            'slot_id' => 'pc1', 'kind' => 'armor', 'code' => 'robe_advanced',
        ])->assertOk();
        $this->assertTrue($armor->json('equipped'));
        $this->assertSame('robe_advanced', $armor->json('catalog.characters.0.equipped_armor.code'));

        // 他のキャラの装備は変わらない
        $this->assertSame('staff_basic', $weapon->json('catalog.characters.1.equipped_weapon.code'));
    }

    public function test_buying_weaker_gear_keeps_current_equipment(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        app(PartyGoldService::class)->setGold($user, 10000);

        $this->postJson('/api/shop/equipment/buy', [
            'slot_id' => 'pc1', 'kind' => 'weapon', 'code' => 'staff_master',
        ])->assertOk()->assertJsonPath('equipped', true);

        $weaker = $this->postJson('/api/shop/equipment/buy', [
            'slot_id' => 'pc1', 'kind' => 'weapon', 'code' => 'staff_advanced',
        ])->assertOk();
        $this->assertFalse($weaker->json('equipped'));
        $this->assertSame('staff_master', $weaker->json('catalog.characters.0.equipped_weapon.code'));
        $this->assertContains('staff_advanced', $weaker->json('catalog.characters.0.owned_weapon_codes'));
    }
}
