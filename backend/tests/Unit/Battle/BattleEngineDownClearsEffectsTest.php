<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use Tests\TestCase;

/**
 * 戦闘不能になったキャラ（敵味方どちらも）からは、すべてのバフ・デバフ（防御・攻撃の段階、回避、加護、障壁）が消える。
 * 蘇生しても戻らない。
 */
class BattleEngineDownClearsEffectsTest extends TestCase
{
    use StageHelpers;

    public function test_an_ally_that_goes_down_loses_every_buff_and_debuff(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['pc1']['stages'] = [
            $this->stageEntry('def', 2),
            $this->stageEntry('def', -1),
            $this->stageEntry('atk', 2, 3, 'familiar'),
            $this->stageEntry('atk', -2),
        ];
        $state['units']['pc1']['buffs'] = ['evasion_set' => 3];

        $this->lethalDamage($engine, $state, 'pc1');

        $unit = $state['units']['pc1'];
        $this->assertFalse($unit['alive']);
        $this->assertSame(0, $unit['hp']);
        $this->assertSame([], $unit['stages'], '防御・攻撃の段階（バフもデバフも）が消える');
        $this->assertSame([], $unit['buffs'], '回避の固定も消える');
        $this->assertFalse($unit['life_ward']);
        $this->assertFalse($unit['damage_shield']);

        // 画面に出る状態（バッジ）も、すべてなし
        $public = $engine->publicState($state)['units'];
        $pc1 = collect($public)->firstWhere('id', 'pc1');
        $this->assertSame(['def' => 0, 'atk' => 0], $pc1['stages']);
        $this->assertSame([], $pc1['buffs']);
        $this->assertFalse($pc1['life_ward']);
        $this->assertFalse($pc1['damage_shield']);
    }

    public function test_an_enemy_that_goes_down_loses_every_buff_and_debuff(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['enemy_1']['stages'] = [$this->stageEntry('def', 3), $this->stageEntry('atk', -3)];
        $state['units']['enemy_1']['buffs'] = ['evasion_set' => 2];
        $state['units']['enemy_1']['life_ward'] = true;

        // 命の加護があると 1 回は踏みとどまる。そのときはまだ倒れていないので、段階などは残る
        $this->lethalDamage($engine, $state, 'enemy_1');
        $this->assertTrue($state['units']['enemy_1']['alive']);
        $this->assertSame(1, $state['units']['enemy_1']['hp']);
        $this->assertFalse($state['units']['enemy_1']['life_ward']);
        $this->assertSame(3, $this->stageSum($state['units']['enemy_1'], 'def'));
        $this->assertSame(['evasion_set' => 2], $state['units']['enemy_1']['buffs']);

        // 加護が消えたあとの 2 回目で倒れる。ここですべて消える
        $this->lethalDamage($engine, $state, 'enemy_1');
        $unit = $state['units']['enemy_1'];
        $this->assertFalse($unit['alive']);
        $this->assertSame([], $unit['stages']);
        $this->assertSame([], $unit['buffs']);
        $this->assertFalse($unit['life_ward']);
    }

    public function test_an_enemy_killed_by_a_real_player_attack_loses_its_buffs_too(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['enemy_1']['hp'] = 1;
        $state['units']['enemy_1']['stages'] = [$this->stageEntry('def', -3), $this->stageEntry('atk', 2)];
        $state['units']['enemy_1']['buffs'] = ['evasion_set' => 3];
        $state['status'] = 'active';
        $state['current_actor'] = 'pc1';
        $state['awaiting_input'] = true;

        $result = $engine->applyPlayerAction($state, 'pc1', 'punch', null, 'enemy_1');

        $enemy = $result['state']['units']['enemy_1'];
        $this->assertFalse($enemy['alive']);
        $this->assertSame([], $enemy['stages']);
        $this->assertSame([], $enemy['buffs']);
        // 倒した敵の経験値・ゴールドは今までどおり入る
        $this->assertSame(20, (int) ($result['state']['meta']['exp_earned'] ?? 0));
    }

    public function test_a_unit_that_survives_a_hit_keeps_its_buffs(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['pc1']['stages'] = [$this->stageEntry('def', 2)];
        $state['units']['pc1']['buffs'] = ['evasion_set' => 3];

        $this->damage($engine, $state, 'pc1', 10);

        $this->assertTrue($state['units']['pc1']['alive']);
        $this->assertSame(2, $this->stageSum($state['units']['pc1'], 'def'));
        $this->assertSame(['evasion_set' => 3], $state['units']['pc1']['buffs']);
    }

    public function test_a_revived_unit_starts_without_the_old_effects_and_can_be_buffed_again(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        $state['units']['pc2']['stages'] = [$this->stageEntry('def', 3), $this->stageEntry('atk', -3)];
        $state['units']['pc2']['buffs'] = ['evasion_set' => 3];

        $this->lethalDamage($engine, $state, 'pc2');

        $revive = ['effect' => 'revive_full', 'label' => 'テスト蘇生', 'target_type' => 'ally_single'];
        $method = new \ReflectionMethod(BattleEngine::class, 'applySpellEffect');
        $method->setAccessible(true);
        $args = [&$state, 'pc1', 'pc2', $revive, 'test_revive'];
        $method->invokeArgs($engine, $args);

        $unit = $state['units']['pc2'];
        $this->assertTrue($unit['alive'], '蘇生した');
        $this->assertSame(0, $this->stageSum($unit, 'def'), '蘇生しても、倒れる前の防御は戻らない');
        $this->assertSame(0, $this->stageSum($unit, 'atk'), '蘇生しても、倒れる前の攻撃は戻らない');
        $this->assertSame([], $unit['buffs']);

        // 蘇生後は、普通にバフを受けられる
        $apply = new \ReflectionMethod(BattleEngine::class, 'applyBuffToUnit');
        $apply->setAccessible(true);
        $args = [&$state['units']['pc2'], 'pc2', 'pc4', 'def_up_2', 'test'];
        $apply->invokeArgs($engine, $args);
        $this->assertSame(2, $this->stageSum($state['units']['pc2'], 'def'));
    }

    public function test_aoe_that_downs_several_units_clears_each_of_them(): void
    {
        $engine = $this->engine();
        $state = $this->state();
        foreach (['pc1', 'pc2', 'pc3'] as $id) {
            $state['units'][$id]['stages'] = [$this->stageEntry('def', 2), $this->stageEntry('atk', 1)];
            $state['units'][$id]['buffs'] = ['evasion_set_small' => 3];
        }
        // pc3 だけ、耐える
        $state['units']['pc3']['hp'] = 400;
        $state['units']['pc3']['max_hp'] = 500;

        foreach (['pc1', 'pc2'] as $id) {
            $this->lethalDamage($engine, $state, $id);
        }
        $this->damage($engine, $state, 'pc3', 50);

        foreach (['pc1', 'pc2'] as $id) {
            $this->assertFalse($state['units'][$id]['alive']);
            $this->assertSame([], $state['units'][$id]['stages']);
            $this->assertSame([], $state['units'][$id]['buffs']);
        }
        $this->assertTrue($state['units']['pc3']['alive']);
        $this->assertSame(2, $this->stageSum($state['units']['pc3'], 'def'));
        $this->assertSame(['evasion_set_small' => 3], $state['units']['pc3']['buffs']);
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

    /** 戦闘不能になる（その時点の残りHP以上の）ダメージを与える。命の加護があれば 1 残る。 */
    private function lethalDamage(BattleEngine $engine, array &$state, string $targetId): void
    {
        $this->damage($engine, $state, $targetId, (int) $state['units'][$targetId]['hp'] + 100);
    }

    private function damage(BattleEngine $engine, array &$state, string $targetId, int $damage): void
    {
        $actorId = str_starts_with($targetId, 'enemy') ? 'pc1' : 'enemy_1';
        $method = new \ReflectionMethod(BattleEngine::class, 'applyDamage');
        $method->setAccessible(true);
        $args = [&$state, $actorId, $targetId, $damage, null, false];
        $method->invokeArgs($engine, $args);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $ally = static fn (string $id, string $name, int $spd) => [
            'id' => $id,
            'name' => $name,
            'sprite' => 'test',
            'side' => 'ally',
            'hp' => 500,
            'max_hp' => 500,
            'mp' => 200,
            'max_mp' => 200,
            'str' => 100,
            'mag' => 100,
            'def' => 100,
            'pdef' => 100,
            'mdef' => 100,
            'spd' => $spd,
            'crit_rate' => 0,
            'evasion_rate' => 0,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'stages' => [],
            'statuses' => [],
            'spells' => [],
        ];
        $enemy = static fn (string $id, string $name) => [
            'id' => $id,
            'name' => $name,
            'sprite' => 'test',
            'side' => 'enemy',
            'master_code' => 'test_enemy',
            'hp' => 300,
            'max_hp' => 300,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 10,
            'mag' => 10,
            'def' => 10,
            'pdef' => 10,
            'mdef' => 10,
            'spd' => 1,
            'crit_rate' => 0,
            'evasion_rate' => 0,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'stages' => [],
            'statuses' => [],
            'spells' => [],
            'exp_reward' => 20,
            'gold_reward' => 5,
        ];

        $units = [
            'pc1' => $ally('pc1', 'サフィリア', 90),
            'pc2' => $ally('pc2', 'ガルデ', 80),
            'pc3' => $ally('pc3', 'グレン', 70),
            'pc4' => $ally('pc4', 'シエナ', 60),
            'enemy_1' => $enemy('enemy_1', 'テスト敵A'),
            'enemy_2' => $enemy('enemy_2', 'テスト敵B'),
        ];

        return [
            'status' => 'active',
            'turn_order' => ['pc1', 'pc2', 'pc3', 'pc4', 'enemy_1', 'enemy_2'],
            'turn_index' => 0,
            'current_actor' => 'pc1',
            'awaiting_input' => true,
            'units' => $units,
            'meta' => [],
            'log' => [],
        ];
    }
}
