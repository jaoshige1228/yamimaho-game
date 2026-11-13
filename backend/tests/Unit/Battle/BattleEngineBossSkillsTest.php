<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\EnemySkillPicker;
use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

/**
 * 2層ボス（エルドラゴン・邪教徒）の技と魔法。
 */
class BattleEngineBossSkillsTest extends TestCase
{
    public function test_dragon_skills_are_defined(): void
    {
        $masters = new MasterDataProvider;
        $dragon = collect($masters->enemySkillsFor('dragon'))->keyBy('skill_code');

        $this->assertSame(
            ['attack', 'dragon_claw', 'dragon_breath'],
            $dragon->keys()->all(),
        );

        // ドラゴンブレスはイフリーヌの火炎放射と同じ（全体・同じ係数）
        $flameBreath = collect($masters->enemySkillsFor('inu_moe'))->firstWhere('skill_code', 'flame_breath');
        $breath = $dragon['dragon_breath'];
        $this->assertSame('ドラゴンブレス', $breath['label']);
        $this->assertSame(
            [$flameBreath['action_type'], $flameBreath['target_type'], $flameBreath['coefficient']],
            [$breath['action_type'], $breath['target_type'], $breath['coefficient']],
        );

        // ドラゴンクロウは単体の大ダメージ（イフリーヌの炎のキバより強い）
        $flameBite = collect($masters->enemySkillsFor('inu_moe'))->firstWhere('skill_code', 'flame_bite');
        $claw = $dragon['dragon_claw'];
        $this->assertSame('ドラゴンクロウ', $claw['label']);
        $this->assertSame(['physical_single', 'ally_single'], [$claw['action_type'], $claw['target_type']]);
        $this->assertGreaterThan($flameBite['coefficient'], $claw['coefficient']);
    }

    public function test_cultist_spells_are_defined(): void
    {
        $masters = new MasterDataProvider;

        $skills = collect($masters->enemySkillsFor('mahou'))->keyBy('skill_code');
        $this->assertSame(
            ['attack', 'cult_def_up', 'cult_def_down', 'cult_heal'],
            $skills->keys()->all(),
        );
        foreach (['cult_def_up', 'cult_def_down', 'cult_heal'] as $code) {
            $this->assertSame('spell', $skills[$code]['action_type'], $code);
        }

        $up = $masters->findSpell('cult_def_up');
        $this->assertSame(['ally_all', 'buff_def', 'def_up_1'], [$up['target_type'], $up['effect'], $up['buff']]);
        $this->assertStringContainsString('防御力を1段階上げる', $up['description']);

        $down = $masters->findSpell('cult_def_down');
        $this->assertSame(['enemy_all', 'debuff_def', 'def_down_1'], [$down['target_type'], $down['effect'], $down['buff']]);
        $this->assertStringContainsString('防御力を1段階下げる', $down['description']);

        $heal = $masters->findSpell('cult_heal');
        $this->assertSame(['ally_single', 'heal_mag', 2.5], [$heal['target_type'], $heal['effect'], $heal['coefficient']]);
    }

    public function test_every_enemy_spell_row_points_at_an_existing_spell(): void
    {
        $masters = new MasterDataProvider;

        foreach ($masters->enemySkills() as $enemyCode => $skills) {
            foreach ($skills as $skill) {
                if ($skill['action_type'] === 'spell') {
                    $this->assertNotSame('', (string) $masters->findSpell($skill['skill_code'])['label'], "{$enemyCode}/{$skill['skill_code']}");
                }
            }
        }
    }

    public function test_dragon_breath_hits_every_living_ally(): void
    {
        $state = $this->bossState(actor: 'enemy_2');
        $state['units']['pc2']['alive'] = false;
        $state['units']['pc2']['hp'] = 0;

        $result = $this->engine('dragon_breath')->runEnemyTurnsUntilPlayer($state);

        $damages = $this->eventsOfType($result['events'], 'damage');
        $this->assertSame(['pc1'], array_column($damages, 'target'));
        // (200 - 40 / 2) × 0.9
        $this->assertSame(162, $damages[0]['value']);
        $this->assertSame('エルドラゴンのドラゴンブレス！', collect($result['events'])->firstWhere('type', 'announce')['text']);

        $state['units']['pc2']['alive'] = true;
        $state['units']['pc2']['hp'] = 1000;
        $result = $this->engine('dragon_breath')->runEnemyTurnsUntilPlayer($state);
        $this->assertEqualsCanonicalizing(['pc1', 'pc2'], array_column($this->eventsOfType($result['events'], 'damage'), 'target'));
    }

    public function test_dragon_claw_hits_one_ally_hard(): void
    {
        $result = $this->engine('dragon_claw')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_2'));

        $damages = $this->eventsOfType($result['events'], 'damage');
        $this->assertCount(1, $damages);
        // (200 - 40 / 2) × 1.5
        $this->assertSame(270, $damages[0]['value']);
        $this->assertSame('エルドラゴンのドラゴンクロウ！', collect($result['events'])->firstWhere('type', 'announce')['text']);
    }

    public function test_cultist_defense_spell_buffs_every_living_enemy_unit(): void
    {
        $result = $this->engine('cult_def_up')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));

        $buffed = $this->eventsOfType($result['events'], 'buff_applied');
        $this->assertEqualsCanonicalizing(['enemy_1', 'enemy_2'], array_column($buffed, 'target'));
        $this->assertSame(['def_up_1', 'def_up_1'], array_column($buffed, 'buff'));

        // 防御1段階上昇（×1.2）。倒れた味方・プレイヤーにはかからない
        $stage = fn (string $id): int => array_sum(array_column($result['state']['units'][$id]['stages'] ?? [], 'amount'));
        $this->assertSame(1, $stage('enemy_1'));
        $this->assertSame(1, $stage('enemy_2'));
        $this->assertSame(0, $stage('enemy_3'), '倒れた味方にはかからない');
        $this->assertSame(0, $stage('pc1'));
        $this->assertSame('邪教徒 Aは闇の加護を唱えた！', collect($result['events'])->firstWhere('type', 'announce')['text']);
    }

    public function test_cultist_debuff_spell_lowers_defense_of_every_living_player(): void
    {
        $result = $this->engine('cult_def_down')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));

        $debuffed = $this->eventsOfType($result['events'], 'buff_applied');
        $this->assertEqualsCanonicalizing(['pc1', 'pc2'], array_column($debuffed, 'target'));
        $this->assertSame(['def_down_1', 'def_down_1'], array_column($debuffed, 'buff'));
        $units = $result['state']['units'];
        $this->assertSame([-1], array_column($units['pc1']['stages'], 'amount'));
        $this->assertSame(3, $units['pc1']['stages'][0]['turns']);
        $this->assertSame([], $units['enemy_2']['stages'] ?? []);
    }

    public function test_cultist_heal_targets_the_most_wounded_ally_for_mag_times_2_5(): void
    {
        $state = $this->bossState(actor: 'enemy_1');
        $state['units']['enemy_1']['hp'] = 450; // 90%
        $state['units']['enemy_2']['hp'] = 400; // 40%
        $state['units']['enemy_3']['alive'] = true;
        $state['units']['enemy_3']['hp'] = 100; // 20%（いちばん傷ついている）

        $result = $this->engine('cult_heal')->runEnemyTurnsUntilPlayer($state);

        $heals = $this->eventsOfType($result['events'], 'heal');
        $this->assertCount(1, $heals);
        $this->assertSame('enemy_3', $heals[0]['target']);
        // 魔力 150 × 2.5 = 375（不足ぶん 400 以内）
        $this->assertSame(375, $heals[0]['value']);
        $this->assertSame(475, $result['state']['units']['enemy_3']['hp']);
        $this->assertSame(400, $result['state']['units']['enemy_2']['hp']);
    }

    public function test_cultist_heal_never_targets_a_fallen_ally(): void
    {
        // 倒れた邪教徒 B（HP 0/500 = 割合はいちばん低い）がいても、回復は生きている味方に向かい、倒れた味方は戻らない
        $state = $this->bossState(actor: 'enemy_1');
        $state['units']['enemy_2']['hp'] = 400; // 40%

        $result = $this->engine('cult_heal')->runEnemyTurnsUntilPlayer($state);

        $heals = $this->eventsOfType($result['events'], 'heal');
        $this->assertCount(1, $heals);
        $this->assertSame('enemy_2', $heals[0]['target']);
        $this->assertSame(0, $result['state']['units']['enemy_3']['hp']);
        $this->assertFalse($result['state']['units']['enemy_3']['alive']);
    }

    public function test_cultist_heal_never_exceeds_max_hp(): void
    {
        $state = $this->bossState(actor: 'enemy_1');
        $state['units']['enemy_2']['hp'] = 990;

        $result = $this->engine('cult_heal')->runEnemyTurnsUntilPlayer($state);

        $this->assertSame(10, $this->eventsOfType($result['events'], 'heal')[0]['value']);
        $this->assertSame(1000, $result['state']['units']['enemy_2']['hp']);
    }

    public function test_cultist_attacks_instead_when_nobody_needs_healing(): void
    {
        $result = $this->engine('cult_heal')->runEnemyTurnsUntilPlayer($this->bossState(actor: 'enemy_1'));

        $this->assertSame([], $this->eventsOfType($result['events'], 'heal'));
        $this->assertSame('邪教徒 Aの攻撃！', collect($result['events'])->firstWhere('type', 'announce')['text']);

        $damages = $this->eventsOfType($result['events'], 'damage');
        $this->assertCount(1, $damages);
        $this->assertSame('ally', $result['state']['units'][$damages[0]['target']]['side']);
    }

    public function test_gusu_and_kasu_skills_are_defined(): void
    {
        $masters = new MasterDataProvider;

        $kasu = collect($masters->enemySkillsFor('npc_kasu'))->pluck('weight', 'skill_code');
        $this->assertSame(['pc3_fire_shot', 'pc3_fire_burst', 'pc3_fire_wave'], $kasu->keys()->all());
        $this->assertSame('pc3_fire_burst', $kasu->sortDesc()->keys()->first(), '強い魔法（爆破）がいちばん出やすい');

        $gusu = collect($masters->enemySkillsFor('npc_gusu'))->pluck('weight', 'skill_code');
        $this->assertSame(['pc2_vine_whip', 'pc2_vine_bind', 'pc2_burst_tree', 'pc2_world_tree'], $gusu->keys()->all());
        $this->assertSame('pc2_vine_whip', $gusu->sortDesc()->keys()->first(), 'ダメージの出る魔法がいちばん出やすい');
    }

    public function test_gusu_attacks_instead_when_nobody_has_fallen(): void
    {
        $state = $this->gusuState();
        $state['units']['enemy_3']['alive'] = true;
        $state['units']['enemy_3']['hp'] = 500;

        $result = $this->engine('pc2_world_tree')->runEnemyTurnsUntilPlayer($state);

        $this->assertSame('グースの攻撃！', collect($result['events'])->firstWhere('type', 'announce')['text']);
        $this->assertSame([], $this->eventsOfType($result['events'], 'revive_failed'));
        $this->assertSame([], $this->eventsOfType($result['events'], 'revive'));
        $this->assertCount(1, $this->eventsOfType($result['events'], 'damage'));
    }

    public function test_gusu_attacks_instead_when_the_only_fallen_enemy_has_fled(): void
    {
        $state = $this->gusuState();
        $state['units']['enemy_3']['fled'] = true;

        $result = $this->engine('pc2_world_tree')->runEnemyTurnsUntilPlayer($state);

        $this->assertSame('グースの攻撃！', collect($result['events'])->firstWhere('type', 'announce')['text']);
        $this->assertFalse($result['state']['units']['enemy_3']['alive'], '逃げた敵は蘇生しない');
    }

    public function test_gusu_revives_a_fallen_ally(): void
    {
        $result = $this->engine('pc2_world_tree', hitRoll: 1)->runEnemyTurnsUntilPlayer($this->gusuState());

        $this->assertSame('グースは宇宙樹の気配を唱えた！', collect($result['events'])->firstWhere('type', 'announce')['text']);
        $revive = $this->eventsOfType($result['events'], 'revive');
        $this->assertCount(1, $revive);
        $this->assertSame('enemy_3', $revive[0]['target']);
        $this->assertTrue($result['state']['units']['enemy_3']['alive']);
        $this->assertSame(250, $result['state']['units']['enemy_3']['hp'], '最大HPの50%');
    }

    public function test_gusu_tries_to_revive_but_may_fail(): void
    {
        $result = $this->engine('pc2_world_tree', hitRoll: 100)->runEnemyTurnsUntilPlayer($this->gusuState());

        $this->assertCount(1, $this->eventsOfType($result['events'], 'revive_failed'));
        $this->assertFalse($result['state']['units']['enemy_3']['alive']);
    }

    /**
     * グース（enemy_1）と、倒れた味方（enemy_3）がいる状態。
     *
     * @return array<string, mixed>
     */
    private function gusuState(): array
    {
        $state = $this->bossState(actor: 'enemy_1');
        $state['units']['enemy_1']['name'] = 'グース';
        $state['units']['enemy_1']['master_code'] = 'npc_gusu';
        $state['units']['enemy_3']['name'] = 'カース';
        $state['units']['enemy_3']['master_code'] = 'npc_kasu';

        return $state;
    }

    /**
     * 敵の種類ごとに決まったスキルを必ず引くエンジン（実際のスキル内容は CSV から読む）。
     */
    private function engine(string $skillCode, int $hitRoll = 100): BattleEngine
    {
        $masters = new MasterDataProvider;
        $picker = new class($masters, $skillCode) extends EnemySkillPicker
        {
            public function __construct(private readonly MasterDataProvider $data, private readonly string $code)
            {
                parent::__construct($data);
            }

            public function pick(string $enemyCode): array
            {
                $skill = collect($this->data->enemySkillsFor($enemyCode))->firstWhere('skill_code', $this->code);

                return [
                    'skill_code' => $skill['skill_code'],
                    'label' => $skill['label'],
                    'action_type' => $skill['action_type'],
                    'target_type' => $skill['target_type'],
                    'coefficient' => (float) $skill['coefficient'],
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
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function eventsOfType(array $events, string $type): array
    {
        return array_values(array_filter($events, fn (array $e) => $e['type'] === $type));
    }

    /**
     * 邪教徒 A（enemy_1）・エルドラゴン（enemy_2）・倒れた邪教徒 B（enemy_3）と味方 2 人。
     *
     * @return array<string, mixed>
     */
    private function bossState(string $actor): array
    {
        return [
            'status' => 'active',
            'current_actor' => $actor,
            'awaiting_input' => false,
            'turn_order' => [$actor, 'pc1'],
            'turn_index' => 0,
            'units' => [
                'enemy_1' => $this->enemy('enemy_1', '邪教徒 A', 'mahou', hp: 500, str: 80, mag: 150, spd: 40),
                'enemy_2' => $this->enemy('enemy_2', 'エルドラゴン', 'dragon', hp: 1000, str: 200, mag: 100, spd: 50),
                'enemy_3' => [...$this->enemy('enemy_3', '邪教徒 B', 'mahou', hp: 500, str: 80, mag: 150, spd: 40), 'hp' => 0, 'alive' => false],
                'pc1' => $this->ally('pc1'),
                'pc2' => $this->ally('pc2'),
            ],
            'log' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enemy(string $id, string $name, string $code, int $hp, int $str, int $mag, int $spd): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'side' => 'enemy',
            'master_code' => $code,
            'sprite' => '2_'.$code,
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 0,
            'max_mp' => 0,
            'str' => $str,
            'mag' => $mag,
            'pdef' => 70,
            'mdef' => 70,
            'def' => 70,
            'spd' => $spd,
            'gold_reward' => 0,
            'exp_reward' => 0,
            'crit_rate' => 1,
            'evasion_rate' => 1,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ally(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'side' => 'ally',
            'sprite' => $id,
            'hp' => 1000,
            'max_hp' => 1000,
            'mp' => 10,
            'max_mp' => 10,
            'str' => 10,
            'mag' => 10,
            'def' => 40,
            'spd' => 10,
            'crit_rate' => 5,
            'evasion_rate' => 5,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'statuses' => [],
            'spells' => [],
        ];
    }
}
