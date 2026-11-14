<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\SpellHealing;
use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

/**
 * サフィリアの癒しの水（pc1_heal_water）は、レベル 10 以下のときだけ、回復量（魔力 × 係数 1.7）が 1.3 倍。
 * さらにレベル 5 以下のときだけ、もう 1.5 倍（Lv5 以下は 1.3 × 1.5 = 1.95 倍。2026-11-14）。
 * Lv11 以上は元の倍率。ほかの回復魔法・全回復・使い魔の回復には掛からない。
 */
class HealWaterLowLevelBonusTest extends TestCase
{
    public function test_the_bonus_is_configured_for_heal_water_up_to_level_10(): void
    {
        $this->assertSame(
            [['max_level' => 10, 'multiplier' => 1.3], ['max_level' => 5, 'multiplier' => 1.5]],
            config('battle.heal_mag_spell_level_bonus.pc1_heal_water'),
        );
        $this->assertSame(1.7, (new MasterDataProvider)->findSpell('pc1_heal_water')['coefficient'], '係数そのものは変えていない');
    }

    public function test_heal_amount_formula_boosts_only_heal_water_at_level_10_or_below(): void
    {
        $water = (new MasterDataProvider)->findSpell('pc1_heal_water'); // 係数 1.7
        $holy = (new MasterDataProvider)->findSpell('pc1_holy_water'); // 係数 2.5

        // Lv6〜10: 100 × 1.7 × 1.3 = 221
        foreach ([6, 9, 10] as $level) {
            $this->assertSame(221, SpellHealing::healMagAmount(100.0, $water, 'pc1', $level, 'pc1_heal_water'), "Lv{$level}");
        }
        // Lv5 以下は、さらに 1.5 倍（1.3 × 1.5 = 1.95 倍）: 100 × 1.7 × 1.95 = 331.5 → 331
        foreach ([1, 3, 5] as $level) {
            $this->assertSame(331, SpellHealing::healMagAmount(100.0, $water, 'pc1', $level, 'pc1_heal_water'), "Lv{$level}");
        }
        // Lv11 以上は 100 × 1.7 = 170（Lv22 以上は、これまでどおり 0.75 倍で 127。補正は重ならない）
        foreach ([[11, 170], [20, 170], [21, 170], [22, 127], [40, 127]] as [$level, $expected]) {
            $this->assertSame($expected, SpellHealing::healMagAmount(100.0, $water, 'pc1', $level, 'pc1_heal_water'), "Lv{$level}");
        }

        // 補正の掛かり方: Lv5 以下は 1.95 倍、Lv6〜10 は 1.3 倍、Lv11 以上は 1.0 倍
        foreach ([[5, 1.95], [6, 1.3], [10, 1.3], [11, 1.0]] as [$level, $expected]) {
            $this->assertEqualsWithDelta($expected, SpellHealing::spellLevelBonus('pc1_heal_water', $level), 0.0000001, "Lv{$level}");
        }

        // 魔法のコードを渡さなければ、魔法ごとの補正は掛からない（使い魔の回復などの呼び方）
        $this->assertSame(170, SpellHealing::healMagAmount(100.0, $water, 'pc1', 5));
        $this->assertSame(170, SpellHealing::healMagAmount(100.0, ['coefficient' => 1.7], null, 5));

        // ほかの回復魔法（神聖の水）は、低レベルでも変わらない
        $this->assertSame(250, SpellHealing::healMagAmount(100.0, $holy, 'pc1', 5, 'pc1_holy_water'));
        $this->assertSame(250, SpellHealing::healMagAmount(100.0, $holy, 'pc1', 10, 'pc1_holy_water'));
    }

    public function test_saphiria_heals_30_percent_more_with_heal_water_in_battle_up_to_level_10(): void
    {
        foreach ([[1, 331], [5, 331], [6, 221], [10, 221], [11, 170], [21, 170], [22, 127]] as [$level, $expected]) {
            $state = $this->state('pc1', 'pc1_heal_water', $level);
            $state['units']['pc2']['hp'] = 100;

            $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc2');

            $heal = collect($result['events'])->firstWhere('type', 'heal');
            $this->assertSame($expected, $heal['value'], "Lv{$level} の癒しの水");
        }
    }

    public function test_other_saphiria_heals_and_the_full_heal_are_not_boosted_at_low_level(): void
    {
        // 神聖の水（2.5）・精霊の虹（味方全体 1.87）は、Lv5 でも元のまま
        $state = $this->state('pc1', 'pc1_holy_water', 5);
        $state['units']['pc2']['hp'] = 100;
        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_holy_water', 'pc2');
        $this->assertSame(250, collect($result['events'])->firstWhere('type', 'heal')['value']);

        $state = $this->state('pc1', 'pc1_spirit_rainbow', 5);
        $state['units']['pc2']['hp'] = 100;
        $state['units']['pc3']['hp'] = 100;
        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_rainbow');
        $this->assertSame(
            ['pc2' => 187, 'pc3' => 187],
            collect($result['events'])->where('type', 'heal')->pluck('value', 'target')->all(),
        );

        // 精霊の水は全回復のまま
        $state = $this->state('pc1', 'pc1_spirit_water', 5);
        $state['units']['pc2']['hp'] = 10;
        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_water', 'pc2');
        $this->assertSame(1000, $result['state']['units']['pc2']['hp']);
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
     * @return array<string, mixed>
     */
    private function state(string $actorId, string $spellId, int $level): array
    {
        $ally = static fn (string $id, int $spd) => [
            'id' => $id,
            'name' => $id,
            'sprite' => $id,
            'master_code' => $id,
            'side' => 'ally',
            'level' => $level,
            'hp' => 1000,
            'max_hp' => 1000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 10,
            'mag' => 100,
            'def' => 5,
            'spd' => $spd,
            'crit_rate' => 5,
            'evasion_rate' => 5,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
        $enemy = [
            'id' => 'enemy_1',
            'name' => 'enemy_1',
            'sprite' => 'bat',
            'master_code' => 'bat',
            'side' => 'enemy',
            'hp' => 500,
            'max_hp' => 500,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 20,
            'mag' => 10,
            'def' => 5,
            'spd' => 1,
            'crit_rate' => 1,
            'evasion_rate' => 1,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];

        $units = [
            'pc1' => $ally('pc1', 10),
            'pc2' => $ally('pc2', 9),
            'pc3' => $ally('pc3', 8),
            'pc4' => $ally('pc4', 7),
            'enemy_1' => $enemy,
        ];
        $units[$actorId]['spells'] = [$spellId];

        return [
            'status' => 'active',
            'current_actor' => $actorId,
            'awaiting_input' => true,
            'turn_order' => array_keys($units),
            'turn_index' => 0,
            'units' => $units,
            'log' => [],
        ];
    }
}
