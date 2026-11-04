<?php

return [
  'demo_user_email' => 'demo@yamimaho.local',

  /** @deprecated 勝利時経験値は enemy_masters.exp_reward の合計を使用 */
  'exp_per_victory' => 40,

  /** 味方スロット ID → character_masters.code */
  'party_slots' => [
    'pc1' => 'pc1',
    'pc2' => 'pc2',
    'pc3' => 'pc3',
    'pc4' => 'pc4',
  ],

  /**
   * 敵を初めて倒したとき（その敵で覚える魔法を、まだ一つも覚えていないとき）に、
   * キャラが一度だけ受け取る最大MPの加算値。魔法を覚える処理（SpellLearningService）の中で付与する。
   * 敵コード => [キャラのマスターコード => 加算するMP]。画面には出さない。
   */
  'first_defeat_max_mp_bonus' => [
    'inu_moe' => ['pc1' => 10],
  ],

  /**
   * ダンジョン探索の抽選（合計100想定。CSVにはしない）
   */
  'dungeon' => [
    'max_floor' => 3,
    /** 実装済みで挑戦可能な最大層（2層目以降は未実装の間は 1） */
    'playable_floor' => 2,
    'battle_encounter_rate' => 30,
    'exploration_event_rate' => 60,
    'dialogue_event_rate' => 9,
    'flavor_narrative_rate' => 1,
    /**
     * 層ごとの設定。boss_step に到達した「進む」でボス戦（通常抽選は行わない）
     */
    'floors' => [
      1 => [
        'boss_step' => 31,
        'boss_enemy_codes' => ['inu_moe'],
        'encounter_bands' => [
          [
            'from' => 1,
            'to' => 10,
            'codes' => ['bat'],
            'count_min' => 1,
            'count_max' => 2,
          ],
          [
            'from' => 11,
            'to' => 14,
            'codes' => ['bat'],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 15,
            'to' => 20,
            'codes' => ['bat', 'snake'],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 21,
            'to' => 25,
            'codes' => ['bat', 'snake', 'beetle'],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 26,
            'to' => 30,
            'codes' => ['snake', 'beetle'],
            'count_min' => 2,
            'count_max' => 3,
          ],
        ],
      ],
      2 => [
        'boss_step' => 31,
        /** ボス戦の敵（左から enemy_1, enemy_2…）。エルドラゴンを中央に、邪教徒 2 体を両脇に置く */
        'boss_enemy_codes' => ['mahou', 'dragon', 'mahou'],
        'mid_boss_step' => 20,
        'mid_boss_event_code' => 'floor2_mid_boss',
        'mid_boss_enemy_codes' => ['npc_kasu', 'npc_gusu'],
        /**
         * 探索イベント（宝箱・スフィンクス）の報酬アイテムの差し替え（元のアイテムコード => この層で出すアイテムコード）。
         * ノードは元のコード（potion_hp_s など）のまま書き、文面は {item:potion_hp_s} と書くと、この層ではここで指定した名前に変わる。
         */
        'reward_item_map' => [
          'potion_hp_s' => 'potion_hp_m',
          'potion_mp_s' => 'potion_mp_m',
        ],
        /**
         * weights = 敵 1 体ごとの出現の重み（敵コード => 重み）。省略した帯は codes から等確率。
         * ミミックは経験値・ゴールドが桁違いに多いので、どの帯でも約 3% のレア扱い。
         */
        'encounter_bands' => [
          [
            'from' => 1,
            'to' => 10,
            'codes' => ['jack', 'mimic'],
            'weights' => ['jack' => 30, 'mimic' => 1],
            'count_min' => 1,
            'count_max' => 2,
          ],
          [
            'from' => 11,
            'to' => 20,
            'codes' => ['jack', 'shinigami', 'mimic'],
            'weights' => ['jack' => 15, 'shinigami' => 15, 'mimic' => 1],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 21,
            'to' => 25,
            'codes' => ['jack', 'shinigami', 'knight', 'mimic'],
            'weights' => ['jack' => 10, 'shinigami' => 10, 'knight' => 10, 'mimic' => 1],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 26,
            'to' => 30,
            'codes' => ['shinigami', 'knight', 'mimic'],
            'weights' => ['shinigami' => 15, 'knight' => 15, 'mimic' => 1],
            'count_min' => 2,
            'count_max' => 3,
          ],
        ],
      ],
    ],
    /** テスト用: null | battle | exploration | dialogue | flavor */
    'test_force' => env('DUNGEON_TEST_FORCE'),
    /** テスト用: trap_arrow | treasure_chest など */
    'test_event_code' => env('DUNGEON_TEST_EVENT_CODE'),
    /** テスト用: 1-100 の固定ロール（null で通常乱数） */
    'test_roll' => env('DUNGEON_TEST_ROLL'),
    /** テスト用: true/false で判定結果を固定（null で通常） */
    'test_stat_success' => env('DUNGEON_TEST_STAT_SUCCESS'),
    /** ステータス判定・chance_branch の成功率上限（%） */
    'max_stat_success_rate' => 95,
  ],
];
