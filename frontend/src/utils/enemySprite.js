/**
 * 敵ユニットの画像ファイル名（拡張子なし）を返す。
 * 表示名（コーモリ A 等）ではなくマスタの sprite / code を使う。
 */
export function enemySpriteAssetKey(unit) {
  const sprite = unit?.sprite ?? '';
  if (/^[a-z][a-z0-9_]*$/i.test(sprite)) {
    return sprite;
  }

  const code = unit?.master_code ?? '';
  if (/^[a-z][a-z0-9_]*$/i.test(code)) {
    return code;
  }

  return 'unknown';
}
