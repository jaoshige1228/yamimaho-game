<?php

namespace App\Services\Dungeon;

use App\Models\User;

class DungeonAdvanceOutcome
{
    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
    ) {}

    public function roll(User $user): string
    {
        if ($this->progress->shouldForceDialogueEvents($user)) {
            return 'dialogue';
        }

        $battleRate = max(0, (int) config('game.dungeon.battle_encounter_rate', 30));
        $explorationRate = max(0, (int) config('game.dungeon.exploration_event_rate', 55));
        $dialogueRate = max(0, (int) config('game.dungeon.dialogue_event_rate', 10));
        $flavorRate = max(0, (int) config('game.dungeon.flavor_narrative_rate', 5));

        if ($this->progress->shouldSkipBattles($user)) {
            $explorationRate += $battleRate;
            $battleRate = 0;
        }

        $roll = random_int(1, 100);
        $cursor = $battleRate;

        if ($roll <= $cursor) {
            return 'battle';
        }

        $cursor += $explorationRate;
        if ($roll <= $cursor) {
            return 'exploration';
        }

        $cursor += $dialogueRate;
        if ($roll <= $cursor) {
            return 'dialogue';
        }

        $cursor += $flavorRate;
        if ($roll <= $cursor) {
            return 'flavor';
        }

        return 'exploration';
    }
}
