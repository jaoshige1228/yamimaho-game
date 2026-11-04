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

    /**
     * ボス戦に出る敵のコード（左から enemy_1, enemy_2…。同じコードを重ねて複数体にできる）。
     *
     * @return list<string>
     */
    public static function bossEnemyCodes(int $floor): array
    {
        $floors = config('game.dungeon.floors', []);
        $codes = $floors[$floor]['boss_enemy_codes'] ?? [];

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter($codes, fn ($code) => is_string($code) && $code !== ''));
    }

    public static function midBossStep(int $floor): ?int
    {
        $floors = config('game.dungeon.floors', []);
        $step = $floors[$floor]['mid_boss_step'] ?? null;

        return is_numeric($step) ? (int) $step : null;
    }

    public static function isMidBossStep(int $floor, int $step): bool
    {
        $midBossStep = self::midBossStep($floor);

        return $midBossStep !== null && $step === $midBossStep;
    }

    public static function midBossEventCode(int $floor): ?string
    {
        $floors = config('game.dungeon.floors', []);
        $code = $floors[$floor]['mid_boss_event_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @return list<string>
     */
    public static function midBossEnemyCodes(int $floor): array
    {
        $floors = config('game.dungeon.floors', []);
        $codes = $floors[$floor]['mid_boss_enemy_codes'] ?? [];

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter($codes, fn ($code) => is_string($code) && $code !== ''));
    }

    /**
     * @return list<array{from: int, to: int, codes: list<string>, weights?: array<string, int>, count_min?: int, count_max?: int}>
     */
    public static function encounterBands(int $floor): array
    {
        $floors = config('game.dungeon.floors', []);
        $bands = $floors[$floor]['encounter_bands'] ?? [];

        return is_array($bands) ? $bands : [];
    }

    /**
     * 探索イベントが報酬として出すアイテムのコード。層ごとの差し替え（reward_item_map）があればそれ、なければそのまま。
     */
    public static function rewardItemCode(int $floor, string $itemCode): string
    {
        $map = config("game.dungeon.floors.{$floor}.reward_item_map", []);

        return is_array($map) && isset($map[$itemCode]) ? (string) $map[$itemCode] : $itemCode;
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
