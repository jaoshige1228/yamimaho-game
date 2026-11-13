<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\SpellHealing;
use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

/**
 * サフィリア（pc1）は Lv22 以上のとき、回復魔法（heal_mag）の回復量が 0.75 倍。
 * 全回復（精霊の水）・ほかのキャラの回復魔法には関係ない。
 */
class BattleEngineHealLevelPenaltyTest extends TestCase
{
    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    public function test_the_penalty_is_configured_for_saphiria_from_level_22(): void
    {
        $this->assertSame(['min_level' => 22, 'multiplier' => 0.75], config('battle.heal_mag_level_penalty.pc1'));
        $this->assertNull(config('battle.heal_mag_level_penalty.pc2'));
    }

    public function test_heal_amount_formula_applies_the_penalty_from_level_22_only_to_saphiria(): void
    {
        $holy = (new MasterDataProvider)->findSpell('pc1_holy_water'); // 倍率 2.5

        $this->assertSame(250, SpellHealing::healMagAmount(100.0, $holy));
        $this->assertSame(250, SpellHealing::healMagAmount(100.0, $holy, 'pc1', 21));
        $this->assertSame(187, SpellHealing::healMagAmount(100.0, $holy, 'pc1', 22), '250 × 0.75 = 187.5 の切り捨て');
        $this->assertSame(187, SpellHealing::healMagAmount(100.0, $holy, 'pc1', 50));
        $this->assertSame(250, SpellHealing::healMagAmount(100.0, $holy, 'pc2', 30), 'サフィリア以外は変わらない');
        $this->assertSame(250, SpellHealing::healMagAmount(100.0, $holy, null, 30));
    }

    public function test_saphiria_heals_less_in_battle_from_level_22(): void
    {
        foreach ([[20, 250], [21, 250], [22, 187], [30, 187]] as [$level, $expected]) {
            $state = $this->state('pc1', 'pc1_holy_water', $level);
            $state['units']['pc2']['hp'] = 100;

            $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_holy_water', 'pc2');

            $heal = collect($result['events'])->firstWhere('type', 'heal');
            $this->assertSame($expected, $heal['value'], "Lv{$level} の神聖の水");
        }
    }

    public function test_every_saphiria_heal_mag_spell_is_reduced_including_the_all_ally_one(): void
    {
        // 癒しの水 1.7 → 127.5 切り捨て 127 / 精霊の虹 2.2（味方全体）→ 165
        $state = $this->state('pc1', 'pc1_heal_water', 22);
        $state['units']['pc2']['hp'] = 100;
        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc2');
        $this->assertSame(127, collect($result['events'])->firstWhere('type', 'heal')['value']);

        $state = $this->state('pc1', 'pc1_spirit_rainbow', 22);
        $state['units']['pc2']['hp'] = 100;
        $state['units']['pc3']['hp'] = 100;
        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_rainbow');
        $this->assertSame(
            ['pc2' => 165, 'pc3' => 165],
            collect($result['events'])->where('type', 'heal')->pluck('value', 'target')->all(),
        );
    }

    public function test_full_heal_is_not_reduced(): void
    {
        $state = $this->state('pc1', 'pc1_spirit_water', 30);
        $state['units']['pc2']['hp'] = 10;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_spirit_water', 'pc2');

        $this->assertSame(1000, $result['state']['units']['pc2']['hp'], '全回復のまま');
    }

    public function test_other_characters_heal_spells_are_not_reduced(): void
    {
        // ガルデ（pc2）の迅速調合 2.5。高レベルでも変わらない
        $state = $this->state('pc2', 'pc2_quick_brew', 30);
        $state['units']['pc1']['hp'] = 100;

        $result = $this->engine()->applyPlayerAction($state, 'pc2', 'spell', 'pc2_quick_brew', 'pc1');

        $this->assertSame(250, collect($result['events'])->firstWhere('type', 'heal')['value']);
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
        $enemy = static fn (string $id) => [
            'id' => $id,
            'name' => $id,
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
            'enemy_1' => $enemy('enemy_1'),
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
