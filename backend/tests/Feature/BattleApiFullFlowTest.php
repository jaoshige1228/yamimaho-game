<?php

namespace Tests\Feature;

use App\Models\BattleState;
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
