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
