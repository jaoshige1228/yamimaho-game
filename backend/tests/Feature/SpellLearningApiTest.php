<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserCharacterLearnedSpell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * イフリーヌを初めて倒したとき、パーティ全員が新しい魔法を覚える。
 */
class SpellLearningApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_PC1 = ['pc1_heal_water', 'pc1_water_blade', 'pc1_water_veil'];

    private const ALL_PC1 = ['pc1_heal_water', 'pc1_holy_water', 'pc1_water_blade', 'pc1_water_veil', 'pc1_water_veil_all'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_new_spells_are_not_known_at_first(): void
    {
        $start = $this->postJson('/api/battles/demo-boss')->assertOk();

        $this->assertSame(self::BASE_PC1, $this->allySpells($start->json('battle_id'), 'pc1'));
        $this->assertSame(0, UserCharacterLearnedSpell::query()->count());
    }

    public function test_defeating_ifrine_the_first_time_teaches_everyone_new_spells_once(): void
    {
        $first = $this->winIfrineBattle();
        $learned = collect($first['events'])->firstWhere('type', 'spells_learned');

        $this->assertNotNull($learned);
        $this->assertSame(
            ['サフィリア', 'ガルデ', 'グレン', 'シエナ'],
            array_column($learned['characters'], 'name'),
        );
        $this->assertSame(['pc1', 'pc2', 'pc3', 'pc4'], array_column($learned['characters'], 'slot_id'));
        $this->assertSame(
            [['神聖の水', '水の幻影群'], ['爆樹', '宇宙樹の息吹'], ['ブラックホール', 'ラグオンビート'], ['岩落とし', '土の城壁']],
            array_map(fn (array $c) => array_column($c['spells'], 'label'), $learned['characters']),
        );
        $this->assertSame(8, UserCharacterLearnedSpell::query()->count());

        // 次の戦闘から使える。並び順は魔法ごとの sort_order どおり
        $next = $this->postJson('/api/battles/demo-boss')->assertOk();
        $this->assertSame(self::ALL_PC1, $this->allySpells($next->json('battle_id'), 'pc1'));
        $this->assertSame(
            ['pc2_vine_whip', 'pc2_vine_bind', 'pc2_burst_tree', 'pc2_world_tree', 'pc2_world_tree_breath'],
            $this->allySpells($next->json('battle_id'), 'pc2'),
        );
        $this->assertSame(
            ['pc3_fire_shot', 'pc3_fire_burst', 'pc3_fire_wave', 'pc3_black_hole', 'pc3_ragaon_beat'],
            $this->allySpells($next->json('battle_id'), 'pc3'),
        );
        $this->assertSame(
            ['pc4_stone_shot', 'pc4_rock_fall', 'pc4_earth_armor', 'pc4_earth_wall', 'pc4_shield_magic'],
            $this->allySpells($next->json('battle_id'), 'pc4'),
        );

        // 2 回目以降に倒しても、覚え直しにはならない
        $second = $this->winIfrineBattle($next->json('battle_id'));
        $this->assertNull(collect($second['events'])->firstWhere('type', 'spells_learned'));
        $this->assertSame(8, UserCharacterLearnedSpell::query()->count());
    }

    public function test_defeating_other_enemies_teaches_nothing(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $result = $this->winAgainst($start->json('battle_id'));

        $this->assertSame('victory', $result['state']['status']);
        $this->assertNull(collect($result['events'])->firstWhere('type', 'spells_learned'));
        $this->assertSame(0, UserCharacterLearnedSpell::query()->count());
    }

    public function test_test_battle_against_ifrine_teaches_spells_too(): void
    {
        $start = $this->postJson('/api/test_battles/start', ['preset' => 'floor1_boss'])->assertOk();
        $result = $this->winAgainst($start->json('battle_id'));

        $this->assertNotNull(collect($result['events'])->firstWhere('type', 'spells_learned'));
        $this->assertSame(8, UserCharacterLearnedSpell::query()->count());
    }

    public function test_reset_forgets_learned_spells(): void
    {
        $this->winIfrineBattle();
        $this->assertSame(8, UserCharacterLearnedSpell::query()->count());

        $this->postJson('/api/reset')->assertOk();

        $this->assertSame(0, UserCharacterLearnedSpell::query()->count());
        $start = $this->postJson('/api/battles/demo-boss')->assertOk();
        $this->assertSame(self::BASE_PC1, $this->allySpells($start->json('battle_id'), 'pc1'));

        // リセット後にもう一度倒せば、また覚える
        $again = $this->winIfrineBattle($start->json('battle_id'));
        $this->assertNotNull(collect($again['events'])->firstWhere('type', 'spells_learned'));
    }

    public function test_first_ifrine_defeat_adds_ten_max_mp_to_pc1_only_and_says_nothing(): void
    {
        $first = $this->winIfrineBattle();

        $this->assertSame(['pc1' => 10, 'pc2' => 0, 'pc3' => 0, 'pc4' => 0], $this->maxMpBonuses());
        $this->assertSame($this->expectedMaxMp(), $this->partyMaxMp());

        // 文言・イベントは増やさない
        $this->assertStringNotContainsString('max_mp', json_encode($first['events']));
        $this->assertStringNotContainsString('bonus', json_encode($first['events']));
        $learned = collect($first['events'])->firstWhere('type', 'spells_learned');
        $this->assertSame(['type', 'characters'], array_keys($learned));

        // 2 回目以降は増えない
        $this->winIfrineBattle();
        $this->assertSame(['pc1' => 10, 'pc2' => 0, 'pc3' => 0, 'pc4' => 0], $this->maxMpBonuses());
        $this->assertSame($this->expectedMaxMp(), $this->partyMaxMp());
    }

    public function test_max_mp_bonus_applies_to_battles_full_restore_and_level_ups(): void
    {
        $this->winIfrineBattle();

        // 次の戦闘の最大MP
        $next = $this->postJson('/api/battles/demo-boss')->assertOk();
        $units = BattleState::query()->findOrFail($next->json('battle_id'))->state['units'];
        $this->assertSame($this->expectedMaxMp()['pc1'], $units['pc1']['max_mp']);
        $this->assertSame($this->expectedMaxMp()['pc2'], $units['pc2']['max_mp']);

        // 全回復すると、加算分を含む最大値まで回復する
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        UserCharacter::query()->where('user_id', $user->id)->update(['mp' => 0]);
        app(\App\Services\Player\UserCharacterService::class)->restorePartyToFull($user);
        $pc1 = UserCharacter::query()->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))->firstOrFail();
        $this->assertSame($this->expectedMaxMp()['pc1'], (int) $pc1->mp);

        // レベルが上がっても、加算分は残る
        $this->postJson('/api/admins/grant-exp', ['exp' => 5000])->assertOk();
        $this->assertSame(10, $this->maxMpBonuses()['pc1']);
        $this->assertSame($this->expectedMaxMp(), $this->partyMaxMp());
        $this->assertGreaterThan(
            app(\App\Services\Growth\LevelGrowthService::class)->statsForLevel($pc1->characterMaster, 1)['mp'] + 10,
            $this->partyMaxMp()['pc1'],
        );
    }

    public function test_reset_removes_max_mp_bonus_and_it_is_granted_again(): void
    {
        $this->winIfrineBattle();
        $this->postJson('/api/reset')->assertOk();

        $this->assertSame(['pc1' => 0, 'pc2' => 0, 'pc3' => 0, 'pc4' => 0], $this->maxMpBonuses());
        $this->assertSame($this->expectedMaxMp(), $this->partyMaxMp());

        $this->winIfrineBattle();
        $this->assertSame(10, $this->maxMpBonuses()['pc1']);
    }

    public function test_defeating_other_enemies_gives_no_max_mp_bonus(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $this->winAgainst($start->json('battle_id'));

        $this->assertSame(['pc1' => 0, 'pc2' => 0, 'pc3' => 0, 'pc4' => 0], $this->maxMpBonuses());
    }

    public function test_learned_spells_show_up_as_dungeon_heal_options_with_their_own_multiplier(): void
    {
        $this->winIfrineBattle();

        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();
        $options = $this->getJson('/api/dungeon/heal/options')->assertOk();
        $healSpells = collect($options->json('spells'));
        $this->assertSame(
            ['pc1_heal_water', 'pc1_holy_water'],
            $healSpells->where('caster_slot_id', 'pc1')->pluck('id')->values()->all(),
        );
        $this->assertSame(
            ['pc2_world_tree', 'pc2_world_tree_breath'],
            $healSpells->where('caster_slot_id', 'pc2')->pluck('id')->values()->all(),
        );

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $party = UserCharacter::query()->where('user_id', $user->id)->get()
            ->keyBy(fn (UserCharacter $c) => $c->characterMaster->code);
        $party['pc1']->update(['hp' => $party['pc1']->hp, 'mp' => 30]);
        $party['pc3']->update(['hp' => 10]);

        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_holy_water',
            'target_slot_id' => 'pc3',
        ])->assertOk();

        $mag = app(\App\Services\Player\EquipmentService::class)->effectiveCombatStats($party['pc1'])['mag'];
        $this->assertSame(10 + (int) floor($mag * 3.5), (int) $party['pc3']->fresh()->hp);
        $this->assertSame(18, (int) $party['pc1']->fresh()->mp);
    }

    public function test_unlearned_spell_cannot_be_used_in_dungeon(): void
    {
        $this->postJson('/api/battles/demo')->assertOk();
        BattleState::query()->delete();
        $this->postJson('/api/dungeon/enter', ['floor' => 1])->assertOk();

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        UserCharacter::query()->where('user_id', $user->id)->update(['mp' => 30]);
        UserCharacter::query()->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc3'))
            ->update(['hp' => 10]);

        $this->postJson('/api/dungeon/heal', [
            'mode' => 'spell',
            'caster_slot_id' => 'pc1',
            'spell_id' => 'pc1_holy_water',
            'target_slot_id' => 'pc3',
        ])->assertStatus(422)->assertJsonPath('message', 'その魔法は使えません。');
    }

    /**
     * @return array<string, int>  スロット => 加算された最大MP
     */
    private function maxMpBonuses(): array
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        return UserCharacter::query()->where('user_id', $user->id)->with('characterMaster')->get()
            ->mapWithKeys(fn (UserCharacter $c) => [$c->characterMaster->code => (int) $c->max_mp_bonus])
            ->sortKeys()
            ->all();
    }

    /**
     * 現在のレベルと加算分から期待される最大MP（スロット => 値）。
     *
     * @return array<string, int>
     */
    private function expectedMaxMp(): array
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $growth = app(\App\Services\Growth\LevelGrowthService::class);

        return UserCharacter::query()->where('user_id', $user->id)->with('characterMaster')->get()
            ->mapWithKeys(fn (UserCharacter $c) => [
                $c->characterMaster->code => $growth->statsForLevel($c->characterMaster, $c->level)['mp'] + (int) $c->max_mp_bonus,
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * API が返す最大MP（スロット => 値）。
     *
     * @return array<string, int>
     */
    private function partyMaxMp(): array
    {
        return collect($this->getJson('/api/player/party')->assertOk()->json('characters'))
            ->mapWithKeys(fn (array $c) => [$c['id'] => $c['max_mp']])
            ->sortKeys()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function allySpells(string $battleId, string $slot): array
    {
        return BattleState::query()->findOrFail($battleId)->state['units'][$slot]['spells'];
    }

    /**
     * イフリーヌ戦を始めて（または続けて）倒す。
     *
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function winIfrineBattle(?string $battleId = null): array
    {
        $battleId ??= $this->postJson('/api/battles/demo-boss')->assertOk()->json('battle_id');

        return $this->winAgainst($battleId);
    }

    /**
     * 敵の HP を 1 にして、こぶしで倒しきる。
     *
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function winAgainst(string $battleId): array
    {
        $record = BattleState::query()->findOrFail($battleId);
        $state = $record->state;
        foreach ($state['units'] as $id => $unit) {
            if ($unit['side'] === 'enemy') {
                $state['units'][$id]['hp'] = 1;
                $state['units'][$id]['evasion_rate'] = 0;
            }
        }
        $record->state = $state;
        $record->save();

        $response = null;
        for ($i = 0; $i < 12; $i++) {
            $state = BattleState::query()->findOrFail($battleId)->state;
            if ($state['status'] !== 'active') {
                break;
            }
            $enemy = collect($state['units'])->where('side', 'enemy')->where('alive', true)->first();
            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'punch',
                'target_id' => $enemy['id'],
            ])->assertOk();
        }

        $this->assertNotNull($response);
        $this->assertSame('victory', $response->json('state.status'));

        return ['state' => $response->json('state'), 'events' => $response->json('events')];
    }
}
