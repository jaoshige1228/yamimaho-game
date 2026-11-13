import { publicAssetUrl } from './publicAssetUrl.js';

/** 探索画面の背景（層 => public/assets/bg/ のファイル名）。ここにない層は 1 層と同じ */
const FLOOR_BACKGROUNDS = {
  1: 'dungeon1.webp',
  2: '2nd_floor.webp',
  3: '3rd_floor.webp',
};

/** 探索中の BGM（層 => useBattleAudio.js の BGM_TRACKS のキー）。ここにない層は 1 層と同じ */
const FLOOR_BGM_TRACKS = {
  1: 'dungeon',
  2: 'dungeon_floor2',
  3: 'dungeon_floor3',
};

/** 通常戦の BGM（層 => useBattleAudio.js の BGM_TRACKS のキー）。ここにない層は 'battle' */
const FLOOR_BATTLE_BGM_TRACKS = {
  3: 'battle_floor3',
};

/** ボス戦の BGM（層 => BGM_TRACKS のキー）。ここにない層は 'boss' */
const FLOOR_BOSS_BGM_TRACKS = {
  3: 'boss_floor3',
};

/** @param {number | string | null | undefined} floor */
export function dungeonBackgroundUrl(floor) {
  const file = FLOOR_BACKGROUNDS[floor] ?? FLOOR_BACKGROUNDS[1];
  return publicAssetUrl(`/assets/bg/${file}`);
}

/** @param {number | string | null | undefined} floor */
export function dungeonBgmTrack(floor) {
  return FLOOR_BGM_TRACKS[floor] ?? FLOOR_BGM_TRACKS[1];
}

/**
 * 戦闘中の BGM のキー。中ボス戦（broken）と空間の裂け目のボス戦（last_boss）は層に関係なく共通で、通常戦・ボス戦は層ごとに変えられる。
 * 戦闘の meta.bgm に BGM のキーが入っているもの（'broken' / 'last_boss'）は、それを優先する。
 *
 * @param {{ floor?: number | string | null, boss?: boolean, midBoss?: boolean, bgm?: string | null }} [options]
 */
export function battleBgmTrack({ floor = null, boss = false, midBoss = false, bgm = null } = {}) {
  if (midBoss || bgm === 'broken') return 'broken';
  if (bgm === 'last_boss') return 'last_boss';
  if (boss) return FLOOR_BOSS_BGM_TRACKS[floor] ?? 'boss';
  return FLOOR_BATTLE_BGM_TRACKS[floor] ?? 'battle';
}
