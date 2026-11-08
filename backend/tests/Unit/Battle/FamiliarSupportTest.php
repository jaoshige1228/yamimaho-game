<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\FamiliarSupport;
use Tests\TestCase;

/**
 * 使い魔の支援: 全員の行動が一周するたびに抽選し、当たると支援を行う（config/familiar.php）。
 *
 * テストでは、最後に動くユニット（pc4）がこぶしを出す → 次は先頭の敵 → 「一周した」という形で支援を起こす。
 * 乱数の並びは familiarRoll に渡す: [確率, 行動の選択, 対象の選択, 顔の選択]。
 * 注入した値は範囲内に丸められるので、0 を渡せば「いちばん最初」を選ぶ。
 */
class FamiliarSupportTest extends TestCase
{
    // ---- 発動の抽選 ----

    public function test_nothing_happens_without_a_familiar_in_the_battle(): void
    {
        $state = $this->state(level: 0);
        unset($state['meta']);

        $result = $this->roundEnd($state, [1, 0, 0, 0]);

        $this->assertSame([], $this->familiarEvents($result['events']));
    }

    public function test_level_zero_never_supports(): void
    {
        $result = $this->roundEnd($this->state(level: 0), [1, 0, 0, 0]);

        $this->assertSame([], $this->familiarEvents($result['events']));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function rates(): array
    {
        return [
            'Lv1' => [1, 20],
            'Lv2' => [2, 20],
            'Lv3' => [3, 30],
            'Lv6' => [6, 30],
            'Lv7' => [7, 40],
            'Lv9' => [9, 40],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rates')]
    public function test_support_rate_depends_on_the_level(int $level, int $rate): void
    {
        $this->assertSame($rate, (new FamiliarSupport)->rateFor($level));

        // 抽選の出目が確率以下なら発動、超えたら発動しない
        $hit = $this->roundEnd($this->state(level: $level), [$rate, 0, 0, 0]);
        $this->assertNotSame([], $this->familiarEvents($hit['events']), "Lv{$level} 出目{$rate}");

        $miss = $this->roundEnd($this->state(level: $level), [$rate + 1, 0, 0, 0]);
        $this->assertSame([], $this->familiarEvents($miss['events']), "Lv{$level} 出目".($rate + 1));
    }

    public function test_no_lottery_in_the_middle_of_a_round(): void
    {
        // 先頭の味方が動いても、まだ一周していない
        $state = $this->state(level: 1, order: ['pc1', 'pc2', 'pc3', 'pc4', 'enemy_1']);
        $state['current_actor'] = 'pc1';

        $result = $this->engine([1, 0, 0, 0])->applyPlayerAction($state, 'pc1', 'punch', null, 'enemy_1');

        $this->assertSame([], $this->familiarEvents($result['events']));
        $this->assertSame('pc2', $result['state']['current_actor']);
    }

    public function test_round_end_is_found_even_when_the_last_unit_is_down(): void
    {
        // 順番 enemy_1 → pc1 → pc2 → pc3(戦闘不能)。pc2 のあとは pc3 を飛ばして enemy_1 に戻る = 一周
        $state = $this->state(level: 1, order: ['enemy_1', 'pc1', 'pc2', 'pc3', 'pc4']);
        $state['units']['pc3']['hp'] = 0;
        $state['units']['pc3']['alive'] = false;
        $state['units']['pc4']['hp'] = 0;
        $state['units']['pc4']['alive'] = false;
        $state['current_actor'] = 'pc2';

        $result = $this->engine([1, 0, 0, 0])->applyPlayerAction($state, 'pc2', 'punch', null, 'enemy_1');

        $this->assertNotSame([], $this->familiarEvents($result['events']));
    }

    // ---- 行動の選択 ----

    public function test_level_one_only_attacks_a_single_enemy(): void
    {
        $result = $this->roundEnd($this->state(level: 1), [1, 0, 0, 0]);

        $action = collect($result['events'])->firstWhere('type', 'familiar_action');
        $this->assertSame('敵に攻撃を繰り出した！', $action['text']);
        $this->assertSame(1, $this->countType($result['events'], 'damage', 'familiar'));
    }

    public function test_heal_is_not_an_option_when_nobody_is_wounded(): void
    {
        // Lv2 の選択肢は [敵単体, 味方単体回復]。誰も傷ついていなければ回復は選択肢にない（出目1でも敵単体）
        $result = $this->roundEnd($this->state(level: 2), [1, 1, 0, 0]);

        $this->assertSame('敵に攻撃を繰り出した！', collect($result['events'])->firstWhere('type', 'familiar_action')['text']);
        $this->assertSame(0, $this->countType($result['events'], 'heal', 'familiar'));
    }

    public function test_level_two_can_heal_a_wounded_ally_with_the_name_in_the_message(): void
    {
        $state = $this->state(level: 2);
        $state['units']['pc3']['hp'] = 10; // 傷ついているのはグレンだけ

        // 出目: 確率, 行動 = 2番目(回復), 対象 = 傷ついた味方の最初, 顔
        $result = $this->roundEnd($state, [1, 1, 0, 0]);

        $action = collect($result['events'])->firstWhere('type', 'familiar_action');
        $this->assertSame('火に応急手当した！', $action['text']);
        $heal = collect($result['events'])->firstWhere('type', 'heal');
        $this->assertSame(['familiar', 'pc3'], [$heal['actor'], $heal['target']]);
    }

    public function test_unlocked_actions_grow_with_the_level(): void
    {
        $names = fn (int $level): array => $this->optionTexts($level);

        $this->assertSame(['敵に攻撃を繰り出した！'], $names(1));
        $this->assertSame(['敵に攻撃を繰り出した！', '{target}に応急手当した！'], $names(2));
        $this->assertSame(['敵に攻撃を繰り出した！', '{target}に応急手当した！'], $names(3));
        $this->assertSame(['敵に攻撃を繰り出した！', '{target}に応急手当した！', '次々と敵全体に攻撃！'], $names(4));
        $this->assertSame([
            '敵に攻撃を繰り出した！', '{target}に応急手当した！', '次々と敵全体に攻撃！', '味方全員に回復魔法を唱えた！',
        ], $names(5));
        $this->assertSame([
            '敵に攻撃を繰り出した！', '{target}に応急手当した！', '次々と敵全体に攻撃！', '味方全員に回復魔法を唱えた！', '味方全員に強化魔法を唱えた！',
        ], $names(6));
        // 蘇生は選択肢には入らず、戦闘不能の味方がいるときだけ必ず行う
        $this->assertSame($names(6), $names(9));
    }

    // ---- 効果 ----

    public function test_single_damage_uses_the_average_mag_of_the_whole_party(): void
    {
        $state = $this->state(level: 1);
        // 戦闘不能の味方も平均に入る: (10 + 20 + 30 + 40) / 4 = 25
        $state['units']['pc3']['hp'] = 0;
        $state['units']['pc3']['alive'] = false;

        $result = $this->roundEnd($state, [1, 0, 0, 0]);

        $damage = collect($result['events'])->where('type', 'damage')->firstWhere('actor', 'familiar');
        // (25 - 防御10/2) × 係数1.4 = 28
        $this->assertSame(['familiar', 'enemy_1', 28], [$damage['actor'], $damage['target'], $damage['value']]);
        $this->assertSame(5000 - 28 - $this->punchDamage(), $result['state']['units']['enemy_1']['hp']);
    }

    public function test_level_eight_strengthens_every_support(): void
    {
        $support = new FamiliarSupport;

        foreach (['enemy_single' => [1.4, 2.0], 'ally_single_heal' => [1.6, 2.2], 'enemy_all' => [1.0, 1.6], 'ally_all_heal' => [1.2, 1.6]] as $action => [$base, $upgraded]) {
            $this->assertSame($base, $support->coefficientFor($action, 7), "{$action} Lv7");
            $this->assertSame($upgraded, $support->coefficientFor($action, 8), "{$action} Lv8");
            $this->assertSame($upgraded, $support->coefficientFor($action, 9), "{$action} Lv9");
        }
        $this->assertSame('atk_up_familiar', $support->attackBuffKey(7));
        $this->assertSame('atk_up_familiar_large', $support->attackBuffKey(8));

        // 実際のダメージ: (25 - 5) × 2.0 = 40。傷ついた味方がいれば「攻撃バフが必ず発動」にならず、最初の選択肢（敵単体）を選べる
        $state = $this->state(level: 8);
        $state['units']['pc1']['hp'] = 90;
        $result = $this->roundEnd($state, [1, 0, 0, 0]);
        $this->assertSame(40, collect($result['events'])->where('type', 'damage')->firstWhere('actor', 'familiar')['value']);
    }

    public function test_all_enemy_damage_hits_every_living_enemy(): void
    {
        $state = $this->state(level: 4, enemies: 2);
        $state['units']['enemy_2']['hp'] = 0;
        $state['units']['enemy_2']['alive'] = false;
        $state['units']['enemy_3'] = $this->enemy('enemy_3');

        // Lv4 の選択肢は [敵単体, 回復(なし), 敵全体] → 傷ついた味方がいないので [敵単体, 敵全体]。2番目 = 敵全体
        $result = $this->roundEnd($state, [1, 1, 0, 0]);

        $this->assertSame('次々と敵全体に攻撃！', collect($result['events'])->firstWhere('type', 'familiar_action')['text']);
        $targets = collect($result['events'])->where('type', 'damage')->where('actor', 'familiar')->pluck('target')->all();
        $this->assertEqualsCanonicalizing(['enemy_1', 'enemy_3'], $targets, '倒れた敵は対象外');
        // (25 - 5) × 1.0 = 20
        $this->assertSame(20, collect($result['events'])->where('actor', 'familiar')->first()['value']);
    }

    public function test_all_ally_heal_restores_only_wounded_living_allies(): void
    {
        $state = $this->state(level: 5);
        $state['units']['pc1']['hp'] = 90;  // 不足10
        $state['units']['pc2']['hp'] = 10;  // 不足90
        $state['units']['pc3']['hp'] = 0;   // 戦闘不能
        $state['units']['pc3']['alive'] = false;

        // Lv5 の選択肢は [敵単体, 味方単体回復, 敵全体, 味方全体回復] → 4番目
        $result = $this->roundEnd($state, [1, 3, 0, 0]);

        $this->assertSame('味方全員に回復魔法を唱えた！', collect($result['events'])->firstWhere('type', 'familiar_action')['text']);
        $heals = collect($result['events'])->where('type', 'heal')->pluck('value', 'target')->all();
        // 平均魔力25 × 1.2 = 30。不足ぶんまで
        $this->assertSame(['pc1' => 10, 'pc2' => 30], $heals);
        $this->assertSame(0, $result['state']['units']['pc3']['hp']);
        $this->assertFalse($result['state']['units']['pc3']['alive'], '戦闘不能の味方は戻らない');
    }

    public function test_level_nine_always_revives_a_fallen_ally_first(): void
    {
        $state = $this->state(level: 9);
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;
        $state['units']['pc1']['hp'] = 10; // 傷ついた味方がいても、蘇生が最優先

        $result = $this->roundEnd($state, [1, 0, 0, 0]);

        $this->assertSame('木を蘇生した！', collect($result['events'])->firstWhere('type', 'familiar_action')['text']);
        $revive = collect($result['events'])->firstWhere('type', 'revive');
        $this->assertSame(['familiar', 'pc2', 30], [$revive['actor'], $revive['target'], $revive['hp']], '最大HP100の30%');
        $this->assertTrue($result['state']['units']['pc2']['alive']);
        $this->assertSame(30, $result['state']['units']['pc2']['hp']);
    }

    public function test_revive_is_unavailable_below_level_nine(): void
    {
        $state = $this->state(level: 8);
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;

        $result = $this->roundEnd($state, [1, 0, 0, 0]);

        $this->assertSame(0, $this->countType($result['events'], 'revive', 'familiar'));
        $this->assertFalse($result['state']['units']['pc2']['alive']);
    }

    public function test_attack_buff_is_forced_when_nobody_is_wounded_and_nobody_has_it(): void
    {
        // 出目で他の行動（敵単体）を選ばせようとしても、必ず攻撃バフ
        $result = $this->roundEnd($this->state(level: 6), [1, 0, 0, 0]);

        $this->assertSame('味方全員に強化魔法を唱えた！', collect($result['events'])->firstWhere('type', 'familiar_action')['text']);
        $buffed = collect($result['events'])->where('type', 'buff_applied')->pluck('buff', 'target')->all();
        $this->assertSame(['pc1' => 'atk_up_familiar', 'pc2' => 'atk_up_familiar', 'pc3' => 'atk_up_familiar', 'pc4' => 'atk_up_familiar'], $buffed);
        $this->assertSame(3, $result['state']['units']['pc1']['buffs']['atk_up_familiar']);
    }

    public function test_attack_buff_is_not_forced_when_someone_is_wounded(): void
    {
        $state = $this->state(level: 6);
        $state['units']['pc1']['hp'] = 50;

        $result = $this->roundEnd($state, [1, 0, 0, 0]); // 行動 = 最初(敵単体)

        $this->assertSame(0, $this->countType($result['events'], 'buff_applied', 'familiar'));
    }

    public function test_attack_buff_is_not_forced_while_an_ally_already_has_it(): void
    {
        $state = $this->state(level: 6);
        $state['units']['pc3']['buffs']['atk_up_familiar'] = 2;

        $result = $this->roundEnd($state, [1, 0, 0, 0]);

        $this->assertSame(0, $this->countType($result['events'], 'buff_applied', 'familiar'));
    }

    public function test_attack_buff_raises_both_strength_and_mag(): void
    {
        $method = new \ReflectionMethod(BattleEngine::class, 'effectiveStat');
        $method->setAccessible(true);
        $engine = $this->engine([]);

        $unit = fn (string $buff) => ['str' => 10, 'mag' => 20, 'def' => 5, 'buffs' => [$buff => 3]];

        $this->assertEqualsWithDelta(12.0, $method->invoke($engine, $unit('atk_up_familiar'), 'str'), 0.0001);
        $this->assertEqualsWithDelta(24.0, $method->invoke($engine, $unit('atk_up_familiar'), 'mag'), 0.0001);
        $this->assertEqualsWithDelta(5.0, $method->invoke($engine, $unit('atk_up_familiar'), 'def'), 0.0001, '防御には乗らない');
        $this->assertEqualsWithDelta(13.0, $method->invoke($engine, $unit('atk_up_familiar_large'), 'str'), 0.0001);
        $this->assertEqualsWithDelta(26.0, $method->invoke($engine, $unit('atk_up_familiar_large'), 'mag'), 0.0001);
    }

    public function test_the_familiar_can_finish_off_the_last_enemy(): void
    {
        $state = $this->state(level: 1);
        $state['units']['enemy_1']['hp'] = 30; // こぶし(5)で 25 に削れて、使い魔の一撃(28)で倒れる
        $state['units']['enemy_1']['max_hp'] = 5000;

        $result = $this->engine([1, 0, 0, 0])->applyPlayerAction($state, 'pc4', 'punch', null, 'enemy_1');

        $this->assertSame('victory', $result['state']['status']);
        $this->assertNull($result['state']['current_actor']);
        $types = array_column($result['events'], 'type');
        $this->assertContains('familiar_support', $types);
        $this->assertContains('gold_gained', $types, '使い魔が倒した敵のゴールドも入る');
        $this->assertSame('battle_end', end($types));
        $this->assertSame(5, $result['state']['meta']['exp_earned']);
    }

    public function test_no_support_after_the_battle_has_ended(): void
    {
        // こぶしで最後の敵を倒した → 勝利。使い魔の抽選はしない
        $state = $this->state(level: 1);
        $state['units']['enemy_1']['hp'] = 1;

        $result = $this->engine([1, 0, 0, 0])->applyPlayerAction($state, 'pc4', 'punch', null, 'enemy_1');

        $this->assertSame('victory', $result['state']['status']);
        $this->assertSame([], $this->familiarEvents($result['events']));
    }

    public function test_event_order_and_the_portrait_comes_from_the_four_familiars(): void
    {
        // 顔の選択の出目 2 → 3番目 = tukaima_pc3
        $result = $this->roundEnd($this->state(level: 1), [1, 0, 0, 2]);

        $types = array_column($result['events'], 'type');
        $support = array_search('familiar_support', $types, true);
        $this->assertSame(['familiar_support', 'familiar_action', 'damage'], array_slice($types, $support, 3));

        $events = array_values($result['events']);
        $this->assertSame(
            ['type' => 'familiar_support', 'portrait' => 'tukaima_pc3', 'text' => '使い魔たちの支援行動！'],
            $events[$support],
        );
        $this->assertSame('tukaima_pc3', $events[$support + 1]['portrait']);
        $this->assertContains('使い魔たちの支援行動！', $result['state']['log']);
        $this->assertContains('敵に攻撃を繰り出した！', $result['state']['log']);
    }

    public function test_the_next_actor_is_still_the_first_unit_of_the_next_round(): void
    {
        $result = $this->roundEnd($this->state(level: 1), [1, 0, 0, 0]);

        $this->assertSame('enemy_1', $result['state']['current_actor']);
        $this->assertSame(0, $result['state']['turn_index']);
    }

    // ---- 補助 ----

    /**
     * 最後の味方（pc4）がこぶしを出して一周する。
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $familiarRolls
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function roundEnd(array $state, array $familiarRolls): array
    {
        return $this->engine($familiarRolls)->applyPlayerAction($state, 'pc4', 'punch', null, 'enemy_1');
    }

    /**
     * 発動前の選択肢（メッセージ）を、状況を「全員満タン・バフなし」にしたうえでレベルごとに並べる。
     * 回復を選択肢に含めるため、1人だけ傷ついた状態にする。蘇生は戦闘不能がいなければ選択肢に入らない。
     *
     * @return list<string>
     */
    private function optionTexts(int $level): array
    {
        $units = $this->state(level: $level)['units'];
        $units['pc1']['hp'] = 10; // 傷ついた味方がいれば回復が選択肢に入る（バフの必ず発動も起きない）

        $texts = [];
        for ($i = 0; $i < 12; $i++) {
            // 最初の乱数（行動の選択）の出目だけを順に変えて、出てくる行動を集める。ほかの乱数（対象・顔）は最初を選ぶ
            $calls = 0;
            $roll = static function (int $min, int $max) use ($i, &$calls): int {
                return $calls++ === 0 ? min($i, $max) : $min;
            };
            $decision = (new FamiliarSupport($roll))->decide($units, $level);
            $texts[] = (string) config("familiar.actions.{$decision['action']}.message");
        }

        return array_values(array_unique($texts));
    }

    /**
     * @param  list<int>  $familiarRolls
     */
    private function engine(array $familiarRolls): BattleEngine
    {
        $rolls = $familiarRolls;

        return new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
            familiarRoll: static function (int $min, int $max) use (&$rolls): int {
                return array_shift($rolls) ?? $min;
            },
        );
    }

    /**
     * pc4 のこぶしのダメージ（筋力 10、敵の防御 10、係数 1.0、乱数 1.0 → 10 - 5 = 5）。
     */
    private function punchDamage(): int
    {
        return 5;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function familiarEvents(array $events): array
    {
        return array_values(array_filter(
            $events,
            fn (array $e) => in_array($e['type'], ['familiar_support', 'familiar_action'], true) || ($e['actor'] ?? null) === 'familiar',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function countType(array $events, string $type, string $actor): int
    {
        return count(array_filter($events, fn (array $e) => $e['type'] === $type && ($e['actor'] ?? null) === $actor));
    }

    /**
     * 敵が先頭、pc4 が最後。pc4 が動くと次は先頭の敵に戻る（= 一周）。
     *
     * @param  list<string>|null  $order
     * @return array<string, mixed>
     */
    private function state(int $level, ?array $order = null, int $enemies = 1): array
    {
        $mags = ['pc1' => 10, 'pc2' => 20, 'pc3' => 30, 'pc4' => 40];
        $names = ['pc1' => '水', 'pc2' => '木', 'pc3' => '火', 'pc4' => '土'];
        $units = [];
        foreach ($mags as $id => $mag) {
            $units[$id] = [
                'id' => $id,
                'name' => $names[$id],
                'sprite' => $id,
                'side' => 'ally',
                'hp' => 100,
                'max_hp' => 100,
                'mp' => 50,
                'max_mp' => 50,
                'str' => 10,
                'mag' => $mag,
                'def' => 5,
                'spd' => 10,
                'crit_rate' => 0,
                'evasion_rate' => 5,
                'damage_shield' => false,
                'alive' => true,
                'buffs' => [],
                'statuses' => [],
                'spells' => [],
            ];
        }
        for ($i = 1; $i <= $enemies; $i++) {
            $units["enemy_{$i}"] = $this->enemy("enemy_{$i}");
        }

        return [
            'status' => 'active',
            'current_actor' => 'pc4',
            'awaiting_input' => true,
            'turn_order' => $order ?? [...array_filter(array_keys($units), fn ($id) => str_starts_with($id, 'enemy')), 'pc1', 'pc2', 'pc3', 'pc4'],
            'turn_index' => 4,
            'units' => $units,
            'meta' => ['familiar' => ['level' => $level]],
            'log' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enemy(string $id): array
    {
        return [
            'id' => $id,
            'name' => '敵',
            'sprite' => 'bat',
            'side' => 'enemy',
            'master_code' => 'bat',
            'hp' => 5000,
            'max_hp' => 5000,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 1,
            'mag' => 1,
            'def' => 10,
            'spd' => 1,
            'crit_rate' => 0,
            'evasion_rate' => 0,
            'gold_reward' => 10,
            'exp_reward' => 5,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
    }
}
