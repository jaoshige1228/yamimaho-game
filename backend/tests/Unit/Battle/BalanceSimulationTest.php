<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BalanceScenario;
use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\BattleSimulator;
use App\Services\Battle\Strategies\PrimarySpellStrategy;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public function test_balance_scenarios_meet_ttk_targets_with_primary_spells(): void
    {
        $simulator = $this->deterministicSimulator();
        $rows = $simulator->runScenarioMatrix(new PrimarySpellStrategy);

        foreach ($rows as $row) {
            $this->assertSame('victory', $row['result'], $row['label']);
            $this->assertTrue(
                $row['within_target'],
                "{$row['label']}: {$row['player_turns']} turns (target {$row['min_player_turns']}-{$row['max_player_turns']})",
            );
        }
    }

    public function test_simulator_outputs_ttk_table(): void
    {
        $simulator = $this->deterministicSimulator();
        $table = $simulator->formatTtkTable(
            $simulator->runScenarioMatrix(new PrimarySpellStrategy, [
                BalanceScenario::defaults()[1],
            ]),
        );

        $this->assertSame('1F boss Lv8 advanced', $table[0]['label']);
        $this->assertSame('8-12', $table[0]['target']);
        $this->assertTrue($table[0]['ok']);
    }

    private function deterministicSimulator(): BattleSimulator
    {
        $picker = static fn (array $units): ?string => ($units['pc1']['alive'] ?? false) ? 'pc1' : 'pc2';
        $engine = new BattleEngine(
            factory: new BattleFactory,
            damageRoll: static fn (): float => 1.0,
            hitRoll: static fn (): int => 100,
            critRoll: static fn (): int => 100,
            enemyTargetPicker: $picker,
        );

        return new BattleSimulator(new BattleOrchestrator(engine: $engine));
    }
}
