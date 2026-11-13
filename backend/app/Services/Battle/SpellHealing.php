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
     *
     * 魔法ごとの低レベル補正（config/battle.php の heal_mag_spell_level_bonus）: 魔法のコード $spellCode が登録されていて、
     * 詠唱者のレベルが max_level 以下なら、回復量に multiplier を掛ける（癒しの水は Lv10 以下で 1.3 倍）。
     *
     * 全回復（heal_full）・蘇生は回復量の式を通らないので、これらの補正は受けない。
     *
     * @param  array<string, mixed>  $spell
     * @param  string|null  $spellCode  魔法のコード（spell_masters.csv の code）。省略すると、魔法ごとの補正は掛からない
     */
    public static function healMagAmount(float $mag, array $spell, ?string $casterCode = null, int $casterLevel = 1, ?string $spellCode = null): int
    {
        $multiplier = (float) ($spell['coefficient'] ?? config('battle.heal_mag_multiplier'));
        $multiplier *= self::levelPenalty($casterCode, $casterLevel);
        $multiplier *= self::spellLevelBonus($spellCode, $casterLevel);

        return (int) floor($mag * $multiplier);
    }

    /** 魔法ごとの低レベル補正の倍率（補正なし・対象外のレベルは 1.0） */
    public static function spellLevelBonus(?string $spellCode, int $casterLevel): float
    {
        if ($spellCode === null) {
            return 1.0;
        }

        $rule = config("battle.heal_mag_spell_level_bonus.{$spellCode}");
        if (! is_array($rule) || $casterLevel > (int) ($rule['max_level'] ?? 0)) {
            return 1.0;
        }

        return (float) ($rule['multiplier'] ?? 1.0);
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
