<?php

namespace App\Services\Battle;

/**
 * 魔法・特技の大分け（戦闘のコマンド画面で、「攻撃」「回復」「補助」のラベルの下に並べる）。
 * 正本は spell_masters.csv の category 列。空の魔法は、効果（effect）の種類から決める。
 */
final class SpellCategory
{
    public const ATTACK = 'attack';

    public const HEAL = 'heal';

    public const SUPPORT = 'support';

    /** コマンド画面での並び順 */
    public const ALL = [self::ATTACK, self::HEAL, self::SUPPORT];

    /** 効果の種類 => 大分け。ここにない効果は「補助」 */
    private const BY_EFFECT = [
        // 攻撃: 敵にダメージを与える・倒す
        'damage' => self::ATTACK,
        'damage_hp_cost' => self::ATTACK,
        'instant_death' => self::ATTACK,
        // 回復: HP・MP を戻す、蘇生
        'heal_mag' => self::HEAL,
        'heal' => self::HEAL,
        'heal_full' => self::HEAL,
        'revive_chance' => self::HEAL,
        'revive_full' => self::HEAL,
        'mp_heal_hp_cost' => self::HEAL,
        // 補助: バフ・デバフ・回避・障壁・加護
        'buff' => self::SUPPORT,
        'buff_def' => self::SUPPORT,
        'buff_evasion_set' => self::SUPPORT,
        'debuff' => self::SUPPORT,
        'debuff_def' => self::SUPPORT,
        'debuff_def_both' => self::SUPPORT,
        'shield_next' => self::SUPPORT,
        'life_ward' => self::SUPPORT,
    ];

    /**
     * CSV に書いた値が正しければそれ、空や不正なら効果の種類から決めた値。
     */
    public static function resolve(?string $explicit, string $effect): string
    {
        $explicit = trim((string) $explicit);
        if (in_array($explicit, self::ALL, true)) {
            return $explicit;
        }

        return self::forEffect($effect);
    }

    public static function forEffect(string $effect): string
    {
        return self::BY_EFFECT[$effect] ?? self::SUPPORT;
    }

    /** その効果の種類が、上の表にあるか（新しい効果を足したときの足し忘れを、テストで見張るため） */
    public static function knows(string $effect): bool
    {
        return array_key_exists($effect, self::BY_EFFECT);
    }
}
