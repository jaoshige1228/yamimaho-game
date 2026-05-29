<?php

return [
    'turn_order' => ['pc1', 'pc2', 'pc3', 'pc4', 'enemy_1', 'enemy_2', 'enemy_3'], // 非推奨: 戦闘開始時に spd で再計算

    'common_actions' => [
        'punch' => [
            'label' => 'こぶし',
            'description' => '物理攻撃。敵単体にダメージを与える。',
            'mp_cost' => 0,
            'target_type' => 'enemy_single',
            'coefficient' => 1.0,
        ],
        'defend' => [
            'label' => '防御',
            'description' => '防御の構え。敵の攻撃まで被ダメージを半減する。',
            'mp_cost' => 0,
            'target_type' => 'self',
        ],
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
        ['slot' => 'enemy_1', 'master_code' => 'kappa', 'name' => 'カッパA'],
        ['slot' => 'enemy_2', 'master_code' => 'kappa', 'name' => 'カッパB'],
        ['slot' => 'enemy_3', 'master_code' => 'kappa', 'name' => 'カッパC'],
    ],

    /** カッパLV2 ×3 のデモ戦闘 */
    'demo_enemies_kappa2' => [
        ['slot' => 'enemy_1', 'master_code' => 'kappa2', 'name' => 'カッパA'],
        ['slot' => 'enemy_2', 'master_code' => 'kappa2', 'name' => 'カッパB'],
        ['slot' => 'enemy_3', 'master_code' => 'kappa2', 'name' => 'カッパC'],
    ],

    'buffs' => [
        'evasion_up_large' => ['stat' => 'evasion', 'multiplier' => 1.5, 'turns' => 3],
        'evasion_up_small' => ['stat' => 'evasion', 'multiplier' => 1.2, 'turns' => 2],
        'def_up_large' => ['stat' => 'def', 'multiplier' => 1.5, 'turns' => 3],
        'def_up_small' => ['stat' => 'def', 'multiplier' => 1.2, 'turns' => 2],
        'ability_down_large' => ['stat' => 'str', 'multiplier' => 0.6, 'turns' => 3],
        'ability_down_small' => ['stat' => 'str', 'multiplier' => 0.8, 'turns' => 2],
    ],

    'defend_damage_multiplier' => 0.5,
];
