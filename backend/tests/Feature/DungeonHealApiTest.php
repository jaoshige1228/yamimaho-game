<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserItem;
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

    public function test_hp_heal_spell_and_item_do_not_work_on_a_fallen_target(): void
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

        $caster->update(['mp' => 30]);
        $target->update(['hp' => 0]);
        app(ItemInventoryService::class)->add($user, 'potion_hp_s', 1);
        $potionCount = fn (): int => (int) UserItem::query()
            ->where('user_id', $user->id)
            ->whereHas('itemMaster', fn ($q) => $q->where('code', 'potion_hp_s'))
            ->value('quantity');
        $before = $potionCount();
        $this->assertGreaterThanOrEqual(1, $before);

        // 魔法: 戦闘不能の対象には回復できず、MPも減らない
        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_heal_water',
            'target_slot_id' => 'pc2',
        ])->assertStatus(422)->assertJsonPath('message', '戦闘不能の対象には回復できません。');

        // アイテム: 同様に使えず、消費もされない
        $this->postJson('/api/dungeon/heal', [
            'mode' => 'item',
            'item_code' => 'potion_hp_s',
            'target_slot_id' => 'pc2',
        ])->assertStatus(422)->assertJsonPath('message', '戦闘不能の対象には回復できません。');

        $this->assertSame(0, (int) $target->fresh()->hp);
        $this->assertSame(30, (int) $caster->fresh()->mp);
        $this->assertSame($before, $potionCount());
    }

    public function test_spirit_rainbow_heals_every_wounded_member_without_choosing_a_target(): void
    {
        [$user, $chars] = $this->enterDungeonWithSpells([
            'pc1' => ['pc1_spirit_rainbow'],
        ]);
        $chars['pc1']->update(['mp' => 30]);
        $chars['pc2']->update(['hp' => 10]);
        $chars['pc3']->update(['hp' => 10]);
        // pc4 は満タン、pc1 も満タンのまま

        $options = $this->getJson('/api/dungeon/heal/options')->assertOk()->json('spells');
        $rainbow = collect($options)->firstWhere('id', 'pc1_spirit_rainbow');
        $this->assertSame(['ally_all', 'heal_mag', 26], [$rainbow['target_type'], $rainbow['effect'], $rainbow['mp_cost']]);

        $response = $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_rainbow',
        ])->assertOk()->assertJsonPath('message', '精霊の虹を唱えた。');

        $party = collect($response->json('party'))->keyBy('id');
        $this->assertGreaterThan(10, $party['pc2']['hp']);
        $this->assertGreaterThan(10, $party['pc3']['hp']);
        $this->assertSame($party['pc4']['max_hp'], $party['pc4']['hp']);
        $this->assertSame(4, (int) $chars['pc1']->fresh()->mp, '消費MPは1回ぶん（26）だけ');
    }

    public function test_spirit_rainbow_cannot_be_used_when_nobody_is_wounded(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([
            'pc1' => ['pc1_spirit_rainbow'],
        ]);
        $chars['pc1']->update(['mp' => 30]);

        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_rainbow',
        ])->assertStatus(422)->assertJsonPath('message', '回復できる対象がいません。');

        $this->assertSame(30, (int) $chars['pc1']->fresh()->mp);
    }

    public function test_spirit_water_fully_heals_and_world_tree_drop_revives_with_full_hp(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([
            'pc1' => ['pc1_spirit_water'],
            'pc2' => ['pc2_world_tree_drop'],
        ]);
        $chars['pc1']->update(['mp' => 30]);
        $chars['pc2']->update(['mp' => 50]);
        $chars['pc3']->update(['hp' => 1]);
        $chars['pc4']->update(['hp' => 0]);

        $healed = $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_water',
            'target_slot_id' => 'pc3',
        ])->assertOk();
        $pc3 = collect($healed->json('party'))->firstWhere('id', 'pc3');
        $this->assertSame($pc3['max_hp'], $pc3['hp']);

        // 全回復の魔法でも、倒れた味方には使えない
        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_water',
            'target_slot_id' => 'pc4',
        ])->assertStatus(422);

        $revived = $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc2',
            'spell_id' => 'pc2_world_tree_drop',
            'target_slot_id' => 'pc4',
        ])->assertOk();
        $pc4 = collect($revived->json('party'))->firstWhere('id', 'pc4');
        $this->assertSame($pc4['max_hp'], $pc4['hp'], 'HP全回復で蘇生する');
        $this->assertSame(10, (int) $chars['pc2']->fresh()->mp);
    }

    public function test_quick_brew_heals_in_the_dungeon_and_is_not_called_a_spell(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([
            'pc2' => ['pc2_quick_brew'],
        ]);
        $chars['pc2']->update(['mp' => 30]);
        $chars['pc3']->update(['hp' => 1]);

        $response = $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc2',
            'spell_id' => 'pc2_quick_brew',
            'target_slot_id' => 'pc3',
        ])->assertOk()->assertJsonPath('message', '迅速調合を使った。');

        $this->assertGreaterThan(1, collect($response->json('party'))->firstWhere('id', 'pc3')['hp']);
        $this->assertSame(16, (int) $chars['pc2']->fresh()->mp);
    }

    public function test_unlearned_new_spell_cannot_be_used_in_the_dungeon(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([]);
        $chars['pc1']->update(['mp' => 30]);
        $chars['pc2']->update(['hp' => 10]);

        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_water',
            'target_slot_id' => 'pc2',
        ])->assertStatus(422)->assertJsonPath('message', 'その魔法は使えません。');
    }

    /**
     * ダンジョンに入った状態にして、指定した魔法を覚えさせる。
     *
     * @param  array<string, list<string>>  $learned  キャラのコード => 覚えさせる魔法
     * @return array{0: User, 1: array<string, UserCharacter>}
     */
    private function enterDungeonWithSpells(array $learned): array
    {
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $chars = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $code) {
            $chars[$code] = UserCharacter::query()
                ->where('user_id', $user->id)
                ->whereHas('characterMaster', fn ($q) => $q->where('code', $code))
                ->firstOrFail();
        }
        foreach ($learned as $code => $spellIds) {
            foreach ($spellIds as $spellId) {
                \App\Models\UserCharacterLearnedSpell::query()->create([
                    'user_character_id' => $chars[$code]->id,
                    'spell_code' => $spellId,
                ]);
            }
        }

        return [$user, $chars];
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
