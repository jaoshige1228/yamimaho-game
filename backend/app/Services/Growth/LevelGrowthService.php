<?php

namespace App\Services\Growth;

use App\Models\CharacterMaster;
use App\Models\UserCharacter;
use App\Services\MasterData\LevelTableProvider;

class LevelGrowthService
{
    public function __construct(
        private readonly LevelTableProvider $levels = new LevelTableProvider,
    ) {}

    public function cumulativeExpForLevel(int $level): int
    {
        return $this->levels->cumulativeExpForLevel($level);
    }

    /**
     * @return array{hp: int, mp: int, str: int, mag: int, def: int, spd: int, know: int, spirit: int}
     */
    public function statsForLevel(CharacterMaster $master, int $level): array
    {
        $level = max(1, min($level, $this->levels->maxLevel()));

        $stats = [
            'hp' => $master->hp,
            'mp' => $master->mp,
            'str' => $master->str,
            'mag' => $master->mag,
            'def' => $master->def,
            'spd' => $master->spd,
            'know' => $master->know,
            'spirit' => $master->spirit,
        ];

        for ($current = 1; $current < $level; $current++) {
            $row = $this->levels->findLevel($current);
            $stats['hp'] = (int) ceil($stats['hp'] * (1 + (float) $row['hp_growth']));
            $stats['mp'] = (int) ceil($stats['mp'] * (1 + (float) $row['mp_growth']));
            $statMultiplier = 1 + (float) $row['stat_growth'];
            foreach (['str', 'mag', 'def', 'spd', 'know', 'spirit'] as $key) {
                $stats[$key] = (int) ceil($stats[$key] * $statMultiplier);
            }
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grantExp(UserCharacter $character, int $amount): array
    {
        if ($amount <= 0) {
            return [];
        }

        $character->exp += $amount;
        $events = [];
        $master = $character->characterMaster;
        $maxLevel = $this->levels->maxLevel();

        while ($character->level < $maxLevel) {
            $nextLevel = $character->level + 1;
            if ($character->exp < $this->levels->cumulativeExpForLevel($nextLevel)) {
                break;
            }

            $before = $this->statsForLevel($master, $character->level);
            $character->level = $nextLevel;
            $after = $this->statsForLevel($master, $character->level);

            $character->hp = min($after['hp'], $character->hp + max(0, $after['hp'] - $before['hp']));
            $character->mp = min($after['mp'], $character->mp + max(0, $after['mp'] - $before['mp']));
            $character->str = $after['str'];
            $character->mag = $after['mag'];
            $character->def = $after['def'];
            $character->spd = $after['spd'];
            $character->know = $after['know'];
            $character->spirit = $after['spirit'];

            $events[] = [
                'type' => 'level_up',
                'slot_id' => $this->slotIdForCharacter($character),
                'name' => $master->name,
                'level' => $character->level,
            ];
        }

        return $events;
    }

    private function slotIdForCharacter(UserCharacter $character): ?string
    {
        $code = $character->characterMaster->code;
        foreach (config('game.party_slots', []) as $slotId => $masterCode) {
            if ($masterCode === $code) {
                return $slotId;
            }
        }

        return null;
    }
}
