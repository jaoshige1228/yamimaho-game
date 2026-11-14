<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\EnemySkillPicker;
use App\Services\MasterData\MasterDataProvider;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 特技（`cast_style = skill`。演奏・調合・応急手当など）の告知は、魔法の告知と同じ形で出る（2026-11-14）。
 *
 * 画面は、告知に `spell_effect` / `spell_element` が付いていれば「魔法を唱えた」ときと同じ演出（フラッシュ・効果音・間）を出す
 * （告知の文章の「唱え」には頼らない。特技は「〇〇の△△！」という文章で、「唱え」を含まない）。
 * この約束（特技の告知にも、魔法と同じ効果の項目が付く）をここで固定する。
 */
class SkillCastAnnounceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public function test_the_players_skills_announce_with_the_effect_of_the_spell_just_like_a_spell(): void
    {
        $masters = new MasterDataProvider;

        // 味方の特技（ガルデの闘志の旋律・迅速調合 / シエナの応急手当）
        foreach ([['pc2', 'pc2_fighting_melody', null], ['pc2', 'pc2_quick_brew', 'pc1'], ['pc4', 'pc4_first_aid', 'pc1']] as [$actor, $code, $target]) {
            $spell = $masters->findSpell($code);
            $this->assertSame('skill', $spell['cast_style'], "{$code} は特技");

            $announce = $this->announceOf($this->cast($actor, $code, $target), $actor);

            $this->assertStringContainsString('の'.$spell['label'].'！', $announce['text']);
            $this->assertStringNotContainsString('唱え', $announce['text'], '特技の告知は「唱えた」ではない');
            $this->assertSame($spell['effect'], $announce['spell_effect'], "{$code} の告知に、魔法と同じ spell_effect が付く");
        }

        // 比較: 普通の魔法（サフィリアの癒しの水）の告知にも、同じ項目が付く
        $spell = $masters->findSpell('pc1_heal_water');
        $this->assertNotSame('skill', $spell['cast_style'] ?? '');
        $announce = $this->announceOf($this->cast('pc1', 'pc1_heal_water', 'pc2'), 'pc1');
        $this->assertStringContainsString('唱えた', $announce['text']);
        $this->assertSame($spell['effect'], $announce['spell_effect']);
    }

    public function test_the_rift_mages_skills_announce_with_the_effect_of_the_spell_just_like_a_spell(): void
    {
        $state = $this->riftState();
        $result = $this->engine()->runEnemyTurnsUntilPlayer($state);

        $byActor = [];
        foreach ($result['events'] as $event) {
            if ($event['type'] === 'announce') {
                $byActor[$event['actor']][] = $event;
            }
        }

        // 奏楽（enemy_3）の 1 ターン目は、特技の「怨恨の旋律」（敵全体の防御ダウン）
        $skill = $byActor['enemy_3'][0];
        $this->assertSame('奏楽の魔法使いの怨恨の旋律！', $skill['text']);
        $this->assertTrue($skill['skill']);
        $this->assertSame('debuff_def', $skill['spell_effect']);

        // 比較: 慈愛（enemy_1）の 1 ターン目は、普通の魔法の「命の加護」。告知の項目の種類は、特技と同じ
        $spell = $byActor['enemy_1'][0];
        $this->assertSame('慈愛の魔法使いは命の加護を唱えた！', $spell['text']);
        $this->assertTrue($spell['skill']);
        $this->assertSame('life_ward', $spell['spell_effect']);
        $this->assertSame(array_keys($spell), array_keys($skill), '特技の告知は、魔法の告知と同じ項目で出る');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cast(string $actorId, string $spellCode, ?string $targetId): array
    {
        $state = $this->partyState($actorId, [$spellCode]);

        return $this->engine()->applyPlayerAction($state, $actorId, 'spell', $spellCode, $targetId)['events'];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function announceOf(array $events, string $actorId): array
    {
        $announces = array_values(array_filter($events, fn (array $e) => $e['type'] === 'announce' && $e['actor'] === $actorId));
        $this->assertCount(1, $announces);

        return $announces[0];
    }

    private function engine(): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => random_int(0, 99999)),
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * 4 人の魔法使いと、Lv35 のパーティー（敵が死なないように HP を大きくしておく）。
     *
     * @return array<string, mixed>
     */
    private function riftState(): array
    {
        $party = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $code) {
            $party[$code] = ['master_code' => $code, 'level' => 35, 'weapon' => 'staff_pro', 'armor' => 'robe_tuyosugi'];
        }
        $masters = new MasterDataProvider;
        $enemies = [];
        foreach (['rift_mercy', 'rift_legend', 'rift_music', 'rift_guide'] as $i => $code) {
            $master = $masters->findEnemy($code, 3);
            $enemies[] = ['slot' => 'enemy_'.($i + 1), 'master_code' => $code, 'floor' => 3, 'name' => $master['name'], 'sprite' => $master['sprite']];
        }
        $state = (new BattleOrchestrator)->factory()->createScenario($party, $enemies, 'test');
        foreach ($state['units'] as $id => $unit) {
            $state['units'][$id]['hp'] = $state['units'][$id]['max_hp'] = 9_999_999;
        }

        return $state;
    }

    /**
     * 指定した味方が、指定した魔法を使える状態（回復の対象になるよう、ほかの味方は少し傷ついている）。
     *
     * @param  list<string>  $spells
     * @return array<string, mixed>
     */
    private function partyState(string $actorId, array $spells): array
    {
        $state = $this->riftState();
        // 先頭の敵の行動は済ませ、味方の入力待ちにする
        $state = $this->engine()->runEnemyTurnsUntilPlayer($state)['state'];
        $state['current_actor'] = $actorId;
        $state['awaiting_input'] = true;
        $state['status'] = 'active';
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = 5000;
            $state['units'][$id]['max_hp'] = 9000;
            $state['units'][$id]['mp'] = $state['units'][$id]['max_mp'] = 999;
        }
        $state['units'][$actorId]['spells'] = $spells;

        return $state;
    }
}
