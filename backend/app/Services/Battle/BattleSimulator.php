<?php

namespace App\Services\Battle;

/**
 * バックエンドのみで戦闘を最後まで自動進行する（バランス検証用）。
 */
class BattleSimulator
{
    public function __construct(
        private readonly BattleOrchestrator $orchestrator = new BattleOrchestrator,
    ) {}

    /**
     * @return array{
     *   result: string,
     *   player_turns: int,
     *   total_steps: int,
     *   final_state: array<string, mixed>
     * }
     */
    public function runUntilEnd(
        BattleActionStrategy $strategy,
        int $maxPlayerTurns = 500,
        ?array $initialState = null,
    ): array {
        if ($initialState === null) {
            $bootstrap = $this->orchestrator->createBattle();
            $state = $bootstrap['state'];
        } else {
            $state = $initialState;
        }

        $playerTurns = 0;
        $totalSteps = 0;

        while ($state['status'] === 'active' && $playerTurns < $maxPlayerTurns) {
            if (! ($state['awaiting_input'] ?? false)) {
                break;
            }

            $choice = $strategy->choose($state);
            $step = $this->orchestrator->submitPlayerAction(
                $state,
                $choice['action'],
                $choice['spell_id'] ?? null,
                $choice['target_id'] ?? null,
            );

            $state = $step['state'];
            $playerTurns++;
            $totalSteps++;
        }

        return [
            'result' => $state['status'],
            'player_turns' => $playerTurns,
            'total_steps' => $totalSteps,
            'final_state' => $state,
        ];
    }

    /**
     * @return list<array{result: string, player_turns: int}>
     */
    public function runMany(int $count, BattleActionStrategy $strategy): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $run = $this->runUntilEnd($strategy);
            $results[] = [
                'result' => $run['result'],
                'player_turns' => $run['player_turns'],
            ];
        }

        return $results;
    }

    /**
     * @return array{
     *   label: string,
     *   result: string,
     *   player_turns: int,
     *   min_player_turns: int|null,
     *   max_player_turns: int|null,
     *   within_target: bool|null
     * }
     */
    public function runScenario(
        BalanceScenario $scenario,
        BattleActionStrategy $strategy,
    ): array {
        $bootstrap = $this->orchestrator->createScenarioBattle(
            $scenario->party,
            $scenario->enemies,
            $scenario->label,
        );
        $run = $this->runUntilEnd($strategy, initialState: $bootstrap['state']);

        $withinTarget = null;
        if ($scenario->minPlayerTurns !== null && $scenario->maxPlayerTurns !== null) {
            $withinTarget = $run['result'] === 'victory'
                && $run['player_turns'] >= $scenario->minPlayerTurns
                && $run['player_turns'] <= $scenario->maxPlayerTurns;
        }

        return [
            'label' => $scenario->label,
            'result' => $run['result'],
            'player_turns' => $run['player_turns'],
            'min_player_turns' => $scenario->minPlayerTurns,
            'max_player_turns' => $scenario->maxPlayerTurns,
            'within_target' => $withinTarget,
        ];
    }

    /**
     * @return list<array{
     *   label: string,
     *   result: string,
     *   player_turns: int,
     *   min_player_turns: int|null,
     *   max_player_turns: int|null,
     *   within_target: bool|null
     * }>
     */
    public function runScenarioMatrix(
        BattleActionStrategy $strategy,
        ?array $scenarios = null,
    ): array {
        $scenarios ??= BalanceScenario::defaults();
        $rows = [];

        foreach ($scenarios as $scenario) {
            $rows[] = $this->runScenario($scenario, $strategy);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{string, string|int|bool|null}>
     */
    public function formatTtkTable(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'result' => $row['result'],
            'player_turns' => $row['player_turns'],
            'target' => $row['min_player_turns'] !== null
                ? "{$row['min_player_turns']}-{$row['max_player_turns']}"
                : '-',
            'ok' => $row['within_target'],
        ], $rows);
    }
}
