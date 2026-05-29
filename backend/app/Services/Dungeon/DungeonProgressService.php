<?php

namespace App\Services\Dungeon;

use App\Models\User;
use App\Models\UserDungeonProgress;

class DungeonProgressService
{
    public function getStep(User $user): int
    {
        return (int) ($this->findOrCreate($user)->step ?? 0);
    }

    public function reset(User $user): void
    {
        $record = $this->findOrCreate($user);
        $record->step = 0;
        $record->save();
    }

    public function increment(User $user): int
    {
        $record = $this->findOrCreate($user);
        $record->step = min(255, (int) $record->step + 1);
        $record->save();

        return (int) $record->step;
    }

    public function maxStep(): int
    {
        $steps = config('game.dungeon_steps', []);

        return empty($steps) ? 0 : max(array_map('intval', array_keys($steps)));
    }

    private function findOrCreate(User $user): UserDungeonProgress
    {
        return UserDungeonProgress::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['step' => 0],
        );
    }
}
