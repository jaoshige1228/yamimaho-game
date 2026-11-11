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
        $chars['pc1']->update(['mp' => 40]);
        $chars['pc2']->update(['hp' => 10]);
        $chars['pc3']->update(['hp' => 10]);
        // pc4 は満タン、pc1 も満タンのまま

        $options = $this->getJson('/api/dungeon/heal/options')->assertOk()->json('spells');
        $rainbow = collect($options)->firstWhere('id', 'pc1_spirit_rainbow');
        $this->assertSame(['ally_all', 'heal_mag', 32], [$rainbow['target_type'], $rainbow['effect'], $rainbow['mp_cost']]);

        $response = $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_rainbow',
        ])->assertOk()->assertJsonPath('message', '精霊の虹を唱えた。');

        $party = collect($response->json('party'))->keyBy('id');
        $this->assertGreaterThan(10, $party['pc2']['hp']);
        $this->assertGreaterThan(10, $party['pc3']['hp']);
        $this->assertSame($party['pc4']['max_hp'], $party['pc4']['hp']);
        $this->assertSame(8, (int) $chars['pc1']->fresh()->mp, '消費MPは1回ぶん（32）だけ');
    }

    public function test_options_tell_how_much_each_spell_heals_or_what_hp_a_revive_gives(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([
            'pc1' => ['pc1_spirit_water'],
        ]);

        $options = collect($this->getJson('/api/dungeon/heal/options')->assertOk()->json('spells'))->keyBy('id');

        // 癒しの水: 実効魔力（武器込み）× 1.7
        $mag = app(\App\Services\Player\EquipmentService::class)->effectiveCombatStats($chars['pc1'])['mag'];
        $this->assertSame((int) floor($mag * 1.7), $options['pc1_heal_water']['amount']);
        $this->assertNull($options['pc1_heal_water']['revive_percent']);

        // 精霊の水（全回復）は量を持たない
        $this->assertNull($options['pc1_spirit_water']['amount']);

        // 蘇生は、蘇生後の HP の割合。宇宙樹の気配は 50%
        $this->assertSame([null, 50], [$options['pc2_world_tree']['amount'], $options['pc2_world_tree']['revive_percent']]);

        // 宇宙樹の雫（HP 全回復で蘇生。気配は忘れる）は 100%
        \App\Models\UserCharacterLearnedSpell::query()->create([
            'user_character_id' => $chars['pc2']->id,
            'spell_code' => 'pc2_world_tree_drop',
        ]);
        $options = collect($this->getJson('/api/dungeon/heal/options')->assertOk()->json('spells'))->keyBy('id');
        $this->assertSame([null, 100], [$options['pc2_world_tree_drop']['amount'], $options['pc2_world_tree_drop']['revive_percent']]);
        $this->assertArrayNotHasKey('pc2_world_tree', $options->all());
    }

    public function test_spirit_rainbow_cannot_be_used_when_nobody_is_wounded(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([
            'pc1' => ['pc1_spirit_rainbow'],
        ]);
        $chars['pc1']->update(['mp' => 40]);

        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_spirit_rainbow',
        ])->assertStatus(422)->assertJsonPath('message', '回復できる対象がいません。');

        $this->assertSame(40, (int) $chars['pc1']->fresh()->mp);
    }

    public function test_strong_mp_potion_restores_100_mp(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $pc1 = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        // 最大 MP が 100 を超えるレベルにして、MP を空にする
        $pc1->update(['level' => 25, 'mp' => 0]);
        app(ItemInventoryService::class)->add($user, 'potion_mp_l', 1);

        $response = $this->postJson('/api/dungeon/heal', [
            'mode' => 'item',
            'item_code' => 'potion_mp_l',
            'target_slot_id' => 'pc1',
        ])->assertOk();

        $party = collect($response->json('party'))->firstWhere('id', 'pc1');
        $this->assertGreaterThan(100, $party['max_mp']);
        $this->assertSame(100, $party['mp']);
    }

    public function test_saphiria_heal_spell_is_reduced_to_75_percent_from_level_20_in_the_dungeon(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $byCode = fn (string $code) => UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', $code))
            ->firstOrFail();
        // 癒しの水（倍率 1.7）。回復先は最大HPが十分大きいレベル
        $byCode('pc3')->update(['level' => 30]);
        $mag = (float) app(\App\Services\Player\EquipmentService::class)->effectiveCombatStats($byCode('pc1'))['mag'];

        foreach ([[19, 1.0], [20, 0.75]] as [$level, $penalty]) {
            $byCode('pc1')->update(['level' => $level, 'mp' => 50]);
            $byCode('pc3')->update(['hp' => 10]);
            $expected = (int) floor($mag * 1.7 * $penalty);

            // 画面に出す回復量の見込みも、実際の回復量と同じ
            $options = $this->getJson('/api/dungeon/heal/options')->assertOk();
            $listed = collect($options->json('spells'))->firstWhere('id', 'pc1_heal_water');
            $this->assertSame($expected, $listed['amount'], "Lv{$level} の見込み");

            $this->postJson('/api/dungeon/heal', [
                'mode' => 'spell',
                'caster_slot_id' => 'pc1',
                'spell_id' => 'pc1_heal_water',
                'target_slot_id' => 'pc3',
            ])->assertOk();
            $this->assertSame(10 + $expected, (int) $byCode('pc3')->hp, "Lv{$level} の回復量");
        }
    }

    public function test_spirit_water_fully_heals_and_world_tree_drop_revives_with_full_hp(): void
    {
        [, $chars] = $this->enterDungeonWithSpells([
            'pc1' => ['pc1_spirit_water'],
            'pc2' => ['pc2_world_tree_drop'],
        ]);
        $chars['pc1']->update(['mp' => 30]);
        $chars['pc2']->update(['mp' => 60]);
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
