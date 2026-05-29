<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleOrchestrator;
use Tests\TestCase;

class BattleOrchestratorTest extends TestCase
{
    private function orchestrator(): BattleOrchestrator
    {
        $picker = static function (array $units): ?string {
            foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $id) {
                if (($units[$id]['alive'] ?? false) === true) {
                    return $id;
                }
            }

            return null;
        };

        return new BattleOrchestrator(
            engine: new BattleEngine(enemyTargetPicker: $picker),
        );
    }

    /** @return list<string> */
    private function allyTurnOrder(array $state): array
    {
        return array_values(array_filter(
            $state['turn_order'],
            static fn (string $id): bool => str_starts_with($id, 'pc'),
        ));
    }

    public function test_battle_starts_on_fastest_ally_turn(): void
    {
        $result = $this->orchestrator()->createBattle();
        $allies = $this->allyTurnOrder($result['state']);

        $this->assertSame('active', $result['state']['status']);
        $this->assertSame($allies[0], $result['state']['current_actor']);
        $this->assertTrue($result['state']['awaiting_input']);
    }

    public function test_player_action_advances_turn_order(): void
    {
        $orch = $this->orchestrator();
        $state = $orch->createBattle()['state'];
        $allies = $this->allyTurnOrder($state);

        foreach ($allies as $index => $allyId) {
            $this->assertSame($allyId, $state['current_actor']);
            $state = $orch->submitPlayerAction(
                $state,
                $index === 1 ? 'defend' : 'punch',
                null,
                'enemy_1',
            )['state'];
        }

        $this->assertSame($allies[0], $state['current_actor']);
    }

    public function test_enemy_phase_runs_after_last_ally_without_player_input(): void
    {
        $orch = $this->orchestrator();
        $state = $orch->createBattle()['state'];
        $allies = $this->allyTurnOrder($state);

        foreach (array_slice($allies, 0, -1) as $allyId) {
            $this->assertSame($allyId, $state['current_actor']);
            $state = $orch->submitPlayerAction($state, 'punch', null, 'enemy_1')['state'];
        }

        $this->assertSame($allies[array_key_last($allies)], $state['current_actor']);
        $roundEnd = $orch->submitPlayerAction($state, 'punch', null, 'enemy_1');

        $turnStarts = array_values(array_filter(
            $roundEnd['events'],
            fn (array $e): bool => ($e['type'] ?? '') === 'turn_start' && str_starts_with((string) ($e['actor'] ?? ''), 'enemy'),
        ));

        $this->assertGreaterThanOrEqual(1, count($turnStarts));
        $this->assertSame($allies[0], $roundEnd['state']['current_actor']);
    }

    public function test_defend_sets_guard_flag_until_next_turn(): void
    {
        $orch = $this->orchestrator();
        $state = $orch->createBattle()['state'];
        $firstAlly = $this->allyTurnOrder($state)[0];

        $result = $orch->submitPlayerAction($state, 'defend');

        $this->assertTrue($result['state']['units'][$firstAlly]['defending']);
        $this->assertContains('defend', array_column($result['events'], 'type'));
    }
}
