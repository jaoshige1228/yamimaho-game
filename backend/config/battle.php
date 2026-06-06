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

    'buffs' => [
        'def_up' => ['stat' => 'def', 'multiplier' => 1.3, 'turns' => 3, 'icon' => 'def_up'],
        'def_up_large' => ['stat' => 'def', 'multiplier' => 1.5, 'turns' => 3, 'icon' => 'def_up_large'],
        'def_down' => ['stat' => 'def', 'multiplier' => 0.7, 'turns' => 3, 'icon' => 'def_down'],
        'def_down_large' => ['stat' => 'def', 'multiplier' => 0.6, 'turns' => 3, 'icon' => 'def_down_large'],
        'atk_up' => ['stat' => 'str', 'multiplier' => 1.2, 'turns' => 3, 'icon' => 'atk_up'],
        'atk_up_large' => ['stat' => 'str', 'multiplier' => 1.5, 'turns' => 3, 'icon' => 'atk_up_large'],
        'atk_down' => ['stat' => 'str', 'multiplier' => 0.8, 'turns' => 3, 'icon' => 'atk_down'],
        'atk_down_large' => ['stat' => 'str', 'multiplier' => 0.6, 'turns' => 3, 'icon' => 'atk_down_large'],
        'evasion_set' => ['type' => 'evasion_set', 'value' => 50, 'turns' => 3, 'icon' => 'evasion'],
    ],
];
