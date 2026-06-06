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
        $battleRate = max(0, (int) config('game.dungeon.battle_encounter_rate', 30));
        $explorationRate = max(0, (int) config('game.dungeon.exploration_event_rate', 60));
        $flavorRate = max(0, (int) config('game.dungeon.flavor_narrative_rate', 10));

        if ($this->progress->shouldSkipBattles($user)) {
            $nonBattleTotal = $explorationRate + $flavorRate;
            if ($nonBattleTotal <= 0) {
                return 'exploration';
            }

            $explorationShare = $explorationRate + $battleRate;
            $roll = random_int(1, $explorationShare + $flavorRate);

            return $roll <= $explorationShare ? 'exploration' : 'flavor';
        }

        $roll = random_int(1, 100);

        if ($roll <= $battleRate) {
            return 'battle';
        }

        if ($roll <= $battleRate + $explorationRate) {
            return 'exploration';
        }

        if ($roll <= $battleRate + $explorationRate + $flavorRate) {
            return 'flavor';
        }

        return 'exploration';
    }
}
