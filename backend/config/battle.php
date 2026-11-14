<?php

return [
    'turn_order' => ['pc1', 'pc2', 'pc3', 'pc4', 'enemy_1', 'enemy_2', 'enemy_3'], // 非推奨: 戦闘開始時に spd で再計算

    'ally_defaults' => [
        'evasion_rate' => 5,
        'crit_rate' => 5,
    ],

    'common_actions' => [
        'punch' => [
            'label' => 'こぶし',
            'description' => 'うなる拳骨。威力は低いが確かな命中率。',
            'mp_cost' => 0,
            'target_type' => 'enemy_single',
            'coefficient' => 1.0,
        ],
        'kick' => [
            'label' => 'キック',
            'description' => 'うなる旋脚。命中率は低いが威力が高い。',
            'mp_cost' => 0,
            'target_type' => 'enemy_single',
            'coefficient' => 2.0,
            'hit_rate' => 50,
        ],
    ],

    /** 味方 Lv10 以降のこぶし・キック倍率（全キャラ共通） */
    'physical_mastery' => [
        'min_level' => 10,
        'punch_coefficient' => 1.5,
        'kick_coefficient' => 3.0,
    ],

    /** デモ戦闘の味方スロット → マスターコード */
    'demo_party' => [
        'pc1' => ['master_code' => 'pc1'],
        'pc2' => ['master_code' => 'pc2'],
        'pc3' => ['master_code' => 'pc3'],
        'pc4' => ['master_code' => 'pc4'],
    ],

    /** デモ戦闘の敵スロット */
    'demo_enemies' => [
        ['slot' => 'enemy_1', 'master_code' => 'bat', 'name' => 'コーモリA'],
        ['slot' => 'enemy_2', 'master_code' => 'bat', 'name' => 'コーモリB'],
        ['slot' => 'enemy_3', 'master_code' => 'bat', 'name' => 'コーモリC'],
    ],

    /** 1層ボス戦デモ */
    'demo_boss' => [
        ['slot' => 'enemy_1', 'master_code' => 'inu_moe', 'name' => 'イフリーヌ'],
    ],

    /** 回復魔法（effect=heal_mag）の回復量 = 詠唱者の魔力 × この値。戦闘中・探索中の両方で使う */
    'heal_mag_multiplier' => 2,

    /**
     * 回復魔法（heal_mag）の回復量を、詠唱者のレベルに応じて減らす。キャラのマスターコード => [min_level, multiplier]。
     * サフィリア（pc1）は Lv22 以上で回復量 0.75 倍。全回復（精霊の水）・蘇生には掛からない。使い魔の回復にも掛からない。
     */
    'heal_mag_level_penalty' => [
        'pc1' => ['min_level' => 22, 'multiplier' => 0.75],
    ],

    /**
     * 回復魔法（heal_mag）の回復量を、詠唱者のレベルが低いときだけ増やす。魔法のコード => 補正のリスト（[max_level, multiplier] を 1 つ以上）。
     * 詠唱者のレベルが max_level 以下の補正は、**すべて掛け合わせる**（重なる）。
     * 癒しの水（pc1_heal_water）: Lv10 以下で 1.3 倍、**さらに Lv5 以下で 1.5 倍**（Lv5 以下は 1.3 × 1.5 = 1.95 倍）。Lv11 以上は元の倍率。
     * 全回復・蘇生には掛からない。使い魔の回復にも掛からない。下の heal_mag_level_penalty（Lv22 以上）とは重ならない。
     */
    'heal_mag_spell_level_bonus' => [
        'pc1_heal_water' => [
            ['max_level' => 10, 'multiplier' => 1.3],
            ['max_level' => 5, 'multiplier' => 1.5],
        ],
    ],

    /** 蘇生魔法（effect=revive_chance）で復活したときの HP（最大 HP に対する割合 %）。戦闘中・探索中で共通 */
    'revive_hp_percent' => 50,

    /**
     * 防御・攻撃のバフ／デバフは「段階」で管理する（-3〜+3）。
     * ユニットの `stages` に、付与1回につき1要素（stat・amount・turns・source）を持ち、持続ターンは要素ごとに独立。
     * 現在の段階 = その stat の amount の合計を -max〜+max に丸めたもの。倍率は下の multipliers で決まる。
     * 攻撃（atk）は筋力と魔力の両方、防御（def）は def・pdef・mdef のすべてに掛かる。
     */
    'stage' => [
        'max' => 3,
        'turns' => 3,
        'multipliers' => [
            'def' => [1 => 1.2, 2 => 1.4, 3 => 1.7, -1 => 0.8, -2 => 0.65, -3 => 0.5],
            'atk' => [1 => 1.2, 2 => 1.4, 3 => 1.6, -1 => 0.8, -2 => 0.65, -3 => 0.5],
        ],
        /** 段階の stat が掛かるユニットのステータス */
        'applies_to' => [
            'def' => ['def', 'pdef', 'mdef'],
            'atk' => ['str', 'mag'],
        ],
    ],

    /**
     * 魔法の buff_key（spell_masters.csv）から引く効果。
     * type=stage … 防御・攻撃の段階（stat は def / atk、delta は上がる段階（+）／下がる段階（-））
     * type=evasion_set … 回避率を固定する（ユニットの `buffs` に残りターンで持つ）
     */
    'buffs' => [
        'def_up_1' => ['type' => 'stage', 'stat' => 'def', 'delta' => 1],
        'def_up_2' => ['type' => 'stage', 'stat' => 'def', 'delta' => 2],
        'def_up_3' => ['type' => 'stage', 'stat' => 'def', 'delta' => 3],
        'def_down_1' => ['type' => 'stage', 'stat' => 'def', 'delta' => -1],
        'def_down_2' => ['type' => 'stage', 'stat' => 'def', 'delta' => -2],
        'def_down_3' => ['type' => 'stage', 'stat' => 'def', 'delta' => -3],
        'atk_up_1' => ['type' => 'stage', 'stat' => 'atk', 'delta' => 1],
        'atk_up_2' => ['type' => 'stage', 'stat' => 'atk', 'delta' => 2],
        'atk_up_3' => ['type' => 'stage', 'stat' => 'atk', 'delta' => 3],
        'atk_down_1' => ['type' => 'stage', 'stat' => 'atk', 'delta' => -1],
        'atk_down_2' => ['type' => 'stage', 'stat' => 'atk', 'delta' => -2],
        'atk_down_3' => ['type' => 'stage', 'stat' => 'atk', 'delta' => -3],
        'evasion_set' => ['type' => 'evasion_set', 'value' => 60, 'turns' => 3, 'icon' => 'evasion'],
        'evasion_set_small' => ['type' => 'evasion_set', 'value' => 30, 'turns' => 3, 'icon' => 'evasion'],
        // 裂け目の光導の魔法使い（悪魔の幻影群）
        'evasion_set_20' => ['type' => 'evasion_set', 'value' => 20, 'turns' => 3, 'icon' => 'evasion'],
    ],

    /**
     * 詠唱が数ターンかかる敵の技（enemy_skills.csv の action_type = charged_spell）。キーは技のコード。
     * 唱え始めたターンに start、次の行動で chant、そのまた次の行動で fire（効果が出る）。{name} は唱える敵の名前。
     * 詠唱の途中でその敵が倒れれば、効果は出ない（倒れた敵は行動しない）。
     */
    'charged_skills' => [
        'rift_evil_infinity' => [
            'start' => '{name}はおぞましい魔法を唱えようとしている……。',
            'chant' => '{name}はおぞましい魔法を詠唱し始めた！',
            'fire' => '{name}は悪魔級殲滅魔法（イーヴィルインフィニティ）を唱え終えた！',
        ],
    ],
];
