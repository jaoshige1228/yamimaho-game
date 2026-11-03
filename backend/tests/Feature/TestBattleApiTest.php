<?php

namespace Tests\Feature;

use App\Models\UserCharacter;
use App\Models\UserCharacterOwnedArmor;
use App\Models\UserCharacterOwnedWeapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class TestBattleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_catalog_lists_regular_enemies_and_boss_presets(): void
    {
        $response = $this->getJson('/api/test_battles/catalog')->assertOk();

        $codes = collect($response->json('enemies'))->pluck('code')->all();
        $this->assertContains('bat', $codes);
        $this->assertNotContains('inu_moe', $codes);
        $this->assertNotContains('npc_kasu', $codes);

        $presetIds = collect($response->json('boss_presets'))->pluck('id')->all();
        $this->assertEquals(
            ['floor1_boss', 'floor2_boss', 'floor2_mid_boss'],
            $presetIds,
        );
    }

    public function test_start_regular_battle_with_selected_count(): void
    {
        $response = $this->postJson('/api/test_battles/start', [
            'floor' => 1,
            'code' => 'bat',
            'count' => 2,
        ])->assertOk();

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertSame(2, $enemies->count());
        $this->assertTrue($enemies->every(fn (array $u) => $u['master_code'] === 'bat'));
        $this->assertSame('test', $response->json('state.meta.source'));
    }

    public function test_start_floor2_mid_boss_spawns_two_enemies_with_broken_bgm(): void
    {
        $response = $this->postJson('/api/test_battles/start', [
            'preset' => 'floor2_mid_boss',
        ])->assertOk();

        $response->assertJsonPath('mid_boss_encounter', true);
        $response->assertJsonPath('state.meta.mid_boss', true);
        $response->assertJsonPath('state.meta.bgm', 'broken');

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertSame(2, $enemies->count());
        $this->assertEqualsCanonicalizing(
            ['npc_kasu', 'npc_gusu'],
            $enemies->pluck('master_code')->all(),
        );
    }

    public function test_start_floor1_boss_sets_boss_encounter(): void
    {
        $response = $this->postJson('/api/test_battles/start', [
            'preset' => 'floor1_boss',
        ])->assertOk();

        $response->assertJsonPath('boss_encounter', true);
        $response->assertJsonPath('state.meta.boss', true);

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertSame(1, $enemies->count());
        $this->assertSame('inu_moe', $enemies->first()['master_code']);
    }

    public function test_catalog_lists_all_equipment_and_current_party_equipment(): void
    {
        $response = $this->getJson('/api/test_battles/catalog')->assertOk();

        $weapons = collect($response->json('weapons'));
        $this->assertSame(15, $weapons->firstWhere('code', 'staff_master')['mag_bonus']);
        $this->assertSame(
            \App\Models\WeaponMaster::query()->count(),
            $weapons->count(),
        );

        $armors = collect($response->json('armors'));
        $this->assertSame(18, $armors->firstWhere('code', 'robe_master')['def_bonus']);
        $this->assertSame(
            \App\Models\ArmorMaster::query()->count(),
            $armors->count(),
        );

        $party = collect($response->json('party'));
        $this->assertSame(['pc1', 'pc2', 'pc3', 'pc4'], $party->pluck('slot_id')->all());
        $this->assertSame('staff_basic', $party->firstWhere('slot_id', 'pc1')['weapon_code']);
        $this->assertSame('robe_basic', $party->firstWhere('slot_id', 'pc1')['armor_code']);
    }

    public function test_free_equipment_mode_applies_unowned_equipment_only_to_that_battle(): void
    {
        $base = $this->startRegularBattle()->json('state.units');
        $baseAlly = fn (string $slot) => collect($base)->firstWhere('id', $slot);

        $response = $this->startRegularBattle([
            'equipment' => [
                'pc1' => ['weapon' => 'staff_master', 'armor' => 'robe_master'],
            ],
        ])->assertOk();
        $units = collect($response->json('state.units'));

        $pc1 = $units->firstWhere('id', 'pc1');
        $this->assertSame('staff_master', $pc1['weapon']['code']);
        $this->assertSame('robe_master', $pc1['armor']['code']);
        // 基本の杖(+1)・基本のローブ(+2) から 熟練の杖(+15)・熟練のローブ(+18) へ
        $this->assertSame($baseAlly('pc1')['mag'] + 14, $pc1['mag']);
        $this->assertSame($baseAlly('pc1')['def'] + 16, $pc1['def']);

        // 指定していない味方は所持装備のまま
        $pc2 = $units->firstWhere('id', 'pc2');
        $this->assertSame('staff_basic', $pc2['weapon']['code']);
        $this->assertSame($baseAlly('pc2')['mag'], $pc2['mag']);
        $this->assertSame($baseAlly('pc2')['def'], $pc2['def']);

        // セーブ（装備・所持装備）は変わらない
        $saved = UserCharacter::query()->with(['weapon', 'armor', 'characterMaster'])->get()
            ->firstWhere('characterMaster.code', 'pc1');
        $this->assertSame('staff_basic', $saved->weapon->code);
        $this->assertSame('robe_basic', $saved->armor->code);
        $this->assertFalse(UserCharacterOwnedWeapon::query()
            ->whereHas('weaponMaster', fn ($q) => $q->where('code', 'staff_master'))->exists());
        $this->assertFalse(UserCharacterOwnedArmor::query()
            ->whereHas('armorMaster', fn ($q) => $q->where('code', 'robe_master'))->exists());
    }

    public function test_free_equipment_mode_can_unequip_and_keep_unspecified_slot(): void
    {
        $base = collect($this->startRegularBattle()->json('state.units'))->firstWhere('id', 'pc3');

        $response = $this->startRegularBattle([
            'equipment' => [
                'pc3' => ['weapon' => null],
            ],
        ])->assertOk();
        $pc3 = collect($response->json('state.units'))->firstWhere('id', 'pc3');

        // 武器だけ外す（基本の杖 +1 が消える）。防具は指定なしなので所持装備のまま
        $this->assertNull($pc3['weapon']);
        $this->assertSame($base['mag'] - 1, $pc3['mag']);
        $this->assertSame('robe_basic', $pc3['armor']['code']);
        $this->assertSame($base['def'], $pc3['def']);
    }

    public function test_free_equipment_mode_rejects_unknown_equipment_or_slot(): void
    {
        $this->startRegularBattle(['equipment' => ['pc1' => ['weapon' => 'no_such_weapon']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipment.pc1.weapon']);

        $this->startRegularBattle(['equipment' => ['pc1' => ['armor' => 'no_such_armor']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipment.pc1.armor']);

        $this->startRegularBattle(['equipment' => ['pc9' => ['weapon' => 'staff_master']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipment']);
    }

    public function test_free_equipment_mode_also_works_for_boss_presets(): void
    {
        $response = $this->postJson('/api/test_battles/start', [
            'preset' => 'floor1_boss',
            'equipment' => ['pc4' => ['weapon' => 'staff_advanced']],
        ])->assertOk();

        $pc4 = collect($response->json('state.units'))->firstWhere('id', 'pc4');
        $this->assertSame('staff_advanced', $pc4['weapon']['code']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function startRegularBattle(array $extra = [])
    {
        return $this->postJson('/api/test_battles/start', array_merge([
            'floor' => 1,
            'code' => 'bat',
            'count' => 1,
        ], $extra));
    }

    public function test_routes_are_hidden_in_production(): void
    {
        App::detectEnvironment(static fn (): string => 'production');

        $this->getJson('/api/test_battles/catalog')->assertNotFound();
        $this->postJson('/api/test_battles/start', [
            'floor' => 1,
            'code' => 'bat',
            'count' => 1,
        ])->assertNotFound();
    }
}
