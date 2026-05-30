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
        $record->step = min(65535, (int) $record->step + 1);
        $record->save();

        return (int) $record->step;
    }

    public function shouldSkipBattles(User $user): bool
    {
        return (bool) $this->findOrCreate($user)->skip_battle_encounters;
    }

    public function setSkipBattles(User $user, bool $skip): void
    {
        $record = $this->findOrCreate($user);
        $record->skip_battle_encounters = $skip;
        $record->save();
    }

    private function findOrCreate(User $user): UserDungeonProgress
    {
        return UserDungeonProgress::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['step' => 0, 'skip_battle_encounters' => false],
        );
    }
}
