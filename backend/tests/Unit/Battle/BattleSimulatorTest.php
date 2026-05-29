<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\BattleSimulator;
use App\Services\Battle\Strategies\AggressivePunchStrategy;
use Tests\TestCase;

class BattleSimulatorTest extends TestCase
{
    public function test_simulation_reaches_victory_or_defeat(): void
    {
        $picker = static fn (array $units): ?string => ($units['pc1']['alive'] ?? false) ? 'pc1' : 'pc2';
        $simulator = new BattleSimulator(
            new BattleOrchestrator(engine: new BattleEngine(enemyTargetPicker: $picker)),
        );

        $run = $simulator->runUntilEnd(new AggressivePunchStrategy);

        $this->assertContains($run['result'], ['victory', 'defeat']);
        $this->assertGreaterThan(0, $run['player_turns']);
        $this->assertNull($run['final_state']['current_actor']);
    }

    public function test_aggressive_strategy_usually_wins_demo_battle(): void
    {
        $picker = static fn (array $units): ?string => ($units['pc4']['alive'] ?? false) ? 'pc4' : 'pc1';
        $simulator = new BattleSimulator(
            new BattleOrchestrator(engine: new BattleEngine(enemyTargetPicker: $picker)),
        );

        $wins = 0;
        foreach ($simulator->runMany(5, new AggressivePunchStrategy) as $run) {
            if ($run['result'] === 'victory') {
                $wins++;
            }
        }

        $this->assertGreaterThanOrEqual(3, $wins, 'デモ戦闘ではこぶし連打で多くが勝利する想定');
    }
}
