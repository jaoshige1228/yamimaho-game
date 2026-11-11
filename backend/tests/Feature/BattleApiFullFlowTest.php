<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\UserCharacter;
use App\Services\Battle\BattleSimulator;
use App\Services\Battle\Strategies\AggressivePunchStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP API のみで戦闘の開始〜ターン進行〜勝敗までを再現する。
 * フロントエンドなしでバックエンド単体の結合を保証する。
 */
class BattleApiFullFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_full_battle_via_api_only(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $battleId = $start->json('battle_id');
        $state = $start->json('state');

        $this->assertSame('active', $state['status']);
        $this->assertStringStartsWith('pc', $state['current_actor']);

        $maxSteps = 400;
        $steps = 0;
        $seenActors = [];

        while ($state['status'] === 'active' && $steps < $maxSteps) {
            $this->assertTrue($state['awaiting_input'] ?? false, "ステップ {$steps}: 入力待ちであること");

            $actor = $state['current_actor'];
            $seenActors[] = $actor;

            $enemy = collect($state['units'])
                ->where('side', 'enemy')
                ->where('alive', true)
                ->sortBy('hp')
                ->first();

            if ($enemy === null) {
                break;
            }

            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'punch',
                'target_id' => $enemy['id'],
            ]);

            $response->assertOk();
            $state = $response->json('state');
            $steps++;
        }

        $this->assertContains($state['status'], ['victory', 'defeat']);
        $this->assertGreaterThan(4, $steps, '数ターン以上進んでから終了すること');
        $this->assertContains('pc1', $seenActors);
        $this->assertContains('pc4', $seenActors);
    }

    public function test_api_rejects_invalid_target(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $battleId = $start->json('battle_id');

        $this->postJson("/api/battles/{$battleId}/actions", [
            'action' => 'punch',
            'target_id' => 'enemy_99',
        ])->assertStatus(422);
    }

    public function test_ally_who_fell_during_the_battle_stays_down_after_victory(): void
    {
        $start = $this->postJson('/api/battles/demo')->assertOk();
        $battleId = $start->json('battle_id');

        // ガルデ（pc2）が戦闘中に倒れ（即死など）、敵は残り1体・HP1 の状態にする
        $record = BattleState::query()->findOrFail($battleId);
        $state = $record->state;
        $state['units']['pc2']['hp'] = 0;
        $state['units']['pc2']['alive'] = false;
        $survivor = null;
        foreach ($state['units'] as $id => $unit) {
            if (($unit['side'] ?? '') !== 'enemy') {
                continue;
            }
            if ($survivor === null) {
                $survivor = $id;
                $state['units'][$id]['hp'] = 1;

                continue;
            }
            $state['units'][$id]['hp'] = 0;
            $state['units'][$id]['alive'] = false;
        }
        $state['current_actor'] = 'pc1';
        $state['awaiting_input'] = true;
        $record->state = $state;
        $record->save();

        $pc2 = UserCharacter::query()->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc2'))->firstOrFail();
        $this->assertGreaterThan(0, (int) $pc2->hp, '戦闘前は元気');

        // 当たるまでパンチ（外れても数ターンで倒せる）。倒れた pc2 の手番は飛ばされる
        $status = 'active';
        $actor = 'pc1';
        for ($i = 0; $i < 30 && $status === 'active'; $i++) {
            $this->assertNotSame('pc2', $actor, '倒れた味方は行動しない');
            $response = $this->postJson("/api/battles/{$battleId}/actions", [
                'action' => 'punch',
                'target_id' => $survivor,
            ])->assertOk();
            $status = $response->json('state.status');
            $actor = $response->json('state.current_actor');
        }

        $this->assertSame('victory', $status);
        $this->assertSame(0, (int) $pc2->fresh()->hp, '倒れた味方は、戦闘が終わっても HP 0 のまま');
    }

    public function test_api_rejects_action_after_battle_ends(): void
    {
        $run = (new BattleSimulator)->runUntilEnd(new AggressivePunchStrategy);
        $this->assertContains($run['result'], ['victory', 'defeat']);

        $record = BattleState::query()->create([
            'id' => (string) Str::uuid(),
            'state' => $run['final_state'],
            'status' => $run['result'],
        ]);

        $this->postJson("/api/battles/{$record->id}/actions", [
            'action' => 'punch',
            'target_id' => 'enemy_1',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '戦闘は終了しています。']);
    }
}
