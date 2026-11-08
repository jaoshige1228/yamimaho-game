<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\EnemySkillPicker;
use Tests\TestCase;

/**
 * 回避率は、物理攻撃にもダメージ魔法にも効く。
 * - 敵が味方を攻撃するとき … 素の回避率（味方5%）か、回避バフ（水の幻影 = 60%、水の幻影群 = 30%）で常に判定する
 * - 味方が敵を攻撃するとき … 素の回避率（敵1%）で判定するのは通常のこぶしだけ。回避バフなら魔法・キックも判定する
 */
class BattleEngineEvasionBuffTest extends TestCase
{
    public function test_player_damage_spell_is_dodged_by_an_enemy_with_the_evasion_buff(): void
    {
        $result = $this->playerSpell('pc3_fire_shot', hitRoll: 1, enemyBuffs: ['evasion_set' => 3]);

        $types = array_column($result['events'], 'type');
        $this->assertContains('miss', $types);
        $this->assertNotContains('damage', $types);
        $this->assertSame(100, $result['state']['units']['enemy_1']['hp']);
    }

    public function test_player_damage_spell_hits_when_the_evasion_roll_fails(): void
    {
        $result = $this->playerSpell('pc3_fire_shot', hitRoll: 100, enemyBuffs: ['evasion_set' => 3]);

        $this->assertContains('damage', array_column($result['events'], 'type'));
        $this->assertLessThan(100, $result['state']['units']['enemy_1']['hp']);
    }

    public function test_player_damage_spell_ignores_the_enemy_base_evasion_rate_without_the_buff(): void
    {
        // 味方が敵に撃つ魔法には、敵の素の回避率（100%にしても）は効かない。回避バフがあるときだけ判定する
        $result = $this->playerSpell('pc3_fire_shot', hitRoll: 1, enemyBuffs: [], enemyEvasionRate: 100);

        $this->assertContains('damage', array_column($result['events'], 'type'));
    }

    public function test_enemy_normal_attack_is_dodged_by_an_ally_with_the_evasion_buff(): void
    {
        $result = $this->enemyTurn(
            ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0],
            hitRolls: [1],
            allyBuffs: ['evasion_set' => 3],
        );

        $types = array_column($result['events'], 'type');
        $this->assertContains('miss', $types);
        $this->assertNotContains('damage', $types);
        $this->assertSame(100, $this->totalAllyHp($result['state']));
    }

    public function test_enemy_normal_attack_hits_when_the_evasion_roll_fails(): void
    {
        $result = $this->enemyTurn(
            ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0],
            hitRolls: [100],
            allyBuffs: ['evasion_set' => 3],
        );

        $this->assertContains('damage', array_column($result['events'], 'type'));
    }

    public function test_enemy_attack_is_dodged_by_the_ally_base_evasion_rate_without_the_buff(): void
    {
        $attack = ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0];

        // 味方の素の回避率5%：ロール5は回避、6は命中
        $dodged = $this->enemyTurn($attack, hitRolls: [5], allyBuffs: []);
        $this->assertContains('miss', array_column($dodged['events'], 'type'));
        $this->assertNotContains('damage', array_column($dodged['events'], 'type'));
        $this->assertSame(100, $this->totalAllyHp($dodged['state']));

        $hit = $this->enemyTurn($attack, hitRolls: [6], allyBuffs: []);
        $this->assertContains('damage', array_column($hit['events'], 'type'));
        $this->assertNotContains('miss', array_column($hit['events'], 'type'));
    }

    public function test_enemy_attack_uses_the_ally_evasion_rate_value(): void
    {
        $attack = ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0];

        // 素の回避率が0なら、ロール1でも必ず当たる
        $result = $this->enemyTurn($attack, hitRolls: [1], allyBuffs: [], allyEvasionRate: 0);

        $this->assertContains('damage', array_column($result['events'], 'type'));
    }

    public function test_enemy_all_target_skill_rolls_the_base_evasion_rate_for_each_ally_without_the_buff(): void
    {
        $result = $this->enemyTurn(
            ['skill_code' => 'flame_breath', 'label' => '火炎放射', 'action_type' => 'physical_all', 'target_type' => 'ally_all', 'coefficient' => 0.9],
            hitRolls: [1, 100],
            allyBuffs: [],
        );

        $types = array_column($result['events'], 'type');
        $this->assertSame(1, count(array_keys($types, 'miss', true)));
        $this->assertSame(1, count(array_keys($types, 'damage', true)));
    }

    public function test_enemy_all_target_physical_skill_rolls_evasion_for_each_ally(): void
    {
        // 1人目は回避（ロール1）、2人目は命中（ロール100）
        $result = $this->enemyTurn(
            ['skill_code' => 'flame_breath', 'label' => '火炎放射', 'action_type' => 'physical_all', 'target_type' => 'ally_all', 'coefficient' => 0.9],
            hitRolls: [1, 100],
            allyBuffs: ['evasion_set' => 3],
        );

        $types = array_column($result['events'], 'type');
        $this->assertSame(1, count(array_keys($types, 'miss', true)));
        $this->assertSame(1, count(array_keys($types, 'damage', true)));
    }

    public function test_enemy_all_target_damage_spell_rolls_evasion_for_each_ally(): void
    {
        $result = $this->enemyTurn(
            ['skill_code' => 'pc3_fire_wave', 'label' => '火炎波及', 'action_type' => 'spell', 'target_type' => '', 'coefficient' => 0.0],
            hitRolls: [1, 100],
            allyBuffs: ['evasion_set' => 3],
        );

        $types = array_column($result['events'], 'type');
        $this->assertSame(1, count(array_keys($types, 'miss', true)));
        $this->assertSame(1, count(array_keys($types, 'damage', true)));
    }

    public function test_enemy_damage_spell_is_dodged_by_the_ally_base_evasion_rate_without_the_buff(): void
    {
        $spell = ['skill_code' => 'pc3_fire_shot', 'label' => '炎弾', 'action_type' => 'spell', 'target_type' => '', 'coefficient' => 0.0];

        $dodged = $this->enemyTurn($spell, hitRolls: [5], allyBuffs: []);
        $this->assertContains('miss', array_column($dodged['events'], 'type'));
        $this->assertNotContains('damage', array_column($dodged['events'], 'type'));

        $hit = $this->enemyTurn($spell, hitRolls: [6], allyBuffs: []);
        $this->assertContains('damage', array_column($hit['events'], 'type'));
    }

    public function test_small_evasion_buff_dodges_up_to_thirty_percent(): void
    {
        $attack = ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0];

        $dodged = $this->enemyTurn($attack, hitRolls: [30], allyBuffs: ['evasion_set_small' => 3]);
        $this->assertContains('miss', array_column($dodged['events'], 'type'));

        $hit = $this->enemyTurn($attack, hitRolls: [31], allyBuffs: ['evasion_set_small' => 3]);
        $this->assertContains('damage', array_column($hit['events'], 'type'));
    }

    public function test_expired_evasion_buff_falls_back_to_the_base_evasion_rate(): void
    {
        $attack = ['skill_code' => 'attack', 'label' => '攻撃', 'action_type' => 'punch', 'target_type' => 'ally_single', 'coefficient' => 1.0];

        // 切れたバフ(0ターン)は無効。ロール30は、60%のバフなら回避だが、素の5%なら命中
        $result = $this->enemyTurn($attack, hitRolls: [30], allyBuffs: ['evasion_set' => 0]);

        $this->assertContains('damage', array_column($result['events'], 'type'));
    }

    public function test_non_damaging_enemy_spell_still_lands_on_an_ally_with_the_evasion_buff(): void
    {
        // 防御ダウン（闇の呪縛）は回避できない
        $result = $this->enemyTurn(
            ['skill_code' => 'cult_def_down', 'label' => '闇の呪縛', 'action_type' => 'spell', 'target_type' => '', 'coefficient' => 0.0],
            hitRolls: [1, 1],
            allyBuffs: ['evasion_set' => 3],
        );

        $types = array_column($result['events'], 'type');
        $this->assertNotContains('miss', $types);
        $this->assertContains('buff_applied', $types);
    }

    public function test_instant_death_spell_can_be_dodged_with_the_evasion_buff(): void
    {
        // ロール10は即死の成功圏内（10%）だが、回避バフ（60%）が先に判定される
        $result = $this->enemyTurn(
            ['skill_code' => 'zaraki', 'label' => 'ソウルハント', 'action_type' => 'spell', 'target_type' => '', 'coefficient' => 0.0],
            hitRolls: [10, 10],
            allyBuffs: ['evasion_set' => 3],
        );

        $types = array_column($result['events'], 'type');
        $this->assertContains('miss', $types);
        $this->assertNotContains('damage', $types);
        $this->assertSame(100, $this->totalAllyHp($result['state']));
    }

    /**
     * @param  array<string, int>  $enemyBuffs
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function playerSpell(string $spellId, int $hitRoll, array $enemyBuffs, int $enemyEvasionRate = 1): array
    {
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => $hitRoll,
            critRoll: static fn (): int => 100,
        );

        $state = [
            'status' => 'active',
            'current_actor' => 'pc1',
            'awaiting_input' => true,
            'turn_order' => ['pc1', 'enemy_1'],
            'turn_index' => 0,
            'units' => [
                'pc1' => [...$this->unit('pc1', 'ally', [], 5), 'spells' => [$spellId]],
                'enemy_1' => $this->unit('enemy_1', 'enemy', $enemyBuffs, $enemyEvasionRate),
            ],
            'log' => [],
        ];

        return $engine->applyPlayerAction($state, 'pc1', 'spell', $spellId, 'enemy_1');
    }

    /**
     * @param  array<string, mixed>  $skill
     * @param  list<int>  $hitRolls
     * @param  array<string, int>  $allyBuffs
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function enemyTurn(array $skill, array $hitRolls, array $allyBuffs, int $allyEvasionRate = 5): array
    {
        $picker = new class($skill) extends EnemySkillPicker
        {
            /** @param array<string, mixed> $skill */
            public function __construct(private array $skill) {}

            public function pick(string $enemyCode): array
            {
                return $this->skill;
            }
        };

        $rolls = $hitRolls;
        $engine = new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: $picker,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static function () use (&$rolls): int {
                return array_shift($rolls) ?? 100;
            },
            critRoll: static fn (): int => 100,
        );

        $isSingleTarget = in_array($skill['skill_code'], ['attack', 'pc3_fire_shot', 'zaraki'], true);

        // 単体攻撃は対象が決まるよう味方1人、全体攻撃・全体魔法は2人にする
        $allies = ['pc1' => $this->unit('pc1', 'ally', $allyBuffs, $allyEvasionRate)];
        if (! $isSingleTarget) {
            $allies['pc2'] = $this->unit('pc2', 'ally', $allyBuffs, $allyEvasionRate);
        }

        $state = [
            'status' => 'active',
            'current_actor' => 'enemy_1',
            'awaiting_input' => false,
            'turn_order' => ['enemy_1', ...array_keys($allies)],
            'turn_index' => 0,
            'units' => ['enemy_1' => $this->unit('enemy_1', 'enemy', [], 1)] + $allies,
            'log' => [],
        ];

        return $engine->runEnemyTurnsUntilPlayer($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function totalAllyHp(array $state): int
    {
        return (int) array_sum(array_map(
            static fn (array $u): int => $u['side'] === 'ally' ? (int) $u['hp'] : 0,
            $state['units'],
        ));
    }

    /**
     * @param  array<string, int>  $buffs
     * @return array<string, mixed>
     */
    private function unit(string $id, string $side, array $buffs, int $evasionRate): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'side' => $side,
            'master_code' => $id,
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 50,
            'max_mp' => 50,
            'str' => 30,
            'mag' => 30,
            'def' => 5,
            'pdef' => 5,
            'mdef' => 5,
            'spd' => 10,
            'crit_rate' => 0,
            'evasion_rate' => $evasionRate,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => $buffs,
            'statuses' => [],
            'spells' => [],
        ];
    }
}
