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

    public function test_admin_routes_are_available_in_production_while_test_mode_is_on(): void
    {
        App::detectEnvironment(static fn (): string => 'production');

        $this->assertTrue((bool) config('game.test_mode_enabled'), 'リリース前は既定でテストモードが有効');
        $this->getJson('/api/admins/status')->assertOk();
        $this->postJson('/api/admins/grant-exp', ['exp' => 50])->assertOk();
    }

    public function test_admin_routes_are_hidden_when_test_mode_is_off(): void
    {
        config(['game.test_mode_enabled' => false]);

        $this->getJson('/api/admins/status')->assertNotFound();
        $this->postJson('/api/admins/grant-exp', ['exp' => 50])->assertNotFound();
        $this->getJson('/api/admins/dungeon-settings')->assertNotFound();
    }
}
