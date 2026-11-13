<?php

namespace App\Services\Battle;

/**
 * 回復魔法（effect=heal_mag）の回復量の式。戦闘中（BattleEngine）と探索中（DungeonHealService）で共有する。
 */
final class SpellHealing
{
    /**
     * 回復量 = 詠唱者の魔力 × 魔法ごとの倍率（spell_masters.csv の coefficient）× レベルによる補正。
     * coefficient が空の魔法は config/battle.php の heal_mag_multiplier を使う。
     *
     * レベルによる補正（config/battle.php の heal_mag_level_penalty）: 詠唱者が $casterCode のキャラで、
     * レベルが min_level 以上なら、回復量に multiplier を掛ける（サフィリアは Lv22 以上で 0.75 倍）。
     * 全回復（heal_full）・蘇生は回復量の式を通らないので、この補正は受けない。
     *
     * @param  array<string, mixed>  $spell
     */
    public static function healMagAmount(float $mag, array $spell, ?string $casterCode = null, int $casterLevel = 1): int
    {
        $multiplier = (float) ($spell['coefficient'] ?? config('battle.heal_mag_multiplier'));
        $multiplier *= self::levelPenalty($casterCode, $casterLevel);

        return (int) floor($mag * $multiplier);
    }

    /** 詠唱者のレベル補正の倍率（補正なしは 1.0） */
    public static function levelPenalty(?string $casterCode, int $casterLevel): float
    {
        if ($casterCode === null) {
            return 1.0;
        }

        $rule = config("battle.heal_mag_level_penalty.{$casterCode}");
        if (! is_array($rule) || $casterLevel < (int) ($rule['min_level'] ?? PHP_INT_MAX)) {
            return 1.0;
        }

        return (float) ($rule['multiplier'] ?? 1.0);
    }
}
