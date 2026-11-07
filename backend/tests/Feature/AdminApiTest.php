<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_admin_status_is_available_in_local(): void
    {
        $this->getJson('/api/admins/status')->assertOk()->assertJson(['available' => true]);
    }

    public function test_grant_exp_updates_all_party_members(): void
    {
        $this->getJson('/api/admins/party')->assertOk();

        $response = $this->postJson('/api/admins/grant-exp', ['exp' => 50]);

        $response->assertOk()
            ->assertJsonPath('characters.0.level', 2)
            ->assertJsonPath('characters.0.exp', 50);

        $this->assertSame(4, count($response->json('characters')));
    }

    public function test_grant_exp_can_skip_multiple_levels(): void
    {
        $response = $this->postJson('/api/admins/grant-exp', ['exp' => 166]);

        $response->assertOk()
            ->assertJsonPath('characters.0.level', 4)
            ->assertJsonPath('characters.0.levels_gained', 3);
    }

    public function test_admin_equipment_lists_all_gear_and_the_party(): void
    {
        $response = $this->getJson('/api/admins/equipment')->assertOk();

        // 武器・防具マスターが全部（増やしても自動で載る）、補正の小さい順に並ぶ
        $this->assertSame(
            \App\Models\WeaponMaster::query()->count(),
            count($response->json('weapons')),
        );
        $this->assertSame(\App\Models\ArmorMaster::query()->count(), count($response->json('armors')));
        foreach (['staff_basic', 'staff_advanced', 'staff_master'] as $code) {
            $this->assertContains($code, array_column($response->json('weapons'), 'code'));
        }
        foreach (['robe_basic', 'robe_advanced', 'robe_master'] as $code) {
            $this->assertContains($code, array_column($response->json('armors'), 'code'));
        }
        $weaponBonuses = array_column($response->json('weapons'), 'mag_bonus');
        $sorted = $weaponBonuses;
        sort($sorted);
        $this->assertSame($sorted, $weaponBonuses);
        $this->assertSame(1, $response->json('weapons.0.mag_bonus'));

        $characters = $response->json('characters');
        $this->assertSame(['pc1', 'pc2', 'pc3', 'pc4'], array_column($characters, 'slot_id'));
        $this->assertSame('staff_basic', $characters[0]['weapon_code']);
        $this->assertSame('robe_basic', $characters[0]['armor_code']);
    }

    public function test_admin_can_equip_gear_the_character_does_not_own(): void
    {
        $this->getJson('/api/admins/equipment')->assertOk();

        $response = $this->postJson('/api/admins/equipment', [
            'slot_id' => 'pc2',
            'weapon_code' => 'staff_master',
            'armor_code' => 'robe_master',
        ])->assertOk();

        $response->assertJsonPath('characters.1.weapon_code', 'staff_master')
            ->assertJsonPath('characters.1.armor_code', 'robe_master');
        $this->assertStringContainsString('ガルデ', $response->json('message'));
        // ほかのキャラは変わらない
        $response->assertJsonPath('characters.0.weapon_code', 'staff_basic');

        // 保存され、プレイヤーの装備画面にも出る（所持扱いになるので、あとで付け直せる）
        $equipment = collect($this->getJson('/api/player/equipment')->assertOk()->json('characters'))->firstWhere('slot_id', 'pc2');
        $this->assertSame('staff_master', $equipment['equipped_weapon']['code']);
        $this->assertSame(20, $equipment['equipped_weapon']['mag_bonus']);
        $this->assertSame('robe_master', $equipment['equipped_armor']['code']);
        $this->assertContains('staff_master', array_column($equipment['owned_weapons'], 'code'));
        $this->assertContains('robe_master', array_column($equipment['owned_armors'], 'code'));

        // 次にパーティを取得しても初期装備に戻らない
        $this->getJson('/api/admins/equipment')->assertOk()->assertJsonPath('characters.1.weapon_code', 'staff_master');
    }

    public function test_admin_can_change_only_the_weapon_or_only_the_armor(): void
    {
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc3', 'armor_code' => 'robe_advanced'])
            ->assertOk()
            ->assertJsonPath('characters.2.weapon_code', 'staff_basic')
            ->assertJsonPath('characters.2.armor_code', 'robe_advanced');

        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc3', 'weapon_code' => 'staff_advanced'])
            ->assertOk()
            ->assertJsonPath('characters.2.weapon_code', 'staff_advanced')
            ->assertJsonPath('characters.2.armor_code', 'robe_advanced');
    }

    public function test_admin_can_go_back_to_the_basic_gear(): void
    {
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1', 'weapon_code' => 'staff_master', 'armor_code' => 'robe_master'])->assertOk();

        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1', 'weapon_code' => 'staff_basic', 'armor_code' => 'robe_basic'])
            ->assertOk()
            ->assertJsonPath('characters.0.weapon_code', 'staff_basic')
            ->assertJsonPath('characters.0.armor_code', 'robe_basic');
    }

    public function test_admin_equip_rejects_unknown_input(): void
    {
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc9', 'weapon_code' => 'staff_master'])->assertUnprocessable();
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1', 'weapon_code' => 'no_such_staff'])->assertUnprocessable();
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1', 'armor_code' => 'no_such_robe'])->assertUnprocessable();
        // 何も指定しない／装備なし（null）だけの指定はできない
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1'])->assertUnprocessable();
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1', 'weapon_code' => null])->assertUnprocessable();

        $this->getJson('/api/admins/equipment')->assertOk()->assertJsonPath('characters.0.weapon_code', 'staff_basic');
    }

    public function test_admin_routes_are_available_in_production_while_test_mode_is_on(): void
    {
        App::detectEnvironment(static fn (): string => 'production');

        $this->assertTrue((bool) config('game.test_mode_enabled'), 'リリース前は既定でテストモードが有効');
        $this->getJson('/api/admins/status')->assertOk();
        $this->postJson('/api/admins/grant-exp', ['exp' => 50])->assertOk();
        $this->getJson('/api/admins/equipment')->assertOk();
    }

    public function test_admin_routes_are_hidden_when_test_mode_is_off(): void
    {
        config(['game.test_mode_enabled' => false]);

        $this->getJson('/api/admins/status')->assertNotFound();
        $this->postJson('/api/admins/grant-exp', ['exp' => 50])->assertNotFound();
        $this->getJson('/api/admins/dungeon-settings')->assertNotFound();
        $this->getJson('/api/admins/equipment')->assertNotFound();
        $this->postJson('/api/admins/equipment', ['slot_id' => 'pc1', 'weapon_code' => 'staff_master'])->assertNotFound();
    }
}
