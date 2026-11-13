import { characterSpriteUrls, publicAssetUrl } from './publicAssetUrl.js';

/**
 * 敵ユニットの画像ファイル名（拡張子なし）を返す。
 * 表示名（コーモリ A 等）ではなくマスタの sprite / code を使う。
 */
export function enemySpriteAssetKey(unit) {
  const sprite = unit?.sprite ?? '';
  if (/^[a-z0-9][a-z0-9_]*$/i.test(sprite)) {
    return sprite;
  }

  const code = unit?.master_code ?? '';
  if (/^[a-z0-9][a-z0-9_]*$/i.test(code)) {
    return code;
  }

  return 'unknown';
}

/**
 * 敵の画像の URL。
 * - npc_（グース／カース）… characters/ の WebP
 * - rift_（空間の裂け目の 4 人の魔法使い）… enemies/ の WebP
 * - それ以外 … enemies/ の PNG
 */
export function enemySpriteUrl(unit) {
  const key = enemySpriteAssetKey(unit);
  if (key.startsWith('npc_')) {
    return characterSpriteUrls(key).webp;
  }
  if (key.startsWith('rift_')) {
    return publicAssetUrl(`/assets/enemies/${key}.webp`);
  }
  return publicAssetUrl(`/assets/enemies/${key}.png`);
}
