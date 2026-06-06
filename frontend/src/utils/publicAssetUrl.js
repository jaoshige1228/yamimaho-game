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
