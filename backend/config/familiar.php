<?php

/**
 * 使い魔システムの数値。仕様は docs/使い魔.md。
 *
 * レベルは 0（未解放）〜 max_level。魔石を与えるたびに stones_progress が増え、
 * 次のレベルの `stones` に達するとレベルが上がる（必要数は累計ではなく、前回のレベルアップからの数）。
 */
return [
    'max_level' => 10,

    /** 魔石を1つ与えるのに必要なゴールド */
    'feed_cost' => 100,

    /** 「まとめて与える」ボタンで一度に与える魔石の数（最大レベルまでに必要な数が少ないときは、その数だけ） */
    'bulk_feed_count' => 10,

    /**
     * レベルごとの「必要な魔石の数」と、使い魔ページに出す説明。
     * 説明は解放済みのレベルだけ画面に出す（未解放は「？？？」）。
     */
    'levels' => [
        1 => ['stones' => 1, 'description' => '敵単体にダメージを与える。'],
        2 => ['stones' => 5, 'description' => '味方一人を小回復する。'],
        3 => ['stones' => 10, 'description' => '使い魔が支援してくれる確率が上がる。'],
        4 => ['stones' => 20, 'description' => '敵全体に小ダメージを与える。'],
        5 => ['stones' => 40, 'description' => '味方全体を小回復する。'],
        6 => ['stones' => 60, 'description' => '味方全体の攻撃力を上げる。'],
        7 => ['stones' => 80, 'description' => '使い魔が支援してくれる確率が上がる。'],
        8 => ['stones' => 120, 'description' => '全ての支援の効果が上がる。'],
        9 => ['stones' => 200, 'description' => '戦闘不能の味方を蘇生する。'],
        10 => ['stones' => 300, 'description' => '使い魔が支援してくれる確率が上がる。'],
    ],

    /**
     * 支援の発動確率（%）。「そのレベル以上」で最も大きいキーの値を使う。
     * Lv0 は支援なし。
     */
    'support_rate' => [
        1 => 20,
        3 => 30,
        7 => 40,
        10 => 50,
    ],

    /** このレベル以上になると、全ての支援の効果が強くなる（下の upgraded を使う） */
    'upgrade_level' => 8,

    /**
     * 支援の種類。
     * level   … 解放されるレベル
     * kind    … damage（敵にダメージ）/ heal（味方を回復）/ buff（攻撃バフ）/ revive（蘇生）
     * scope   … single（単体）/ all（全体）
     * base / upgraded … 係数（ダメージ・回復は「パーティーの平均魔力 × 係数」）。upgraded は Lv8 以降
     * weight … 「必ず発動」の条件に当てはまらないとき、選択肢の中から選ばれやすさ。大きいほど選ばれやすい
     *          （選択肢の weight の合計に対する割合。ダメージ = 10、回復・攻撃バフ = 13 で、回復とバフを 1.3 倍選ばれやすくしている）
     * message … 発動したときのメッセージ（{target} は対象の名前）
     */
    'actions' => [
        'enemy_single' => [
            'level' => 1, 'kind' => 'damage', 'scope' => 'single', 'weight' => 10,
            'base' => 1.4, 'upgraded' => 2.0,
            'message' => '敵に攻撃を繰り出した！',
        ],
        'ally_single_heal' => [
            'level' => 2, 'kind' => 'heal', 'scope' => 'single', 'weight' => 13,
            'base' => 1.6, 'upgraded' => 2.2,
            'message' => '{target}に応急手当した！',
        ],
        'enemy_all' => [
            'level' => 4, 'kind' => 'damage', 'scope' => 'all', 'weight' => 10,
            'base' => 1.0, 'upgraded' => 1.6,
            'message' => '次々と敵全体に攻撃！',
        ],
        'ally_all_heal' => [
            'level' => 5, 'kind' => 'heal', 'scope' => 'all', 'weight' => 13,
            'base' => 1.2, 'upgraded' => 1.6,
            'message' => '味方全員に回復魔法を唱えた！',
        ],
        // 攻撃バフは段階制（config/battle.php の stage / buffs）。Lv6〜7 は攻撃1段階（×1.2）、Lv8 以降は2段階（×1.4）
        'ally_attack_buff' => [
            'level' => 6, 'kind' => 'buff', 'scope' => 'all', 'weight' => 13,
            'base' => 'atk_up_1', 'upgraded' => 'atk_up_2',
            'message' => '味方全員に強化魔法を唱えた！',
        ],
        'revive' => [
            'level' => 9, 'kind' => 'revive', 'scope' => 'single',
            'message' => '{target}を蘇生した！',
        ],
    ],

    /** 蘇生したときの HP（最大HPに対する割合 %。最低 1） */
    'revive_hp_percent' => 30,

    /** 支援の演出に出す使い魔の顔（frontend/public/assets/characters/ 配下）。発動のたびにランダムで選ぶ */
    'portraits' => ['tukaima_pc1', 'tukaima_pc2', 'tukaima_pc3', 'tukaima_pc4'],

    /** 支援の最初に出す固定メッセージ */
    'support_title' => '使い魔たちの支援行動！',
];
