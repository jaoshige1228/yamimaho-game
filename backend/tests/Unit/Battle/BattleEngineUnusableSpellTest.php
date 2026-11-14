<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 戦闘中のコマンドは、今は唱えられない魔法（MP が足りない・回復する相手がいない・蘇生する相手がいない…）も選べる（2026-11-14）。
 * このゲームは、魔法を選ばないと「どんな魔法か」が分からない（選んだあとの画面に、説明が出る）ため。
 *
 * サーバーは、唱えられない魔法の一覧に `unusable_reason`（理由）を付けて返す。画面は、選んだあとに理由を出して、
 * 対象を選ぶ・唱えることだけをできなくする。実際に唱えようとした行動は、今までどおりサーバーが受け付けない。
 */
class BattleEngineUnusableSpellTest extends TestCase
{
    public function test_a_spell_with_no_wounded_ally_is_listed_with_a_reason_but_still_rejected_when_cast(): void
    {
        $state = $this->state('pc1', ['pc1_heal_water', 'pc1_spirit_rainbow']); // 単体回復・全体回復
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = 500; // 全員満タン
        }

        foreach (['pc1_heal_water', 'pc1_spirit_rainbow'] as $code) {
            $listed = $this->command($state, $code);
            $this->assertFalse($listed['usable'], $code);
            $this->assertSame('回復できる味方がいません', $listed['unusable_reason'], $code);
            $this->assertTrue($listed['affordable'], '理由は MP ではない');
        }

        // 選べても、唱える行動はサーバーが受け付けない（対象を選ぶ画面で、何も選べない）
        $this->expectException(InvalidArgumentException::class);
        $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc2');
    }

    public function test_a_revive_spell_with_nobody_down_is_listed_with_a_reason(): void
    {
        $state = $this->state('pc2', ['pc2_world_tree_drop']);

        $listed = $this->command($state, 'pc2_world_tree_drop', 'pc2');

        $this->assertFalse($listed['usable']);
        $this->assertSame('戦闘不能の味方がいません', $listed['unusable_reason']);

        // 倒れた味方がいれば、唱えられる
        $state['units']['pc3']['hp'] = 0;
        $state['units']['pc3']['alive'] = false;
        $listed = $this->command($state, 'pc2_world_tree_drop', 'pc2');
        $this->assertTrue($listed['usable']);
        $this->assertNull($listed['unusable_reason']);
    }

    public function test_a_spell_that_costs_more_mp_than_the_caster_has_is_listed_with_a_reason(): void
    {
        $state = $this->state('pc1', ['pc1_heal_water']);
        $state['units']['pc2']['hp'] = 100; // 傷ついた味方はいる（対象はある）
        $state['units']['pc1']['mp'] = 1;

        $listed = $this->command($state, 'pc1_heal_water');

        $this->assertFalse($listed['affordable']);
        $this->assertFalse($listed['usable']);
        $this->assertSame('MPが足りません', $listed['unusable_reason']);
    }

    public function test_the_self_cast_spell_gives_its_own_reason(): void
    {
        // 魔力集中: HP が足りない（HP が 201 未満）／MP が満タン
        $state = $this->state('pc3', ['pc3_mana_focus']);
        $state['units']['pc3']['hp'] = 150;
        $state['units']['pc3']['mp'] = 10;
        $this->assertSame('HPが足りません', $this->command($state, 'pc3_mana_focus', 'pc3')['unusable_reason']);

        $full = $this->state('pc3', ['pc3_mana_focus']);
        $this->assertSame('MPが満タンです', $this->command($full, 'pc3_mana_focus', 'pc3')['unusable_reason']);

        $ok = $this->state('pc3', ['pc3_mana_focus']);
        $ok['units']['pc3']['hp'] = 400;
        $ok['units']['pc3']['mp'] = 10;
        $listed = $this->command($ok, 'pc3_mana_focus', 'pc3');
        $this->assertTrue($listed['usable']);
        $this->assertNull($listed['unusable_reason']);
    }

    public function test_usable_spells_have_no_reason(): void
    {
        $state = $this->state('pc1', ['pc1_heal_water', 'pc1_water_veil']);
        $state['units']['pc2']['hp'] = 100;

        $this->assertNull($this->command($state, 'pc1_heal_water')['unusable_reason']);
        $this->assertNull($this->command($state, 'pc1_water_veil')['unusable_reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function command(array $state, string $code, string $actor = 'pc1'): array
    {
        $state['current_actor'] = $actor;
        $state['awaiting_input'] = true;

        return collect($this->engine()->publicState($state)['commands']['spells'])->firstWhere('id', $code);
    }

    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * @param  list<string>  $spells
     * @return array<string, mixed>
     */
    private function state(string $actor, array $spells): array
    {
        $ally = static fn (string $id, string $name, int $spd, array $spells) => [
            'id' => $id, 'name' => $name, 'sprite' => 'test', 'side' => 'ally', 'master_code' => $id, 'level' => 10,
            'hp' => 500, 'max_hp' => 500, 'mp' => 300, 'max_mp' => 300,
            'str' => 100, 'mag' => 100, 'def' => 100, 'pdef' => 100, 'mdef' => 100, 'spd' => $spd,
            'crit_rate' => 0, 'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true,
            'buffs' => [], 'stages' => [], 'statuses' => [], 'spells' => $spells,
        ];
        $units = [
            'pc1' => $ally('pc1', 'サフィリア', 40, $actor === 'pc1' ? $spells : []),
            'pc2' => $ally('pc2', 'ガルデ', 30, $actor === 'pc2' ? $spells : []),
            'pc3' => $ally('pc3', 'グレン', 20, $actor === 'pc3' ? $spells : []),
            'pc4' => $ally('pc4', 'シエナ', 10, $actor === 'pc4' ? $spells : []),
            'enemy_1' => [
                'id' => 'enemy_1', 'name' => '敵', 'sprite' => 'test', 'side' => 'enemy', 'master_code' => 'bat',
                'hp' => 9000, 'max_hp' => 9000, 'mp' => 0, 'max_mp' => 0,
                'str' => 100, 'mag' => 100, 'def' => 100, 'pdef' => 100, 'mdef' => 100, 'spd' => 1,
                'crit_rate' => 0, 'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true,
                'buffs' => [], 'stages' => [], 'statuses' => [], 'spells' => [],
            ],
        ];

        return [
            'status' => 'active', 'current_actor' => $actor, 'awaiting_input' => true,
            'turn_order' => array_keys($units), 'turn_index' => 0, 'units' => $units, 'log' => [],
        ];
    }
}
