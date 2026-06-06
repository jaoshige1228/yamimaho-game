<?php

return [
  'demo_user_email' => 'demo@yamimaho.local',

  /** 勝利時に味方1人あたりが得る経験値 */
  'exp_per_victory' => 40,

  /** 味方スロット ID → character_masters.code */
  'party_slots' => [
    'pc1' => 'pc1',
    'pc2' => 'pc2',
    'pc3' => 'pc3',
    'pc4' => 'pc4',
  ],

  /**
   * ダンジョン探索の抽選（合計100想定。CSVにはしない）
   */
  'dungeon' => [
    'max_floor' => 3,
    /** 実装済みで挑戦可能な最大層（2層目以降は未実装の間は 1） */
    'playable_floor' => 1,
    'battle_encounter_rate' => 30,
    'exploration_event_rate' => 60,
    'flavor_narrative_rate' => 10,
    /**
     * 層ごとの設定。boss_step に到達した「進む」でボス戦（通常抽選は行わない）
     */
    'floors' => [
      1 => [
        'boss_step' => 31,
        'boss_enemy_code' => 'inu_moe',
        'encounter_bands' => [
          [
            'from' => 1,
            'to' => 10,
            'codes' => ['bat'],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 11,
            'to' => 20,
            'codes' => ['bat', 'snake'],
            'count_min' => 2,
            'count_max' => 3,
          ],
          [
            'from' => 21,
            'to' => 30,
            'codes' => ['snake', 'beetle'],
            'count_min' => 2,
            'count_max' => 3,
          ],
        ],
      ],
    ],
    /** テスト用: null | battle | exploration | flavor */
    'test_force' => env('DUNGEON_TEST_FORCE'),
    /** テスト用: trap_arrow | treasure_chest など */
    'test_event_code' => env('DUNGEON_TEST_EVENT_CODE'),
    /** テスト用: 1-100 の固定ロール（null で通常乱数） */
    'test_roll' => env('DUNGEON_TEST_ROLL'),
    /** テスト用: true/false で判定結果を固定（null で通常） */
    'test_stat_success' => env('DUNGEON_TEST_STAT_SUCCESS'),
  ],
];
