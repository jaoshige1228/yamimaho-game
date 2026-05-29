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
    ): array {
        $bootstrap = $this->orchestrator->createBattle();
        $state = $bootstrap['state'];
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
}
