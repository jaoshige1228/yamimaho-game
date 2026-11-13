<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Growth\LevelGrowthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 戦闘不能のまま戦闘を終えた味方にも経験値が入る。ただし、レベルアップの最大HP・MPの増加で復活はしない。
 * （戦闘の勝利 → 経験値 → レベルアップ → 保存、までを本物の流れで通す）
 */
class DownedAllyExpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_a_downed_ally_gets_exp_and_levels_up_but_stays_down_after_a_real_victory(): void
    {
        $user = $this->demoUser();
        $growth = new LevelGrowthService;
        $dead = $this->character($user, 'pc2');
        $alive = $this->character($user, 'pc1');
        $maxHpBefore = $growth->statsForCharacter($dead)['hp'];

        // 倒れる味方は、次のレベルまであと 5。コーモリ（経験値 20）を倒すとレベルアップする
        $dead->exp = $growth->cumulativeExpForLevel(2) - 5;
        $dead->save();
        $deadExpBefore = (int) $dead->exp;

        $orchestrator = new BattleOrchestrator(
            engine: new BattleEngine(
                factory: new BattleFactory,
                damageRoll: static fn (): float => 1.0,
                hitRoll: static fn (): int => 100,
                critRoll: static fn (): int => 100,
            ),
        );
        $state = $orchestrator->createBattleForUser($user, [[
            'slot' => 'enemy_1',
            'master_code' => 'bat',
            'floor' => 1,
            'name' => 'コーモリ',
            'sprite' => 'bat',
        ]], ['source' => 'test'])['state'];

        // ガルデは戦闘中に倒れた。サフィリアのこぶしで、残りHP 1 のコーモリを倒して勝つ
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;
        $state['units']['enemy_1']['hp'] = 1;
        $state['status'] = 'active';
        $state['current_actor'] = 'pc1';
        $state['awaiting_input'] = true;

        $result = $orchestrator->submitPlayerAction($state, 'punch', null, 'enemy_1', $user);

        $this->assertSame('victory', $result['state']['status']);

        $expBySlot = [];
        foreach ($result['events'] as $event) {
            if (($event['type'] ?? '') === 'exp_gained') {
                $expBySlot[$event['slot_id']] = $event['amount'];
            }
        }
        $this->assertSame(['pc1' => 20, 'pc2' => 20, 'pc3' => 20, 'pc4' => 20], $expBySlot, '倒れた味方にも経験値が入る');
        $levelUpSlots = array_column(
            array_filter($result['events'], fn (array $e): bool => ($e['type'] ?? '') === 'level_up'),
            'slot_id',
        );
        $this->assertContains('pc2', $levelUpSlots, '倒れた味方もレベルアップする');

        $dead->refresh();
        $this->assertSame($deadExpBefore + 20, (int) $dead->exp);
        $this->assertSame(2, (int) $dead->level);
        $this->assertGreaterThan($maxHpBefore, $growth->statsForCharacter($dead)['hp'], '最大HPは増えている');
        $this->assertSame(0, (int) $dead->hp, '最大HPが増えても、倒れた味方は復活しない');

        // 生きている味方は、今までどおり HP が残る
        $alive->refresh();
        $this->assertGreaterThan(0, (int) $alive->hp);
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }

    private function character(User $user, string $code): UserCharacter
    {
        return UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', $code))
            ->firstOrFail();
    }
}
