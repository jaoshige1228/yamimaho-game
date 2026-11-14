<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 戦闘の行動 API の守り（2026-11-15 の公開前点検）。
 * - 存在しない相手を魔法で狙っても、500 ではなく 422 になる
 * - 勝利の行動をもう一度送っても、報酬は 1 回しか入らない（戦闘の行をロックして、状態の確認から保存までを 1 つのトランザクションで行う）
 */
class BattleActionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_a_spell_aimed_at_a_missing_unit_is_a_422_not_a_server_error(): void
    {
        $battleId = $this->startBattleWaitingFor('pc1', ['pc1_water_blade', 'pc1_heal_water']);

        $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'spell',
            'spell_id' => 'pc1_water_blade',
            'target_id' => 'enemy_99',
        ])->assertStatus(422);

        $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'spell',
            'spell_id' => 'pc1_water_blade',
            'target_id' => 'pc2',
        ])->assertStatus(422);

        // 失敗した行動では、MP も減らない（戦闘の状態は、行動の前のまま）
        $mp = BattleState::query()->findOrFail($battleId)->state['units']['pc1']['mp'];
        $this->assertSame(300, $mp);
    }

    public function test_the_winning_action_pays_the_reward_only_once_even_if_it_is_sent_twice(): void
    {
        $user = $this->demoUser();
        $battleId = $this->startBattleWaitingFor('pc1', []);

        $record = BattleState::query()->findOrFail($battleId);
        $state = $record->state;
        // 敵はあと 1 体、HP 1。こぶしで倒せば勝ち
        foreach (['enemy_2', 'enemy_3'] as $id) {
            $state['units'][$id]['hp'] = 0;
            $state['units'][$id]['alive'] = false;
            $state['units'][$id]['reward_counted'] = true;
        }
        $state['units']['enemy_1']['hp'] = 1;
        $state['units']['enemy_1']['def'] = 0;
        $state['units']['enemy_1']['pdef'] = 0;
        $state['units']['enemy_1']['evasion_rate'] = 0;
        $state['units']['enemy_1']['gold_reward'] = 50;
        $record->state = $state;
        $record->save();

        $goldBefore = (int) $user->fresh()->gold;
        $payload = ['action' => 'punch', 'target_id' => 'enemy_1'];

        $first = $this->postJson("/api/battles/{$battleId}/actions", $payload)->assertOk();
        $first->assertJsonPath('state.status', 'victory');
        $this->assertSame($goldBefore + 50, (int) $user->fresh()->gold);

        // 同じ行動の再送（通信のやり直し・別のタブ）。戦闘は終わっているので受け付けず、報酬も増えない
        $this->postJson("/api/battles/{$battleId}/actions", $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', '戦闘は終了しています。');
        $this->assertSame($goldBefore + 50, (int) $user->fresh()->gold);
    }

    /**
     * 指定の味方の番で、魔法を覚えた状態の戦闘を作る（保存された戦闘の状態を直接書き換える）。
     *
     * @param  list<string>  $spells
     */
    private function startBattleWaitingFor(string $actor, array $spells): string
    {
        $battleId = (string) $this->postJson('/api/battles/demo')->assertOk()->json('battle_id');

        $record = BattleState::query()->findOrFail($battleId);
        $state = $record->state;
        $state['current_actor'] = $actor;
        $state['awaiting_input'] = true;
        $state['units'][$actor]['spells'] = $spells;
        $state['units'][$actor]['mp'] = 300;
        $state['units'][$actor]['max_mp'] = 300;
        $record->state = $state;
        $record->save();

        return $battleId;
    }

    private function demoUser(): User
    {
        $this->postJson('/api/battles/demo')->assertOk();

        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
