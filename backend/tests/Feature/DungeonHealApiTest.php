<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonHealApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_heal_with_item_in_dungeon(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $character = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        $character->hp = 10;
        $character->save();

        $response = $this->postJson('/api/dungeon/heal', [
            'mode' => 'item',
            'item_code' => 'potion_hp_s',
            'target_slot_id' => 'pc1',
        ])->assertOk();

        $pc1 = collect($response->json('party'))->firstWhere('id', 'pc1');
        $this->assertGreaterThan(10, $pc1['hp']);
    }
}
