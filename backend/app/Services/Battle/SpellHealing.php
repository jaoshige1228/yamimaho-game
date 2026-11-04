<?php

namespace App\Services\Battle;

/**
 * 回復魔法（effect=heal_mag）の回復量の式。戦闘中（BattleEngine）と探索中（DungeonHealService）で共有する。
 */
final class SpellHealing
{
    /**
     * 回復量 = 詠唱者の魔力 × 魔法ごとの倍率（spell_masters.csv の coefficient）。
     * coefficient が空の魔法は config/battle.php の heal_mag_multiplier を使う。
     *
     * @param  array<string, mixed>  $spell
     */
    public static function healMagAmount(float $mag, array $spell): int
    {
        $multiplier = (float) ($spell['coefficient'] ?? config('battle.heal_mag_multiplier'));

        return (int) floor($mag * $multiplier);
    }
}
