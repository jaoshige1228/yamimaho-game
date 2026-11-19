<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleEngineKickTest extends TestCase
{
    public function test_kick_misses_when_hit_roll_fails(): void
    {
        $rolls = [100];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
        );

        $state = $this->battleState();

        $result = $engine->applyPlayerAction($state, 'pc1', 'kick', null, 'enemy_1');
        $types = array_column($result['events'], 'type');

        $this->assertContains('miss', $types);
        $this->assertNotContains('damage', $types);
    }

    public function test_kick_hits_with_double_str_damage(): void
    {
        $rolls = [1, 100];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
            critRoll: static fn (): int => 100,
        );

        $state = $this->battleState();
        $state['units']['pc1']['str'] = 14;
        $state['units']['enemy_1']['def'] = 6;

        $result = $engine->applyPlayerAction($state, 'pc1', 'kick', null, 'enemy_1');
        $damage = collect($result['events'])->firstWhere('type', 'damage');

        $this->assertNotNull($damage);
        // (14 - 3) * 2.0 = 22
        $this->assertSame(22, $damage['value']);
    }

    public function test_level_10_kick_uses_triple_coefficient(): void
    {
        $rolls = [1, 100];
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
            critRoll: static fn (): int => 100,
        );

        $state = $this->battleState();
        $state['units']['pc1']['level'] = 10;
        $state['units']['pc1']['str'] = 14;
        $state['units']['enemy_1']['def'] = 6;

        $result = $engine->applyPlayerAction($state, 'pc1', 'kick', null, 'enemy_1');
        $damage = collect($result['events'])->firstWhere('type', 'damage');

        $this->assertNotNull($damage);
        // (14 - 3) * 3.0 = 33
        $this->assertSame(33, $damage['value']);
    }

    public function test_from_level_51_punch_and_kick_coefficients_are_multiplied_by_1_1(): void
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 1,
            critRoll: static fn (): int => 100,
        );

        // コマンドに出る倍率: Lv10〜50 はこぶし 1.5・キック 3.0。Lv51 以降は、さらに ×1.1（1.65・3.3）
        foreach ([[10, 1.5, 3.0], [50, 1.5, 3.0], [51, 1.65, 3.3], [75, 1.65, 3.3], [100, 1.65, 3.3]] as [$level, $punch, $kick]) {
            $state = $this->battleState();
            $state['units']['pc1']['level'] = $level;
            foreach ($state['units'] as $id => $unit) {
                $state['units'][$id] += ['sprite' => 'test']; // publicState が画面用に読む項目（この手組みの状態には無い）
            }
            $commands = $engine->publicState($state)['commands'];
            $this->assertEqualsWithDelta($punch, $commands['punch']['coefficient'], 0.0000001, "Lv{$level} のこぶし");
            $this->assertEqualsWithDelta($kick, $commands['kick']['coefficient'], 0.0000001, "Lv{$level} のキック");
        }

        // 実際のダメージ（筋力 14・敵の防御 6 → 14 - 3 = 11 に倍率）: Lv50 は こぶし 16 / キック 33、Lv51 は こぶし 18 / キック 36
        $damageOf = function (int $level, string $action) use ($engine): int {
            $state = $this->battleState();
            $state['units']['pc1']['level'] = $level;
            $state['units']['pc1']['str'] = 14;
            $state['units']['enemy_1']['def'] = 6;
            $result = $engine->applyPlayerAction($state, 'pc1', $action, null, 'enemy_1');

            return (int) collect($result['events'])->firstWhere('type', 'damage')['value'];
        };
        $this->assertSame(16, $damageOf(50, 'punch'));
        $this->assertSame(33, $damageOf(50, 'kick'));
        $this->assertSame(18, $damageOf(51, 'punch'));
        $this->assertSame(36, $damageOf(51, 'kick'));
    }

    /**
     * @return array<string, mixed>
     */
    private function battleState(): array
    {
        return [
            'status' => 'active',
            'current_actor' => 'pc1',
            'awaiting_input' => true,
            'turn_order' => ['pc1', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'name' => 'PC1',
                    'side' => 'ally',
                    'level' => 1,
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 20,
                    'max_mp' => 20,
                    'str' => 14,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 10,
                    'crit_rate' => 0,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => '敵',
                    'side' => 'enemy',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 0,
                    'max_mp' => 0,
                    'str' => 8,
                    'mag' => 8,
                    'def' => 6,
                    'spd' => 5,
                    'crit_rate' => 0,
                    'evasion_rate' => 0,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                    'master_code' => 'bat',
                ],
            ],
            'log' => [],
        ];
    }
}
