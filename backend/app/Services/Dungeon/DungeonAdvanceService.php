<?php

namespace App\Services\Dungeon;

use App\Models\BattleState;
use App\Models\User;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Dungeon\Exploration\DungeonExplorationService;

class DungeonAdvanceService
{
    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly BattleOrchestrator $orchestrator = new BattleOrchestrator,
        private readonly DungeonEncounterCatalog $encounters = new DungeonEncounterCatalog,
        private readonly DungeonExplorationService $exploration = new DungeonExplorationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function advance(User $user): array
    {
        if ($this->exploration->hasActiveSession($user)) {
            throw new \RuntimeException('探索イベントの途中です。');
        }

        $nextStep = $this->progress->increment($user);

        $forced = config('game.dungeon.test_force');
        if ($forced === 'exploration') {
            $eventCode = config('game.dungeon.test_event_code');

            return $this->exploration->startEvent(
                $user,
                $nextStep,
                is_string($eventCode) && $eventCode !== '' ? $eventCode : null,
            );
        }

        if ($forced === 'battle' || $this->rollBattle($user)) {
            return $this->startBattle($user, $nextStep);
        }

        return $this->exploration->startEvent($user, $nextStep);
    }

    private function rollBattle(User $user): bool
    {
        if ($this->progress->shouldSkipBattles($user)) {
            return false;
        }

        $battleRate = (int) config('game.dungeon.battle_encounter_rate', 20);

        return random_int(1, 100) <= $battleRate;
    }

    /**
     * @return array<string, mixed>
     */
    private function startBattle(User $user, int $step): array
    {
        $encounter = $this->encounters->pickRandomEncounter();
        /** @var list<array<string, string>> $enemies */
        $enemies = $encounter['enemies'];
        $meta = [
            'source' => 'dungeon',
            'step' => $step,
            'boss' => $encounter['boss'],
        ];

        $battleId = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattleForUser($user, $enemies, $meta);

        BattleState::query()->create([
            'id' => $battleId,
            'state' => $result['state'],
            'status' => $result['state']['status'],
        ]);

        return [
            'event' => 'battle',
            'battle_id' => $battleId,
            'state' => $this->orchestrator->engine()->publicState($result['state']),
            'events' => array_merge(
                [['type' => 'battle_started', 'battle_id' => $battleId]],
                $result['events'],
            ),
            'step' => $step,
        ];
    }
}
