<?php

namespace Tests\Feature;

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

    public function test_start_floor2_boss_spawns_dragon_with_two_cultists(): void
    {
        $response = $this->postJson('/api/test_battles/start', [
            'preset' => 'floor2_boss',
        ])->assertOk();

        $response->assertJsonPath('boss_encounter', true);
        $response->assertJsonPath('state.meta.boss', true);
        $response->assertJsonPath('state.meta.floor', 2);

        $enemies = collect($response->json('state.units'))->where('side', 'enemy')->values();
        $this->assertSame(['mahou', 'dragon', 'mahou'], $enemies->pluck('master_code')->all());
        $this->assertSame(['邪教徒 A', 'エルドラゴン', '邪教徒 B'], $enemies->pluck('name')->all());
        // 画像名が「2_」のように数字で始まっても、そのまま使われる（2_dragon.png など）
        $this->assertSame(['2_mahou', '2_dragon', '2_mahou'], $enemies->pluck('sprite')->all());
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

    public function test_routes_are_available_in_production_while_test_mode_is_on(): void
    {
        App::detectEnvironment(static fn (): string => 'production');

        $this->assertTrue((bool) config('game.test_mode_enabled'), 'リリース前は既定でテストモードが有効');
        $this->getJson('/api/test_battles/catalog')->assertOk();
        $this->postJson('/api/test_battles/start', [
            'floor' => 1,
            'code' => 'bat',
            'count' => 1,
        ])->assertOk();
    }

    public function test_routes_are_hidden_when_test_mode_is_off(): void
    {
        config(['game.test_mode_enabled' => false]);

        $this->getJson('/api/test_battles/catalog')->assertNotFound();
        $this->postJson('/api/test_battles/start', [
            'floor' => 1,
            'code' => 'bat',
            'count' => 1,
        ])->assertNotFound();
    }
}
