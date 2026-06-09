<?php

namespace App\Services\Dungeon;

use App\Models\DungeonFloorMaster;

class DungeonFloorConfig
{
    public static function maxFloor(): int
    {
        return max(1, (int) config('game.dungeon.max_floor', 3));
    }

    public static function playableFloor(): int
    {
        return min(self::maxFloor(), max(1, (int) config('game.dungeon.playable_floor', 1)));
    }

    public static function bossStep(int $floor): ?int
    {
        $floors = config('game.dungeon.floors', []);
        $step = $floors[$floor]['boss_step'] ?? null;

        return is_numeric($step) ? (int) $step : null;
    }

    public static function isBossStep(int $floor, int $step): bool
    {
        $bossStep = self::bossStep($floor);

        return $bossStep !== null && $step === $bossStep;
    }

    public static function bossEnemyCode(int $floor): ?string
    {
        $floors = config('game.dungeon.floors', []);
        $code = $floors[$floor]['boss_enemy_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @return list<array{from: int, to: int, codes: list<string>, count_min?: int, count_max?: int}>
     */
    public static function encounterBands(int $floor): array
    {
        $floors = config('game.dungeon.floors', []);
        $bands = $floors[$floor]['encounter_bands'] ?? [];

        return is_array($bands) ? $bands : [];
    }

    public static function statMultiplierScale(int $floor): int
    {
        $record = DungeonFloorMaster::query()->find($floor);
        if ($record === null) {
            return 100;
        }

        return max(1, min(100, (int) $record->stat_multiplier_scale));
    }
}
