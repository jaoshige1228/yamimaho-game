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
    'battle_encounter_rate' => 20,
    'exploration_event_rate' => 80,
    /** テスト用: null | battle | exploration */
    'test_force' => env('DUNGEON_TEST_FORCE'),
    /** テスト用: trap_arrow | treasure_chest など */
    'test_event_code' => env('DUNGEON_TEST_EVENT_CODE'),
    /** テスト用: 1-100 の固定ロール（null で通常乱数） */
    'test_roll' => env('DUNGEON_TEST_ROLL'),
    /** テスト用: true/false で判定結果を固定（null で通常） */
    'test_stat_success' => env('DUNGEON_TEST_STAT_SUCCESS'),
  ],
];
