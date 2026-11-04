import { publicAssetUrl } from './publicAssetUrl.js';

/** 探索画面の背景（層 => public/assets/bg/ のファイル名）。ここにない層は 1 層と同じ */
const FLOOR_BACKGROUNDS = {
  1: 'dungeon1.webp',
  2: '2nd_floor.webp',
};

/** 探索中の BGM（層 => useBattleAudio.js の BGM_TRACKS のキー）。ここにない層は 1 層と同じ */
const FLOOR_BGM_TRACKS = {
  1: 'dungeon',
  2: 'dungeon_floor2',
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
