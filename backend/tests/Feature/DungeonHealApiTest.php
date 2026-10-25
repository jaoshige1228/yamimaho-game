<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use App\Services\Player\ItemInventoryService;
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

        app(ItemInventoryService::class)->add($user, 'potion_hp_s', 1);

        $response = $this->postJson('/api/dungeon/heal', [
            'mode' => 'item',
            'item_code' => 'potion_hp_s',
            'target_slot_id' => 'pc1',
        ])->assertOk();

        $pc1 = collect($response->json('party'))->firstWhere('id', 'pc1');
        $this->assertGreaterThan(10, $pc1['hp']);
    }

    public function test_dead_caster_cannot_heal_with_spell(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $caster = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        $target = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc2'))
            ->firstOrFail();

        $caster->update(['hp' => 0, 'mp' => 30]);
        $target->update(['hp' => 10]);

        $options = $this->getJson('/api/dungeon/heal/options')->assertOk();
        $casterSpells = collect($options->json('spells'))
            ->where('caster_slot_id', 'pc1');
        $this->assertNotEmpty($casterSpells);
        $this->assertTrue($casterSpells->every(fn (array $spell) => $spell['affordable'] === false));

        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => $casterSpells->first()['id'],
            'target_slot_id' => 'pc2',
        ])->assertStatus(422)
            ->assertJsonPath('message', '戦闘不能のキャラクターは魔法を使えません。');
    }
}
