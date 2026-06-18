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
        private readonly DungeonFlavorNarrativeService $flavor = new DungeonFlavorNarrativeService,
        private readonly DungeonAdvanceOutcome $outcome = new DungeonAdvanceOutcome,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function advance(User $user): array
    {
        if ($this->exploration->hasActiveSession($user)) {
            throw new \RuntimeException('探索イベントの途中です。');
        }

        $floor = $this->progress->getFloor($user);
        if (! $this->progress->isFloorPlayable($user, $floor)) {
            throw new \RuntimeException('この層には挑戦できません。');
        }

        $nextStep = $this->progress->increment($user);

        if (DungeonFloorConfig::isBossStep($floor, $nextStep)) {
            return $this->startBattle($user, $floor, $nextStep);
        }

        $forced = config('game.dungeon.test_force');
        if ($forced === 'exploration') {
            $eventCode = config('game.dungeon.test_event_code');

            return $this->exploration->startEvent(
                $user,
                $floor,
                $nextStep,
                is_string($eventCode) && $eventCode !== '' ? $eventCode : null,
            );
        }

        if ($forced === 'dialogue') {
            $eventCode = config('game.dungeon.test_event_code');

            return $this->exploration->startEvent(
                $user,
                $floor,
                $nextStep,
                is_string($eventCode) && $eventCode !== '' ? $eventCode : null,
                dialogueOnlyPool: true,
            );
        }

        if ($forced === 'flavor') {
            return $this->flavor->start($user, $floor, $nextStep);
        }

        if ($forced === 'battle') {
            return $this->startBattle($user, $floor, $nextStep);
        }

        $rolled = $this->outcome->roll($user);

        if ($rolled === 'battle') {
            return $this->startBattle($user, $floor, $nextStep);
        }

        if ($rolled === 'flavor') {
            return $this->flavor->start($user, $floor, $nextStep);
        }

        if ($rolled === 'dialogue') {
            return $this->exploration->startEvent($user, $floor, $nextStep, dialogueOnlyPool: true);
        }

        return $this->exploration->startEvent($user, $floor, $nextStep);
    }

    /**
     * @return array<string, mixed>
     */
    private function startBattle(User $user, int $floor, int $step): array
    {
        $encounter = $this->encounters->buildEncounter($floor, $step);
        /** @var list<array<string, string>> $enemies */
        $enemies = $encounter['enemies'];
        $isBoss = (bool) $encounter['boss'];
        $meta = [
            'source' => 'dungeon',
            'floor' => $floor,
            'step' => $step,
            'boss' => $isBoss,
            'can_flee' => ! $isBoss,
        ];

        $battleId = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattleForUser($user, $enemies, $meta);

        BattleState::query()->create([
            'id' => $battleId,
            'user_id' => $user->id,
            'state' => $result['state'],
            'status' => $result['state']['status'],
        ]);

        $payload = [
            'event' => 'battle',
            'battle_id' => $battleId,
            'state' => $this->orchestrator->engine()->publicState($result['state']),
            'events' => array_merge(
                [['type' => 'battle_started', 'battle_id' => $battleId]],
                $result['events'],
            ),
            'floor' => $floor,
            'step' => $step,
        ];

        if ($isBoss) {
            $payload['boss_encounter'] = true;
        }

        return array_merge($payload, $this->progress->statusPayload($user));
    }
}
