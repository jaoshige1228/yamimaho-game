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
   * ダンジョン「進む」累計 step → イベント。
   * battle.enemies は battle.demo_enemies と同形式の配列。
   */
  'dungeon_steps' => [
    1 => [
      'type' => 'message',
      'text' => '何も起きなかった',
    ],
    2 => [
      'type' => 'battle',
      'boss' => false,
      'enemies' => [
        ['slot' => 'enemy_1', 'master_code' => 'kappa', 'name' => 'カッパA'],
        ['slot' => 'enemy_2', 'master_code' => 'kappa', 'name' => 'カッパB'],
      ],
    ],
    3 => [
      'type' => 'story',
      'lines' => [
        ['type' => 'narration', 'text' => 'この先から変な匂いが漂ってくる……'],
        ['type' => 'dialogue', 'character' => 'pc1', 'text' => 'な、なんか変な匂いがするよ！？'],
      ],
    ],
    4 => [
      'type' => 'battle',
      'boss' => false,
      'enemies' => [
        ['slot' => 'enemy_1', 'master_code' => 'kappa', 'name' => 'カッパA'],
        ['slot' => 'enemy_2', 'master_code' => 'kappa', 'name' => 'カッパB'],
        ['slot' => 'enemy_3', 'master_code' => 'kappa', 'name' => 'カッパC'],
      ],
    ],
    5 => [
      'type' => 'message',
      'text' => '何も起きなかった',
    ],
    6 => [
      'type' => 'battle',
      'boss' => true,
      'enemies' => [
        ['slot' => 'enemy_1', 'master_code' => 'sha', 'name' => '大佐'],
      ],
    ],
  ],
];
