<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dungeon\DungeonProgressService;
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
        $this->clearFloors($user, 1);

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

    private function clearFloors(User $user, int $floors): void
    {
        for ($floor = 1; $floor <= $floors; $floor++) {
            app(DungeonProgressService::class)->markFloorCleared($user, $floor);
        }
    }

    /** @return list<string> */
    private function listedCodes(string $kind): array
    {
        return collect($this->getJson('/api/shop/equipment')->assertOk()->json($kind))->pluck('code')->all();
    }

    public function test_the_lineup_grows_as_floors_are_cleared(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $expected = [
            0 => [['staff_advanced'], ['robe_advanced']],
            1 => [['staff_advanced', 'staff_master'], ['robe_advanced', 'robe_master']],
            2 => [['staff_advanced', 'staff_master', 'staff_pro'], ['robe_advanced', 'robe_master', 'robe_tuyosugi']],
            3 => [
                ['staff_advanced', 'staff_master', 'staff_pro', 'staff_ultimate'],
                ['robe_advanced', 'robe_master', 'robe_tuyosugi', 'robe_ultimate'],
            ],
        ];
        foreach ($expected as $cleared => [$weapons, $armors]) {
            $this->clearFloors($user, $cleared); // 突破した層は上書きではなく最大値なので、毎回呼んでよい
            $this->assertSame($weapons, $this->listedCodes('weapons'), "{$cleared} 層突破の武器");
            $this->assertSame($armors, $this->listedCodes('armors'), "{$cleared} 層突破の防具");
        }
    }

    public function test_ultimate_gear_has_the_documented_stats_and_price(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $this->clearFloors($user, 3);

        $weapon = collect($this->getJson('/api/shop/equipment')->json('weapons'))->firstWhere('code', 'staff_ultimate');
        $this->assertSame('究極の杖', $weapon['name']);
        $this->assertSame(120, $weapon['mag_bonus']);
        $this->assertSame(30000, $weapon['price']);
        $this->assertStringContainsString('某ゾネの創始者', $weapon['description']);

        $armor = collect($this->getJson('/api/shop/equipment')->json('armors'))->firstWhere('code', 'robe_ultimate');
        $this->assertSame('究極のローブ', $armor['name']);
        $this->assertSame(200, $armor['def_bonus']);
        $this->assertSame(30000, $armor['price']);
        $this->assertStringContainsString('某モンドの創始者', $armor['description']);
    }

    public function test_gear_of_a_floor_that_is_not_cleared_cannot_be_bought_even_by_code(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        app(PartyGoldService::class)->setGold($user, 100000);

        // 0 層突破: 熟練・大魔導・究極は買えない（お金があっても、コードを直接指定しても）
        foreach ([['weapon', 'staff_master'], ['armor', 'robe_master'], ['weapon', 'staff_pro'], ['armor', 'robe_tuyosugi'], ['weapon', 'staff_ultimate'], ['armor', 'robe_ultimate']] as [$kind, $code]) {
            $this->postJson('/api/shop/equipment/buy', ['slot_id' => 'pc1', 'kind' => $kind, 'code' => $code])->assertStatus(422);
        }
        $this->assertSame(100000, app(PartyGoldService::class)->getGold($user->fresh()));

        // 2 層まで突破しても、究極（3 層）はまだ買えない。大魔導は買える
        $this->clearFloors($user, 2);
        $this->postJson('/api/shop/equipment/buy', ['slot_id' => 'pc1', 'kind' => 'weapon', 'code' => 'staff_ultimate'])->assertStatus(422);
        $this->postJson('/api/shop/equipment/buy', ['slot_id' => 'pc1', 'kind' => 'weapon', 'code' => 'staff_pro'])->assertOk();

        // 3 層を突破すると、究極が買えて、強いので自動で装備される
        $this->clearFloors($user, 3);
        $response = $this->postJson('/api/shop/equipment/buy', ['slot_id' => 'pc1', 'kind' => 'armor', 'code' => 'robe_ultimate'])->assertOk();
        $this->assertTrue($response->json('equipped'));
        $this->assertSame('robe_ultimate', $response->json('catalog.characters.0.equipped_armor.code'));
        $this->assertSame(100000 - 4000 - 30000, $response->json('gold'));
    }
}
