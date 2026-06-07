/**
 * public/ 配下の静的ファイル URL（ファイル名にハッシュがないためキャッシュバスター付与）。
 * 本番ビルド時は VITE_PUBLIC_ASSET_VERSION（未設定ならビルド時刻）が ?v= に入る。
 */
const version = import.meta.env.VITE_PUBLIC_ASSET_VERSION ?? '';

export function publicAssetUrl(path) {
  const normalized = path.startsWith('/') ? path : `/${path}`;
  if (!version) {
    return normalized;
  }
  const separator = normalized.includes('?') ? '&' : '?';
  return `${normalized}${separator}v=${encodeURIComponent(version)}`;
}

/**
 * キャラクター立ち絵（WebP 優先、PNG フォールバック）。
 * @param {string} sprite 例: PC1
 */
export function characterSpriteUrls(sprite) {
  const base = `/assets/characters/${sprite}`;
  return {
    webp: publicAssetUrl(`${base}.webp`),
    png: publicAssetUrl(`${base}.png`),
  };
}
