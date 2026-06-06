<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_navigation_when_not_in_dungeon(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();

        $response = $this->getJson('/api/player/navigation')->assertOk();

        $this->assertFalse($response->json('in_dungeon'));
        $this->assertNotEmpty($response->json('active_battle_id'));
    }

    public function test_navigation_when_in_dungeon(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $response = $this->getJson('/api/player/navigation')->assertOk();

        $this->assertTrue($response->json('in_dungeon'));
    }
}
