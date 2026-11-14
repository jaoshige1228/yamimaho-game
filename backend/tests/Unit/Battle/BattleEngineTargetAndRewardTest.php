<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * 公開前の点検（2026-11-15）で見つかった 2 つの不具合の回帰テスト。
 *
 * 1. 倒した敵の経験値・ゴールドは、1 体につき 1 回だけ数える（蘇生した敵を倒し直しても、倒れた敵に当て直しても増えない）。
 * 2. 単体を狙う魔法の相手は、サーバーで確かめる（存在しない相手は 500 ではなく 422 になる。
 *    攻撃魔法を味方へ、回復や強化を敵へ、倒れた相手への攻撃や強化は受け付けない）。
 */
class BattleEngineTargetAndRewardTest extends TestCase
{
    public function test_the_reward_of_an_enemy_is_counted_once_even_if_it_is_revived_and_defeated_again(): void
    {
        $state = $this->state('pc1', []);
        $state['meta'] = [];
        $state['units']['enemy_1']['hp'] = 10;
        $state['units']['enemy_1']['gold_reward'] = 900;
        $state['units']['enemy_1']['exp_reward'] = 135;

        $this->hit($state, 'enemy_1', 10);
        $this->assertFalse($state['units']['enemy_1']['alive']);
        $this->assertSame(900, $state['meta']['gold_earned']);
        $this->assertSame(135, $state['meta']['exp_earned']);

        // 敵の蘇生魔法（宇宙樹の雫など）で生き返り、もう一度倒される
        $state['units']['enemy_1']['alive'] = true;
        $state['units']['enemy_1']['hp'] = 5;
        $events = $this->hit($state, 'enemy_1', 5);

        $this->assertFalse($state['units']['enemy_1']['alive']);
        $this->assertSame(900, $state['meta']['gold_earned'], '倒し直しても、ゴールドは増えない');
        $this->assertSame(135, $state['meta']['exp_earned'], '倒し直しても、経験値は増えない');
        $this->assertNotContains('gold_gained', array_column($events, 'type'), '2 回目は「ゴールドを得た」演出も出さない');
    }

    public function test_hitting_an_already_fallen_enemy_again_does_not_count_its_reward_again(): void
    {
        $state = $this->state('pc1', []);
        $state['meta'] = [];
        $state['units']['enemy_1']['hp'] = 10;
        $state['units']['enemy_1']['gold_reward'] = 20;
        $state['units']['enemy_1']['exp_reward'] = 20;

        $this->hit($state, 'enemy_1', 10);
        $this->hit($state, 'enemy_1', 10);

        $this->assertSame(20, $state['meta']['gold_earned']);
        $this->assertSame(20, $state['meta']['exp_earned']);
    }

    public function test_each_enemy_still_pays_its_own_reward(): void
    {
        $state = $this->state('pc1', []);
        $state['meta'] = [];
        foreach (['enemy_1', 'enemy_2'] as $id) {
            $state['units'][$id]['hp'] = 10;
            $state['units'][$id]['gold_reward'] = 20;
            $state['units'][$id]['exp_reward'] = 30;
        }

        $this->hit($state, 'enemy_1', 10);
        $this->hit($state, 'enemy_2', 10);

        $this->assertSame(40, $state['meta']['gold_earned']);
        $this->assertSame(60, $state['meta']['exp_earned']);
    }

    public function test_a_spell_aimed_at_a_unit_that_does_not_exist_is_rejected_not_a_server_error(): void
    {
        $state = $this->state('pc1', ['pc1_water_blade', 'pc1_heal_water']);

        foreach (['pc1_water_blade' => 'enemy_99', 'pc1_heal_water' => 'pc9'] as $spell => $target) {
            try {
                $this->engine()->applyPlayerAction($state, 'pc1', 'spell', $spell, $target);
                $this->fail("{$spell} → {$target} は受け付けない");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('有効なターゲットを指定してください。', $e->getMessage());
            }
        }
    }

    public function test_an_attack_spell_cannot_be_aimed_at_an_ally(): void
    {
        $state = $this->state('pc1', ['pc1_water_blade']);

        $this->expectException(InvalidArgumentException::class);
        $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_blade', 'pc2');
    }

    public function test_a_support_spell_cannot_be_aimed_at_an_enemy(): void
    {
        $state = $this->state('pc1', ['pc1_water_veil', 'pc1_heal_water']);
        $state['units']['enemy_1']['hp'] = 100;

        foreach (['pc1_water_veil', 'pc1_heal_water'] as $spell) {
            try {
                $this->engine()->applyPlayerAction($state, 'pc1', 'spell', $spell, 'enemy_1');
                $this->fail("{$spell} を敵に使えてはいけない");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('有効なターゲットを指定してください。', $e->getMessage());
            }
        }
    }

    public function test_an_attack_spell_cannot_be_aimed_at_an_enemy_that_has_already_fallen(): void
    {
        $state = $this->state('pc1', ['pc1_water_blade']);
        $state['meta'] = [];
        $state['units']['enemy_2']['hp'] = 0;
        $state['units']['enemy_2']['alive'] = false;
        $state['units']['enemy_2']['gold_reward'] = 20;
        $state['units']['enemy_2']['exp_reward'] = 20;

        try {
            $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_blade', 'enemy_2');
            $this->fail('倒れた敵に攻撃魔法は撃てない');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('有効なターゲットを指定してください。', $e->getMessage());
        }
        $this->assertArrayNotHasKey('gold_earned', $state['meta'], '失敗した行動で、報酬が数えられることもない');
    }

    public function test_a_buff_cannot_be_aimed_at_an_ally_that_has_fallen(): void
    {
        $state = $this->state('pc1', ['pc1_water_veil']);
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;

        $this->expectException(InvalidArgumentException::class);
        $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_veil', 'pc2');
    }

    public function test_healing_and_reviving_keep_their_own_messages_for_fallen_or_healthy_allies(): void
    {
        $state = $this->state('pc1', ['pc1_heal_water', 'pc1_miracle_drop']);
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;

        // 倒れた味方は回復できない（専用の理由）
        try {
            $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc2');
            $this->fail('倒れた味方は回復できない');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('回復できない対象です。', $e->getMessage());
        }

        // 生きている味方は蘇生できない（専用の理由）
        try {
            $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_miracle_drop', 'pc3');
            $this->fail('生きている味方は蘇生できない');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('蘇生できない対象です。', $e->getMessage());
        }
    }

    public function test_valid_targets_are_still_accepted(): void
    {
        $state = $this->state('pc1', ['pc1_water_blade', 'pc1_heal_water', 'pc1_water_veil', 'pc1_miracle_drop']);
        $state['units']['pc3']['hp'] = 100; // 傷ついた味方

        $attack = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_blade', 'enemy_1');
        $this->assertContains('damage', array_column($attack['events'], 'type'));

        $heal = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_heal_water', 'pc3');
        $this->assertGreaterThan(100, $heal['state']['units']['pc3']['hp']);

        $buff = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_water_veil', 'pc2');
        $this->assertNotEmpty($buff['events']);

        // 倒れた味方を蘇生する
        $state['units']['pc4']['hp'] = 0;
        $state['units']['pc4']['alive'] = false;
        $revive = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_miracle_drop', 'pc4');
        $this->assertTrue($revive['state']['units']['pc4']['alive']);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function hit(array &$state, string $targetId, int $damage): array
    {
        $method = (new ReflectionClass($this->engine()))->getMethod('applyDamage');
        $method->setAccessible(true);

        return $method->invokeArgs($this->engine(), [&$state, 'pc1', $targetId, $damage, null, false]);
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
        $enemy = static fn (string $id) => [
            'id' => $id, 'name' => '敵', 'sprite' => 'test', 'side' => 'enemy', 'master_code' => 'bat',
            'hp' => 9000, 'max_hp' => 9000, 'mp' => 0, 'max_mp' => 0,
            'str' => 100, 'mag' => 100, 'def' => 100, 'pdef' => 100, 'mdef' => 100, 'spd' => 1,
            'crit_rate' => 0, 'evasion_rate' => 0, 'damage_shield' => false, 'alive' => true,
            'buffs' => [], 'stages' => [], 'statuses' => [], 'spells' => [],
        ];
        $units = [
            'pc1' => $ally('pc1', 'サフィリア', 40, $actor === 'pc1' ? $spells : []),
            'pc2' => $ally('pc2', 'ガルデ', 30, []),
            'pc3' => $ally('pc3', 'グレン', 20, []),
            'pc4' => $ally('pc4', 'シエナ', 10, []),
            'enemy_1' => $enemy('enemy_1'),
            'enemy_2' => $enemy('enemy_2'),
        ];

        return [
            'status' => 'active', 'current_actor' => $actor, 'awaiting_input' => true,
            'turn_order' => array_keys($units), 'turn_index' => 0, 'units' => $units, 'log' => [],
        ];
    }
}
