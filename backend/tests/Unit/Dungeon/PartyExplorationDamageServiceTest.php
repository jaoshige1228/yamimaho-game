<?php

namespace Tests\Unit\Dungeon;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Dungeon\PartyExplorationDamageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyExplorationDamageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_pick_random_alive_slot_excludes_multiple_slots(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $service = new PartyExplorationDamageService;

        foreach (UserCharacter::query()->where('user_id', $user->id)->get() as $character) {
            $character->hp = 100;
            $character->save();
        }

        for ($i = 0; $i < 20; $i++) {
            $picked = $service->pickRandomAliveSlot($user, ['pc1', 'pc2']);
            $this->assertContains($picked, ['pc3', 'pc4']);
        }
    }
}
