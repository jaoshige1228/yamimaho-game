<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\MasterData\MasterDataProvider;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 3 層のボス（ゼロ）を倒すと覚える 5 つの魔法（2026-11-14）。
 * サフィリア: 奇跡の虹・奇跡の雫 / ガルデ: 闘志の旋律 / グレン: 魔力集中 / シエナ: 応急手当
 */
class Floor3BossSpellsTest extends TestCase
{
    use StageHelpers;

    public function test_the_spell_data_matches_the_spec(): void
    {
        $masters = new MasterDataProvider;

        $rainbow = $masters->findSpell('pc1_miracle_rainbow');
        $this->assertSame(['奇跡の虹', 64, 'ally_all', 'heal_full'], [$rainbow['label'], $rainbow['mp_cost'], $rainbow['target_type'], $rainbow['effect']]);
        $this->assertSame('希望の架け橋となる奇跡の虹。味方全員のHPを全回復する。', $rainbow['description']);

        $drop = $masters->findSpell('pc1_miracle_drop');
        $this->assertSame(['奇跡の雫', 40, 'ally_single', 'revive_chance', 100], [$drop['label'], $drop['mp_cost'], $drop['target_type'], $drop['effect'], $drop['power']]);
        $this->assertSame('莫大な生命力を秘めた雫を生成する。味方単体を半分のHPで確実に蘇生する。', $drop['description']);

        $melody = $masters->findSpell('pc2_fighting_melody');
        $this->assertSame(['闘志の旋律', 38, 'ally_all', 'buff', 'atk_up_1'], [$melody['label'], $melody['mp_cost'], $melody['target_type'], $melody['effect'], $melody['buff']]);
        $this->assertSame('反逆心を鼓舞させる力強い旋律。味方全体の攻撃力を1段階上げる。', $melody['description']);

        $focus = $masters->findSpell('pc3_mana_focus');
        $this->assertSame(['魔力集中', 0, 'self', 'mp_heal_hp_cost', 50, 200], [$focus['label'], $focus['mp_cost'], $focus['target_type'], $focus['effect'], $focus['power'], $focus['hp_cost']]);
        $this->assertSame('自身の血を糧とし、魔力を回復する。HPが減る代わりに、MPを回復する。', $focus['description']);

        // 応急手当は、迅速調合と同じ回復量・同じ消費 MP
        $firstAid = $masters->findSpell('pc4_first_aid');
        $brew = $masters->findSpell('pc2_quick_brew');
        $this->assertSame(['応急手当', 'ally_single', 'heal_mag'], [$firstAid['label'], $firstAid['target_type'], $firstAid['effect']]);
        $this->assertSame($brew['mp_cost'], $firstAid['mp_cost']);
        $this->assertSame($brew['coefficient'], $firstAid['coefficient']);
        $this->assertSame('傷ついた仲間を素早く癒す。味方単体のHPを回復。', $firstAid['description']);
    }

    public function test_miracle_rainbow_fully_heals_every_wounded_living_ally_and_costs_64_mp(): void
    {
        $state = $this->state('pc1', ['pc1_miracle_rainbow']);
        $state['units']['pc2']['hp'] = 10;
        $state['units']['pc3']['hp'] = 400;
        $state['units']['pc4']['hp'] = 0;
        $state['units']['pc4']['alive'] = false;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_miracle_rainbow');

        $units = $result['state']['units'];
        $this->assertSame(1000, $units['pc2']['hp'], '全回復');
        $this->assertSame(1000, $units['pc3']['hp'], '全回復');
        $this->assertSame(1000, $units['pc1']['hp']);
        $this->assertSame(0, $units['pc4']['hp'], '戦闘不能の味方は戻らない');
        $this->assertFalse($units['pc4']['alive']);
        $this->assertSame(200 - 64, $units['pc1']['mp']);
        $healed = collect($result['events'])->where('type', 'heal')->pluck('value', 'target')->all();
        $this->assertSame(['pc2' => 990, 'pc3' => 600], $healed, '減っていた分だけ回復');
    }

    public function test_miracle_rainbow_cannot_be_used_when_nobody_is_wounded(): void
    {
        $state = $this->state('pc1', ['pc1_miracle_rainbow']);

        $this->expectException(InvalidArgumentException::class);
        $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_miracle_rainbow');
    }

    public function test_miracle_drop_always_revives_a_fallen_ally_with_half_hp(): void
    {
        // 乱数に頼らず、確実に成功する（出目が最悪の 100 でも成功）
        $state = $this->state('pc1', ['pc1_miracle_drop']);
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;

        $result = $this->engine()->applyPlayerAction($state, 'pc1', 'spell', 'pc1_miracle_drop', 'pc2');

        $this->assertTrue($result['state']['units']['pc2']['alive']);
        $this->assertSame(500, $result['state']['units']['pc2']['hp'], '最大HPの 50%');
        $this->assertSame(200 - 40, $result['state']['units']['pc1']['mp']);
        $this->assertContains('revive', array_column($result['events'], 'type'));
        $this->assertNotContains('revive_failed', array_column($result['events'], 'type'));

        // 生きている味方には使えない
        $alive = $this->state('pc1', ['pc1_miracle_drop']);
        $this->expectException(InvalidArgumentException::class);
        $this->engine()->applyPlayerAction($alive, 'pc1', 'spell', 'pc1_miracle_drop', 'pc2');
    }

    public function test_fighting_melody_raises_the_attack_of_every_living_ally_by_one_stage(): void
    {
        $engine = $this->engine();
        $state = $this->state('pc2', ['pc2_fighting_melody']);
        $state['units']['pc4']['hp'] = 0;
        $state['units']['pc4']['alive'] = false;

        $result = $engine->applyPlayerAction($state, 'pc2', 'spell', 'pc2_fighting_melody');
        $units = $result['state']['units'];

        foreach (['pc1', 'pc2', 'pc3'] as $id) {
            $this->assertSame(1, $this->stageSum($units[$id], 'atk'), "{$id} は攻撃 +1 段階");
            $this->assertSame(0, $this->stageSum($units[$id], 'def'), "{$id} の防御は変わらない");
        }
        $this->assertSame(0, $this->stageSum($units['pc4'], 'atk'), '戦闘不能の味方にはかからない');
        $this->assertSame(200 - 38, $units['pc2']['mp']);
        $this->assertSame(0, $this->stageSum($units['enemy_1'], 'atk'), '敵にはかからない');

        // 筋力と魔力が ×1.2（1 段階）
        $method = new \ReflectionMethod(BattleEngine::class, 'effectiveStat');
        $method->setAccessible(true);
        $this->assertEqualsWithDelta(120.0, $method->invoke($engine, $units['pc1'], 'str'), 0.0001);
        $this->assertEqualsWithDelta(120.0, $method->invoke($engine, $units['pc1'], 'mag'), 0.0001);

        // 旋律は魔法ではなく特技。「〇〇の△△！」と告知する
        $announce = collect($result['events'])->firstWhere('type', 'announce');
        $this->assertSame('ガルデの闘志の旋律！', $announce['text']);
    }

    public function test_mana_focus_trades_200_hp_for_50_mp_at_no_mp_cost(): void
    {
        $state = $this->state('pc3', ['pc3_mana_focus']);
        $state['units']['pc3']['mp'] = 20;

        $result = $this->engine()->applyPlayerAction($state, 'pc3', 'spell', 'pc3_mana_focus');
        $unit = $result['state']['units']['pc3'];

        $this->assertSame(800, $unit['hp'], 'HP が 200 減る');
        $this->assertSame(70, $unit['mp'], 'MP は 消費 0 で、50 回復');
        $events = collect($result['events']);
        $this->assertSame('グレンは魔力集中を唱えた！', $events->firstWhere('type', 'announce')['text']);
        $this->assertNull($events->firstWhere('type', 'mp_spent'), 'MP 0 の魔法なので、MP の消費は出ない');
        $damage = $events->firstWhere('type', 'damage');
        $this->assertSame(['pc3', 'pc3', 200], [$damage['actor'], $damage['target'], $damage['value']]);
        $recovered = $events->firstWhere('type', 'mp_recovered');
        $this->assertSame(['pc3', 'pc3', 50], [$recovered['actor'], $recovered['target'], $recovered['value']]);
        // 他のユニットには何も起きない
        $this->assertSame(1000, $result['state']['units']['pc1']['hp']);
    }

    public function test_mana_focus_stops_at_the_max_mp_and_ignores_shields_and_wards(): void
    {
        $state = $this->state('pc3', ['pc3_mana_focus']);
        $state['units']['pc3']['mp'] = 180; // 最大 200 → 回復するのは 20 だけ
        $state['units']['pc3']['damage_shield'] = true;
        $state['units']['pc3']['life_ward'] = true;

        $result = $this->engine()->applyPlayerAction($state, 'pc3', 'spell', 'pc3_mana_focus');
        $unit = $result['state']['units']['pc3'];

        $this->assertSame(200, $unit['mp']);
        $this->assertSame(800, $unit['hp'], '魔法障壁があっても HP は減る');
        $this->assertTrue($unit['damage_shield'], '障壁は消費されない');
        $this->assertTrue($unit['life_ward'], '命の加護も消費されない');
        $this->assertSame(20, collect($result['events'])->firstWhere('type', 'mp_recovered')['value']);
    }

    public function test_mana_focus_cannot_be_used_without_enough_hp_or_at_full_mp(): void
    {
        $engine = $this->engine();

        // HP が 200 以下（唱えると倒れる / HP が 1 になる）: 唱えられない
        foreach ([200, 150, 1] as $hp) {
            $state = $this->state('pc3', ['pc3_mana_focus']);
            $state['units']['pc3']['hp'] = $hp;
            $state['units']['pc3']['mp'] = 10;
            try {
                $engine->applyPlayerAction($state, 'pc3', 'spell', 'pc3_mana_focus');
                $this->fail("HP {$hp} で唱えられてしまった");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('HPが足りません。', $e->getMessage());
            }
            $this->assertFalse($this->spellCommand($engine, $state, 'pc3', 'pc3_mana_focus')['usable'], "HP {$hp}");
        }

        // HP 201 なら唱えられて、HP は 1 残る
        $state = $this->state('pc3', ['pc3_mana_focus']);
        $state['units']['pc3']['hp'] = 201;
        $state['units']['pc3']['mp'] = 10;
        $this->assertTrue($this->spellCommand($engine, $state, 'pc3', 'pc3_mana_focus')['usable']);
        $result = $engine->applyPlayerAction($state, 'pc3', 'spell', 'pc3_mana_focus');
        $this->assertSame(1, $result['state']['units']['pc3']['hp']);
        $this->assertTrue($result['state']['units']['pc3']['alive']);

        // MP が満タン: 回復する分がないので、唱えられない（HP も減らない）
        $full = $this->state('pc3', ['pc3_mana_focus']);
        try {
            $engine->applyPlayerAction($full, 'pc3', 'spell', 'pc3_mana_focus');
            $this->fail('MP 満タンで唱えられてしまった');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('MPが満タンです。', $e->getMessage());
        }
        $this->assertFalse($this->spellCommand($engine, $full, 'pc3', 'pc3_mana_focus')['usable']);
    }

    public function test_mana_focus_is_listed_with_a_self_target_and_zero_cost(): void
    {
        $state = $this->state('pc3', ['pc3_mana_focus']);
        $state['units']['pc3']['mp'] = 0;

        $command = $this->spellCommand($this->engine(), $state, 'pc3', 'pc3_mana_focus');

        $this->assertSame(['self', 0, true, true], [$command['target_type'], $command['mp_cost'], $command['affordable'], $command['usable']]);
    }

    public function test_first_aid_heals_exactly_like_quick_brew_for_the_same_mag_and_costs_the_same(): void
    {
        $engine = $this->engine();

        $aid = $this->state('pc4', ['pc4_first_aid']);
        $aid['units']['pc1']['hp'] = 100;
        $aidResult = $engine->applyPlayerAction($aid, 'pc4', 'spell', 'pc4_first_aid', 'pc1');

        $brew = $this->state('pc2', ['pc2_quick_brew']);
        $brew['units']['pc1']['hp'] = 100;
        $brew['units']['pc2']['mag'] = $aid['units']['pc4']['mag'];
        $brewResult = $engine->applyPlayerAction($brew, 'pc2', 'spell', 'pc2_quick_brew', 'pc1');

        $this->assertSame(250, collect($aidResult['events'])->firstWhere('type', 'heal')['value'], '魔力 100 × 2.5');
        $this->assertSame(
            collect($brewResult['events'])->firstWhere('type', 'heal')['value'],
            collect($aidResult['events'])->firstWhere('type', 'heal')['value'],
        );
        $this->assertSame(200 - 14, $aidResult['state']['units']['pc4']['mp']);
        $this->assertSame($brewResult['state']['units']['pc2']['mp'], $aidResult['state']['units']['pc4']['mp']);
        $this->assertSame('シエナの応急手当！', collect($aidResult['events'])->firstWhere('type', 'announce')['text']);
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
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function spellCommand(BattleEngine $engine, array $state, string $actorId, string $spellId): array
    {
        $state['current_actor'] = $actorId;
        $commands = $engine->publicState($state)['commands'];

        return collect($commands['spells'])->firstWhere('id', $spellId);
    }

    /**
     * @param  list<string>  $spells
     * @return array<string, mixed>
     */
    private function state(string $actorId, array $spells): array
    {
        $names = ['pc1' => 'サフィリア', 'pc2' => 'ガルデ', 'pc3' => 'グレン', 'pc4' => 'シエナ'];
        $ally = static fn (string $id, int $spd) => [
            'id' => $id,
            'name' => $names[$id],
            'sprite' => $id,
            'master_code' => $id,
            'side' => 'ally',
            'level' => 30,
            'hp' => 1000,
            'max_hp' => 1000,
            'mp' => 200,
            'max_mp' => 200,
            'str' => 100,
            'mag' => 100,
            'def' => 50,
            'pdef' => 50,
            'mdef' => 50,
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
        $enemy = [
            'id' => 'enemy_1',
            'name' => 'enemy_1',
            'sprite' => 'bat',
            'master_code' => 'bat',
            'side' => 'enemy',
            'hp' => 5000,
            'max_hp' => 5000,
            'mp' => 0,
            'max_mp' => 0,
            'str' => 20,
            'mag' => 10,
            'def' => 5,
            'pdef' => 5,
            'mdef' => 5,
            'spd' => 1,
            'crit_rate' => 0,
            'evasion_rate' => 0,
            'damage_shield' => false,
            'alive' => true,
            'buffs' => [],
            'stages' => [],
            'statuses' => [],
            'spells' => [],
        ];

        $units = [
            'pc1' => $ally('pc1', 100),
            'pc2' => $ally('pc2', 90),
            'pc3' => $ally('pc3', 80),
            'pc4' => $ally('pc4', 70),
            'enemy_1' => $enemy,
        ];
        $units[$actorId]['spells'] = $spells;

        return [
            'status' => 'active',
            'current_actor' => $actorId,
            'awaiting_input' => true,
            'turn_order' => array_keys($units),
            'turn_index' => 0,
            'units' => $units,
            'meta' => [],
            'log' => [],
        ];
    }
}
