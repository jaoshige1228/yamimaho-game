<?php

namespace App\Services\Player;

use App\Models\BattleState;
use App\Models\User;
use App\Services\Dungeon\DungeonProgressService;

class GameNavigationService
{
    public function __construct(
        private readonly DungeonProgressService $dungeon = new DungeonProgressService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $inDungeon = $this->dungeon->isInDungeon($user);
        $activeBattleId = $this->findActiveBattleId($user);

        return [
            'in_dungeon' => $inDungeon,
            'active_battle_id' => $activeBattleId,
            'battle_from_dungeon' => $activeBattleId !== null
                ? $this->isDungeonBattle($activeBattleId)
                : false,
        ];
    }

    private function findActiveBattleId(User $user): ?string
    {
        $record = BattleState::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();

        return $record?->id;
    }

    private function isDungeonBattle(string $battleId): bool
    {
        $record = BattleState::query()->find($battleId);
        if ($record === null) {
            return false;
        }

        $meta = $record->state['meta'] ?? [];

        return ($meta['source'] ?? '') === 'dungeon';
    }
}
