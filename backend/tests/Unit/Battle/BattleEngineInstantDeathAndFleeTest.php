<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\EnemySkillPicker;
use App\Services\MasterData\MasterDataProvider;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 刈り取る奴のソウルハント（即死魔法 zaraki）と、ミミックの逃走。
 */
class BattleEngineInstantDeathAndFleeTest extends TestCase
{
    public function test_zaraki_and_mimic_rows_are_defined(): void
    {
        $masters = new MasterDataProvider;

        $zaraki = $masters->findSpell('zaraki');
        $this->assertSame(['ソウルハント', 'enemy_single', 'instant_death', 10], [
            $zaraki['label'], $zaraki['target_type'], $zaraki['effect'], $zaraki['power'],
        ]);

        $mimic = collect($masters->enemySkillsFor('mimic'));
        $this->assertSame(['attack' => 50, 'flee' => 50], $mimic->pluck('weight', 'skill_code')->all());
        $this->assertSame('flee', $mimic->firstWhere('skill_code', 'flee')['action_type']);
    }

    public function test_zaraki_kills_one_target_when_the_roll_is_within_ten_percent(): void
    {
        $result = $this->zarakiEngine(hitRoll: 10)->runEnemyTurnsUntilPlayer($this->zarakiState());

        $deaths = array_values(array_filter(
            $result['events'],
            fn (array $e) => $e['type'] === 'damage' && ($e['instant_death'] ?? false),
        ));
        $this->assertCount(1, $deaths);
        $this->assertSame($deaths[0]['value'], 50, '残りHPぶんのダメージとして扱う');

        $victim = $result['state']['units'][$deaths[0]['target']];
        $this->assertSame(0, $victim['hp']);
        $this->assertFalse($victim['alive']);

        // もう一人は無事で、戦闘は続く
        $alive = array_filter($result['state']['units'], fn (array $u) => $u['side'] === 'ally' && $u['alive']);
        $this->assertCount(1, $alive);
        $this->assertSame('active', $result['state']['status']);

        $announce = collect($result['events'])->firstWhere('type', 'announce');
        $this->assertSame('刈り取る奴はソウルハントを唱えた！', $announce['text']);
        $this->assertSame('instant_death', $announce['spell_effect']);
    }

    public function test_zaraki_does_nothing_when_the_roll_is_above_ten_percent(): void
    {
        $result = $this->zarakiEngine(hitRoll: 11)->runEnemyTurnsUntilPlayer($this->zarakiState());

        $types = array_column($result['events'], 'type');
        $this->assertContains('spell_failed', $types);
        $this->assertNotContains('damage', $types);
        $this->assertSame(50, $result['state']['units']['pc1']['hp']);
        $this->assertSame(50, $result['state']['units']['pc2']['hp']);
    }

    public function test_barrier_blocks_zaraki_once(): void
    {
        $state = $this->zarakiState();
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;
        $state['units']['pc1']['damage_shield'] = true;

        $result = $this->zarakiEngine(hitRoll: 1)->runEnemyTurnsUntilPlayer($state);

        $types = array_column($result['events'], 'type');
        $this->assertContains('shield_break', $types);
        $this->assertNotContains('damage', $types);
        $this->assertTrue($result['state']['units']['pc1']['alive']);
        $this->assertSame(50, $result['state']['units']['pc1']['hp']);
        $this->assertFalse($result['state']['units']['pc1']['damage_shield']);
    }

    public function test_mimic_flees_and_the_battle_ends_in_victory_without_rewards(): void
    {
        $engine = new BattleEngine(enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 60));

        $result = $engine->runEnemyTurnsUntilPlayer($this->mimicState());

        $mimic = $result['state']['units']['enemy_1'];
        $this->assertFalse($mimic['alive']);
        $this->assertTrue($mimic['fled']);
        $this->assertSame(100, $mimic['hp'], '倒したわけではない');

        $this->assertSame('victory', $result['state']['status']);
        $types = array_column($result['events'], 'type');
        $this->assertContains('enemy_fled', $types);
        $this->assertContains('battle_end', $types);
        $this->assertNotContains('gold_gained', $types);
        $this->assertSame(0, (int) ($result['state']['meta']['gold_earned'] ?? 0));
        $this->assertSame(0, (int) ($result['state']['meta']['exp_earned'] ?? 0));

        $announce = collect($result['events'])->firstWhere('type', 'announce');
        $this->assertSame('ミミックは逃げ出した！', $announce['text']);

        $public = $engine->publicState($result['state']);
        $this->assertTrue(collect($public['units'])->firstWhere('id', 'enemy_1')['fled']);
    }

    public function test_mimic_attacks_when_it_does_not_flee(): void
    {
        $engine = new BattleEngine(
            enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 10),
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );

        $result = $engine->runEnemyTurnsUntilPlayer($this->mimicState());

        $this->assertSame('active', $result['state']['status']);
        $this->assertTrue($result['state']['units']['enemy_1']['alive']);
        $this->assertNotContains('enemy_fled', array_column($result['events'], 'type'));
        $this->assertSame('ミミックの攻撃！', collect($result['events'])->firstWhere('type', 'announce')['text']);
    }

    public function test_battle_continues_when_other_enemies_remain_and_the_fled_one_cannot_be_targeted(): void
    {
        $state = $this->mimicState();
        $state['units']['enemy_2'] = $state['units']['enemy_1'];
        $state['units']['enemy_2']['id'] = 'enemy_2';
        $state['units']['enemy_2']['name'] = 'コーモリ';
        $state['units']['enemy_2']['master_code'] = 'bat';
        $state['turn_order'] = ['enemy_1', 'enemy_2', 'pc1'];

        $engine = new BattleEngine(
            enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 60),
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
        $result = $engine->runEnemyTurnsUntilPlayer($state);

        $this->assertTrue($result['state']['units']['enemy_1']['fled']);
        $this->assertTrue($result['state']['units']['enemy_2']['alive']);
        $this->assertSame('active', $result['state']['status']);
        $this->assertTrue($result['state']['awaiting_input']);

        $this->expectException(InvalidArgumentException::class);
        $engine->applyPlayerAction($result['state'], 'pc1', 'punch', null, 'enemy_1');
    }

    private function zarakiEngine(int $hitRoll): BattleEngine
    {
        $picker = new class extends EnemySkillPicker
        {
            public function pick(string $enemyCode): array
            {
                return [
                    'skill_code' => 'zaraki',
                    'label' => 'ソウルハント',
                    'action_type' => 'spell',
                    'target_type' => '',
                    'coefficient' => 0.0,
                ];
            }
        };

        return new BattleEngine(
            enemySkillPicker: $picker,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => $hitRoll,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function zarakiState(): array
    {
        $state = $this->mimicState();
        $state['units']['enemy_1']['name'] = '刈り取る奴';
        $state['units']['enemy_1']['master_code'] = 'shinigami';
        $state['units']['pc2'] = [...$state['units']['pc1'], 'id' => 'pc2', 'name' => 'PC2'];
        $state['turn_order'] = ['enemy_1', 'pc1', 'pc2'];

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function mimicState(): array
    {
        return [
            'status' => 'active',
            'current_actor' => 'enemy_1',
            'awaiting_input' => false,
            'turn_order' => ['enemy_1', 'pc1'],
            'turn_index' => 0,
            'units' => [
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'name' => 'ミミック',
                    'side' => 'enemy',
                    'master_code' => 'mimic',
                    'sprite' => '2_mimic',
                    'hp' => 100,
                    'max_hp' => 100,
                    'mp' => 0,
                    'max_mp' => 0,
                    'str' => 50,
                    'mag' => 30,
                    'pdef' => 100,
                    'mdef' => 100,
                    'def' => 100,
                    'spd' => 60,
                    'gold_reward' => 5000,
                    'exp_reward' => 1000,
                    'crit_rate' => 1,
                    'evasion_rate' => 1,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
                'pc1' => [
                    'id' => 'pc1',
                    'name' => 'PC1',
                    'side' => 'ally',
                    'sprite' => 'pc1',
                    'hp' => 50,
                    'max_hp' => 50,
                    'mp' => 10,
                    'max_mp' => 10,
                    'str' => 10,
                    'mag' => 10,
                    'def' => 5,
                    'spd' => 10,
                    'crit_rate' => 5,
                    'evasion_rate' => 5,
                    'damage_shield' => false,
                    'alive' => true,
                    'buffs' => [],
                    'statuses' => [],
                    'spells' => [],
                ],
            ],
            'log' => [],
        ];
    }
}
