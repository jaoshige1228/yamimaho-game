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

    public function test_kick_emits_announce_event(): void
    {
        $orch = $this->orchestrator();
        $state = $orch->createBattle()['state'];
        $firstAlly = $this->allyTurnOrder($state)[0];

        $result = $orch->submitPlayerAction($state, 'kick', null, 'enemy_1');

        $announces = array_values(array_filter(
            $result['events'],
            fn (array $e): bool => ($e['type'] ?? '') === 'announce',
        ));

        $this->assertNotEmpty($announces);
        $this->assertStringContainsString('キック', $announces[0]['text'] ?? '');
    }
}
