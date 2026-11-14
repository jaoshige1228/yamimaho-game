<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Player\PartyGoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 倒した敵のゴールドは、勝ったときだけ入る。敗北では、戦闘中に敵を倒していても入らない。
 */
class DefeatGoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_gold_of_an_enemy_defeated_before_losing_is_not_paid(): void
    {
        $user = $this->demoUser();
        $goldBefore = app(PartyGoldService::class)->getGold($user);

        $orchestrator = $this->orchestrator();
        $state = $this->twoBats($orchestrator, $user);
        // コーモリ（ゴールド 20）の 1 体を倒したあと、残りの 1 体に全滅させられる状況にする:
        // 生きているのは HP 1 のサフィリアだけ。こぶしで enemy_1 を倒しても、筋力 9999 の enemy_2 に倒される
        foreach (['pc2', 'pc3', 'pc4'] as $id) {
            $state['units'][$id]['hp'] = 0;
            $state['units'][$id]['alive'] = false;
        }
        $state['units']['pc1']['hp'] = 1;
        $state['units']['enemy_1']['hp'] = 1;
        $state['units']['enemy_2']['str'] = 9999;

        $result = $orchestrator->submitPlayerAction($state, 'punch', null, 'enemy_1', $user);

        $this->assertSame('defeat', $result['state']['status']);
        $types = array_column($result['events'], 'type');
        $this->assertContains('gold_gained', $types, '戦闘中は、敵を倒した時点でゴールドが数えられる（画面の演出用）');
        $this->assertSame(20, $result['state']['meta']['gold_earned']);
        $this->assertNotContains('gold_reward_applied', $types, '敗北ではゴールドを受け取らない');
        $this->assertSame($goldBefore, app(PartyGoldService::class)->getGold($user->fresh()));
    }

    public function test_gold_is_still_paid_when_the_battle_is_won(): void
    {
        $user = $this->demoUser();
        $goldBefore = app(PartyGoldService::class)->getGold($user);

        $orchestrator = $this->orchestrator();
        $state = $this->twoBats($orchestrator, $user);
        $state['units']['enemy_1']['hp'] = 1;
        $state['units']['enemy_2']['hp'] = 1;
        // 2 体のコーモリを、サフィリアのこぶし 1 回ずつで倒す（2 回目の前に敵が動いても、HP 1 のまま）
        $result = $orchestrator->submitPlayerAction($state, 'punch', null, 'enemy_1', $user);
        $this->assertSame('active', $result['state']['status']);
        $state = $result['state'];
        $state['current_actor'] = 'pc1';
        $state['awaiting_input'] = true;

        $result = $orchestrator->submitPlayerAction($state, 'punch', null, 'enemy_2', $user);

        $this->assertSame('victory', $result['state']['status']);
        $applied = collect($result['events'])->firstWhere('type', 'gold_reward_applied');
        $this->assertNotNull($applied);
        $this->assertSame(40, $applied['amount']);
        $this->assertSame($goldBefore + 40, app(PartyGoldService::class)->getGold($user->fresh()));
    }

    private function orchestrator(): BattleOrchestrator
    {
        return new BattleOrchestrator(
            engine: new BattleEngine(
                factory: new BattleFactory,
                damageRoll: static fn (): float => 1.0,
                hitRoll: static fn (): int => 100,
                critRoll: static fn (): int => 100,
            ),
        );
    }

    /**
     * コーモリ 2 体（倒すと 1 体ごとにゴールド 20）の戦闘を、サフィリアの手番にして返す。
     *
     * @return array<string, mixed>
     */
    private function twoBats(BattleOrchestrator $orchestrator, User $user): array
    {
        $enemies = [];
        foreach ([1, 2] as $i) {
            $enemies[] = ['slot' => "enemy_{$i}", 'master_code' => 'bat', 'floor' => 1, 'name' => "コーモリ{$i}", 'sprite' => 'bat'];
        }
        $state = $orchestrator->createBattleForUser($user, $enemies, ['source' => 'test'])['state'];
        $state['status'] = 'active';
        $state['current_actor'] = 'pc1';
        $state['awaiting_input'] = true;

        return $state;
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
