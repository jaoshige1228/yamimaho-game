<?php

namespace App\Services\Dungeon;

use App\Models\BattleState;
use App\Models\User;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;

class DungeonAdvanceService
{
    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly BattleOrchestrator $orchestrator = new BattleOrchestrator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function advance(User $user): array
    {
        $nextStep = $this->progress->increment($user);
        $maxStep = $this->progress->maxStep();

        if ($nextStep > $maxStep) {
            $this->progress->reset($user);

            return [
                'event' => 'message',
                'text' => 'この先はまだ何もない…',
                'step' => 0,
            ];
        }

        $stepConfig = config("game.dungeon_steps.{$nextStep}");
        if (! is_array($stepConfig)) {
            throw new \InvalidArgumentException("Unknown dungeon step: {$nextStep}");
        }

        if (($stepConfig['type'] ?? '') === 'message') {
            return [
                'event' => 'message',
                'text' => (string) ($stepConfig['text'] ?? ''),
                'step' => $nextStep,
            ];
        }

        if (($stepConfig['type'] ?? '') === 'story') {
            return [
                'event' => 'story',
                'step' => $nextStep,
                'lines' => $stepConfig['lines'] ?? [],
            ];
        }

        if (($stepConfig['type'] ?? '') === 'battle') {
            return $this->startBattle($user, $nextStep, $stepConfig);
        }

        throw new \InvalidArgumentException("Invalid dungeon step type at step {$nextStep}");
    }

    /**
     * @param  array<string, mixed>  $stepConfig
     * @return array<string, mixed>
     */
    private function startBattle(User $user, int $step, array $stepConfig): array
    {
        /** @var list<array<string, string>> $enemies */
        $enemies = $stepConfig['enemies'] ?? [];
        $meta = [
            'source' => 'dungeon',
            'step' => $step,
            'boss' => (bool) ($stepConfig['boss'] ?? false),
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
