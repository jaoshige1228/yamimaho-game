<?php

namespace App\Services\Growth;

use App\Models\CharacterMaster;
use App\Models\UserCharacter;
use App\Services\MasterData\LevelTableProvider;

class LevelGrowthService
{
    private const HP_MULTIPLIER = 1.1;

    private const MP_PER_LEVEL = 5;

    private const MAG_DEF_MULTIPLIER = 1.2;

    private const FLAT_STAT_PER_LEVEL = 2;

    /** @var list<string> */
    private const FLAT_STATS = ['str', 'spd', 'know', 'spirit'];

    /** @var list<string> */
    private const MULTIPLY_STATS = ['mag', 'def'];

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
            $this->applyOneLevelGrowth($stats);
        }

        return $stats;
    }

    /**
     * @param  array{hp: int, mp: int, str: int, mag: int, def: int, spd: int, know: int, spirit: int}  $stats
     */
    private function applyOneLevelGrowth(array &$stats): void
    {
        $stats['hp'] = (int) ceil($stats['hp'] * self::HP_MULTIPLIER);
        $stats['mp'] += self::MP_PER_LEVEL;

        foreach (self::MULTIPLY_STATS as $key) {
            $stats[$key] = (int) ceil($stats[$key] * self::MAG_DEF_MULTIPLIER);
        }

        foreach (self::FLAT_STATS as $key) {
            $stats[$key] += self::FLAT_STAT_PER_LEVEL;
        }
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
