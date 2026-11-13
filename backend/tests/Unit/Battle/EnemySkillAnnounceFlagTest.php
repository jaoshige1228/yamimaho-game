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
 * 敵の通常攻撃以外の技・魔法の告知（「〇〇は△△を唱えた！」「〇〇の△△！」）には skill = true が付く。
 * 画面は、これが付いた告知を長めに残し、効果が出るまでの間も少し長くする。通常攻撃と、味方の告知には付かない。
 */
class EnemySkillAnnounceFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public function test_the_normal_attack_announce_has_no_skill_flag(): void
    {
        $announce = $this->firstAnnounce('mahou', roll: 0); // 邪教徒: 攻撃

        $this->assertSame('邪教徒の攻撃！', $announce['text']);
        $this->assertArrayNotHasKey('skill', $announce);
    }

    public function test_enemy_spells_and_skills_have_the_skill_flag(): void
    {
        // 邪教徒の魔法（闇の加護・闇の呪縛）
        $this->assertTrue($this->firstAnnounce('mahou', roll: 25)['skill']);
        $this->assertTrue($this->firstAnnounce('mahou', roll: 50)['skill']);
        // エルドラゴンの物理の技（ドラゴンクロウ・ドラゴンブレス）。通常攻撃は同じくフラグなし
        $claw = $this->firstAnnounce('dragon', roll: 30);
        $this->assertSame('エルドラゴンのドラゴンクロウ！', $claw['text']);
        $this->assertTrue($claw['skill']);
        $this->assertTrue($this->firstAnnounce('dragon', roll: 65)['skill']);
        $this->assertArrayNotHasKey('skill', $this->firstAnnounce('dragon', roll: 0));
    }

    public function test_the_players_announces_never_have_the_skill_flag(): void
    {
        $state = $this->state('mahou');
        $engine = $this->engine(0);
        $state = $engine->runEnemyTurnsUntilPlayer($state)['state'];
        $result = $engine->applyPlayerAction($state, $state['current_actor'], 'punch', null, 'enemy_1');

        $announces = array_values(array_filter($result['events'], fn (array $e) => $e['type'] === 'announce' && str_starts_with((string) $e['actor'], 'pc')));
        $this->assertNotEmpty($announces);
        foreach ($announces as $announce) {
            $this->assertArrayNotHasKey('skill', $announce);
        }
    }

    // ---- 道具 ----

    /**
     * 抽選の値を固定して、その敵が最初に出す告知を返す。
     *
     * @return array<string, mixed>
     */
    private function firstAnnounce(string $enemyCode, int $roll): array
    {
        $result = $this->engine($roll)->runEnemyTurnsUntilPlayer($this->state($enemyCode));

        foreach ($result['events'] as $event) {
            if ($event['type'] === 'announce' && str_starts_with((string) $event['actor'], 'enemy')) {
                return $event;
            }
        }

        $this->fail('敵の告知が出なかった');
    }

    private function engine(int $roll): BattleEngine
    {
        return new BattleEngine(
            factory: new BattleFactory,
            enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => $roll),
            enemyTargetPicker: static fn (array $units): string => 'pc1',
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $enemyCode): array
    {
        $party = [];
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $code) {
            // レベル 1 のパーティー（素早さが低く、敵が先に動く）。HP は大きくして、倒れないようにする
            $party[$code] = ['master_code' => $code, 'level' => 1];
        }
        $master = (new MasterDataProvider)->findEnemy($enemyCode, 2);
        $enemies = [['slot' => 'enemy_1', 'master_code' => $enemyCode, 'floor' => 2, 'name' => $master['name'], 'sprite' => $master['sprite']]];

        $state = (new BattleOrchestrator)->factory()->createScenario($party, $enemies, 'test');
        $state['units']['enemy_1']['hp'] = $state['units']['enemy_1']['max_hp'] = 999999;
        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = $state['units'][$id]['max_hp'] = 999999;
        }

        return $state;
    }
}
