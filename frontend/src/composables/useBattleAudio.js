import { onBeforeUnmount, ref } from 'vue';
import { publicAssetUrl } from '../utils/publicAssetUrl.js';

const BGM_TRACKS = {
  home: publicAssetUrl('/assets/audio/bgm/home.mp3'),
  dungeon: publicAssetUrl('/assets/audio/bgm/Dim2.mp3'),
  dungeon_floor2: publicAssetUrl('/assets/audio/bgm/dungeon.mp3'),
  dungeon_floor3: publicAssetUrl('/assets/audio/bgm/kaisyoku.mp3'),
  battle: publicAssetUrl('/assets/audio/bgm/RetroRoman_Battle3.mp3'),
  boss: publicAssetUrl('/assets/audio/bgm/boss_battle_2.mp3'),
  broken: publicAssetUrl('/assets/audio/bgm/broken.mp3'),
  // 空間の裂け目のボス戦（戦闘の meta.bgm = 'last_boss'）
  last_boss: publicAssetUrl('/assets/audio/bgm/last_boss.mp3'),
  battle_floor3: publicAssetUrl('/assets/audio/bgm/Lapislazuli.mp3'),
  boss_floor3: publicAssetUrl('/assets/audio/bgm/carnation.mp3'),
};

const SE_TRACKS = {
  cursor: publicAssetUrl('/assets/audio/se/cursor.mp3'),
  attack_before: publicAssetUrl('/assets/audio/se/attack_before.mp3'),
  magic: publicAssetUrl('/assets/audio/se/magic.mp3'),
  // 空間の裂け目のボス戦で、敵が魔法を唱えるときに鳴らす（PC の詠唱は magic のまま）
  enemy_magic: publicAssetUrl('/assets/audio/se/enemy_magic.mp3'),
  damage: publicAssetUrl('/assets/audio/se/damage.mp3'),
  miss: publicAssetUrl('/assets/audio/se/miss.mp3'),
  success: publicAssetUrl('/assets/audio/se/success.mp3'),
  // PC にデバフ（防御ダウンなど）が入ったときに鳴らす
  debahu: publicAssetUrl('/assets/audio/se/debahu.mp3'),
  heal: publicAssetUrl('/assets/audio/se/heal.mp3'),
  tukaima: publicAssetUrl('/assets/audio/se/tukaima.mp3'),
};

const STORAGE_KEY = 'yamimaho-audio';

const DEFAULT_SETTINGS = {
  bgmEnabled: false,
  bgmVolume: 0.25,
  seEnabled: true,
  seVolume: 0.3,
};

/*
 * 音量は Web Audio API の GainNode で掛ける。
 * iOS Safari は HTMLAudioElement.volume を無視する（常に 1.0）ため、audio.volume では端末ごとに音量が変わってしまう。
 *
 *   BGM … <audio>（ストリーミング）→ bgmGain → 出力
 *   SE  … AudioBuffer → 音源ごとの補正ゲイン → seGain → 出力
 */

/** BGM は音源が大きい（約 -10 LUFS）ので、スライダー値にこの倍率を掛ける */
const BGM_MASTER_GAIN = 0.10;

/**
 * 曲ごとの音量の追加補正（dB。BGM_TRACKS のキー）。ここにない曲は補正なし（0 dB）。
 * 6 dB で約 2 倍の音量。裂け目のボス戦（last_boss）は、少し小さかったので +3 dB（約 1.41 倍）。
 * BGM は BGM_MASTER_GAIN で大きく絞っているので、数 dB 上げても割れない。
 */
const BGM_TRACK_BOOST_DB = {
  last_boss: 3,
};

/**
 * SE の音源ごとの実測ラウドネス（LUFS）と真のピーク（dBTP）。
 * 計測: ffmpeg -i <file> -af loudnorm=I=-23:TP=-1:print_format=json -f null -
 * （input_i / input_tp）。SE の音源を差し替えたらここも測り直す。
 */
const SE_SOURCE_LEVELS = {
  cursor: { lufs: -29.6, peak: -5.2 },
  attack_before: { lufs: -20.6, peak: -5.8 },
  magic: { lufs: -13.2, peak: -5.0 },
  enemy_magic: { lufs: -17.9, peak: -10.1 },
  damage: { lufs: -17.7, peak: -5.7 },
  miss: { lufs: -21.9, peak: -9.1 },
  success: { lufs: -16.4, peak: -6.4 },
  debahu: { lufs: -18.3, peak: -3.4 },
  tukaima: { lufs: -17.4, peak: -9.4 },
};
/** 音源ごとの音量差をこの値に揃える（SE スライダー 1.0 のときの音量） */
const SE_TARGET_LUFS = -20;
/** 補正後のピークがこの値を超えないようにする（割れ防止。cursor は小さい音源なので届かず少し小さめになる） */
const SE_PEAK_CEILING_DB = -1;
/** 同時に鳴らせる SE の数。超えたら古いものから止める */
const SE_MAX_VOICES = 8;

/**
 * SE ごとの追加の音量補正（dB。SE_TRACKS のキー）。ここにない SE は、SE_TARGET_LUFS に揃えるだけ（0 dB）。
 * 6 dB で約 2 倍の音量。
 *  - enemy_magic（裂け目の敵の魔法）… +6 dB
 *  - debahu（PC にデバフが付いたとき）… +10 dB。この音源は 200Hz 未満の低音がほとんど（200Hz 以上は全体より 20dB 以上小さい）で、
 *    ノート PC やスマホのスピーカーでは低音が出ず、数値（ラウドネス）は同じでも、ほかの SE より 10〜18 dB 小さく聞こえていた。
 *    ピークも大きく、-1 dB の天井だと +4 dB 強しか上げられないので、追加補正のある SE は、天井ではなくソフトリミッターで割れを防ぐ
 *    （リミッターで低音が少し歪んで倍音が出るぶん、小さいスピーカーでも聞こえやすくなる）
 */
const SE_BOOST_DB = {
  enemy_magic: 6,
  debahu: 10,
};

/** ソフトリミッター: この振幅（フルスケールの 0.5 = -6 dBFS）までは音をそのまま通し、これを超えた分をなめらかに 1.0 に収める */
const SE_SOFT_LIMIT_KNEE = 0.5;
/** ソフトリミッターが受け止められる入力の大きさ（フルスケールの何倍まで。4 倍 = +12 dB。超えた分は 1.0 で頭打ち） */
const SE_SOFT_LIMIT_RANGE = 4;

/**
 * 音源ごとの補正ゲイン（リニア）。測定値のない SE は補正なし。
 * 追加補正（SE_BOOST_DB）のない SE は、補正後のピークが SE_PEAK_CEILING_DB を超えないようにする。
 * 追加補正のある SE は、その天井は使わず、ソフトリミッター（createSeSoftLimiterCurve）を通す。
 */
export const SE_NORMALIZE_GAIN = Object.fromEntries(
  Object.entries(SE_SOURCE_LEVELS).map(([seKey, level]) => {
    const boostDb = SE_BOOST_DB[seKey] ?? 0;
    const wantedDb = SE_TARGET_LUFS + boostDb - level.lufs;
    const maxDb = SE_PEAK_CEILING_DB - level.peak;
    return [seKey, 10 ** ((boostDb > 0 ? wantedDb : Math.min(wantedDb, maxDb)) / 20)];
  }),
);

/** 追加補正のある SE かどうか（ソフトリミッターを通す） */
const isBoostedSe = (seKey) => (SE_BOOST_DB[seKey] ?? 0) > 0;

/**
 * ソフトリミッターの波形整形の曲線（WaveShaperNode 用）。入力は ±SE_SOFT_LIMIT_RANGE 倍を ±1 に縮めて受ける。
 * 振幅が SE_SOFT_LIMIT_KNEE 以下はそのまま、超えた分は tanh でなめらかに 1.0 に近づける（1.0 を超えない）。
 *
 * @param {number} [size]
 * @returns {Float32Array}
 */
export function createSeSoftLimiterCurve(size = 8192) {
  const curve = new Float32Array(size);
  const knee = SE_SOFT_LIMIT_KNEE;
  for (let i = 0; i < size; i += 1) {
    const x = (i / (size - 1)) * 2 - 1; // -1〜1
    const u = x * SE_SOFT_LIMIT_RANGE; // 縮める前の振幅
    const a = Math.abs(u);
    const y = a <= knee ? a : knee + (1 - knee) * Math.tanh((a - knee) / (1 - knee));
    curve[i] = Math.sign(u) * y;
  }
  return curve;
}

const settings = ref(loadSettings());
const unlocked = ref(false);
const currentBgmTrack = ref(null);

/** @type {AudioContext | null} */
let ctx = null;
/** @type {GainNode | null} */
let bgmGain = null;
/** @type {GainNode | null} */
let seGain = null;
/**
 * 追加補正のある SE がつなぐ入口（入力を 1/SE_SOFT_LIMIT_RANGE に縮めて、seSoftLimiter に渡す）
 * @type {GainNode | null}
 */
let seLimiterIn = null;
/** @type {WaveShaperNode | null} */
let seSoftLimiter = null;
/** @type {HTMLAudioElement | null} */
let bgm = null;
let bgmSource = null;
let fadeTimer = null;
let mountCount = 0;
let silentBlipPlayed = false;
let seDataPrefetched = false;

/** SE の元データ（fetch 結果）。404 は SE_MISSING で覚えておく */
const SE_MISSING = Symbol('se-missing');
const seData = new Map();
/** デコード済みの SE（key → Promise<AudioBuffer | null>） */
const seBuffers = new Map();
/** 再生中の SE */
const activeSe = new Set();

function loadSettings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      return {
        ...DEFAULT_SETTINGS,
        bgmEnabled: typeof parsed.bgmEnabled === 'boolean' ? parsed.bgmEnabled : DEFAULT_SETTINGS.bgmEnabled,
        seEnabled: typeof parsed.seEnabled === 'boolean' ? parsed.seEnabled : DEFAULT_SETTINGS.seEnabled,
        bgmVolume: normalizedVolume(parsed.bgmVolume, DEFAULT_SETTINGS.bgmVolume),
        seVolume: normalizedVolume(parsed.seVolume, DEFAULT_SETTINGS.seVolume),
      };
    }
  } catch {
    /* ignore */
  }
  return { ...DEFAULT_SETTINGS };
}

function normalizedVolume(value, fallback) {
  const volume = Number(value);
  return Number.isFinite(volume) ? Math.min(1, Math.max(0, volume)) : fallback;
}

function persistSettings() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings.value));
  } catch {
    /* ignore */
  }
}

function effectiveBgmGain() {
  if (!settings.value.bgmEnabled) {
    return 0;
  }

  const trackBoost = 10 ** ((BGM_TRACK_BOOST_DB[currentBgmTrack.value] ?? 0) / 20);

  return settings.value.bgmVolume * BGM_MASTER_GAIN * trackBoost;
}

function effectiveSeGain() {
  if (!settings.value.seEnabled) {
    return 0;
  }

  return settings.value.seVolume;
}

/** 設定の音量をゲインに反映する（ノイズが出ないよう短い時間でなめらかに変える） */
function setGainSmoothly(node, value) {
  const now = ctx.currentTime;
  node.gain.cancelScheduledValues(now);
  node.gain.setTargetAtTime(value, now, 0.02);
}

function applyVolumes() {
  if (ctx) {
    setGainSmoothly(bgmGain, effectiveBgmGain());
    setGainSmoothly(seGain, effectiveSeGain());
    return;
  }

  // Web Audio が使えない環境の保険（BGM のみ。iOS では効かない）
  if (bgm) {
    bgm.volume = Math.min(1, effectiveBgmGain());
  }
}

function enablePlaybackAudioSession() {
  // iOS 16.4+: マナーモード（サイレントスイッチ）でも鳴らす。Web Audio は何もしないとマナーモードで無音になる
  try {
    if (navigator.audioSession) {
      navigator.audioSession.type = 'playback';
    }
  } catch {
    /* ignore */
  }
}

function resumeContext() {
  if (!ctx || ctx.state === 'running') {
    return Promise.resolve();
  }

  // iOS では電話やバックグラウンド後に 'interrupted' になる。タップの中で呼べば復帰する
  return ctx.resume().catch(() => {});
}

function ensureContext() {
  if (ctx) {
    return ctx;
  }

  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextClass) {
    return null;
  }

  ctx = new AudioContextClass();

  bgmGain = ctx.createGain();
  bgmGain.gain.value = effectiveBgmGain();
  bgmGain.connect(ctx.destination);

  seGain = ctx.createGain();
  seGain.gain.value = effectiveSeGain();
  seGain.connect(ctx.destination);

  // 追加補正のある SE 用のソフトリミッター: seLimiterIn → seSoftLimiter → seGain（音量スライダーはリミッターのあとに掛かる）
  seLimiterIn = ctx.createGain();
  seLimiterIn.gain.value = 1 / SE_SOFT_LIMIT_RANGE;
  seSoftLimiter = ctx.createWaveShaper();
  seSoftLimiter.curve = createSeSoftLimiterCurve();
  seSoftLimiter.oversample = '4x';
  seLimiterIn.connect(seSoftLimiter);
  seSoftLimiter.connect(seGain);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      resumeContext();
    }
  });

  connectBgmToGraph();

  return ctx;
}

function connectBgmToGraph() {
  if (!ctx || !bgm || bgmSource) {
    return;
  }

  // 一度つなぐと <audio> の音は必ずこのグラフを通り、bgm.volume は使わない
  bgm.volume = 1;
  bgmSource = ctx.createMediaElementSource(bgm);
  bgmSource.connect(bgmGain);
}

function ensureBgm() {
  if (!bgm) {
    bgm = new Audio();
    bgm.loop = true;
    bgm.preload = 'auto';
  }
  ensureContext();
  connectBgmToGraph();
  applyVolumes();
}

/** iOS の古いバージョン向け: 最初のタップの中で無音を 1 回鳴らして、Web Audio を使えるようにする */
function playSilentBlip() {
  if (!ctx || silentBlipPlayed) {
    return;
  }

  silentBlipPlayed = true;
  const source = ctx.createBufferSource();
  source.buffer = ctx.createBuffer(1, 1, 22050);
  source.connect(ctx.destination);
  source.start(0);
}

function fetchSeData(seKey) {
  if (!seData.has(seKey)) {
    const promise = fetch(SE_TRACKS[seKey])
      .then((response) => (response.ok ? response.arrayBuffer() : SE_MISSING))
      .catch((error) => {
        // 通信エラーは覚えず、次に鳴らすときにやり直す
        seData.delete(seKey);
        throw error;
      });
    seData.set(seKey, promise);
  }

  return seData.get(seKey);
}

/** SE の元データだけ先に取得しておく（AudioContext はまだ要らない） */
function prefetchSeData() {
  if (seDataPrefetched) {
    return;
  }

  seDataPrefetched = true;
  Object.keys(SE_TRACKS).forEach((seKey) => {
    fetchSeData(seKey).catch(() => {});
  });
}

function decodeAudio(arrayBuffer) {
  return new Promise((resolve, reject) => {
    // 古い Safari は Promise 版が無くコールバック版のみ
    const decoding = ctx.decodeAudioData(arrayBuffer, resolve, reject);
    if (decoding && typeof decoding.then === 'function') {
      decoding.then(resolve, reject);
    }
  });
}

/** @returns {Promise<AudioBuffer | null>} 読み込めない SE（ファイルなし等）は null */
function loadSeBuffer(seKey) {
  if (!ctx) {
    return Promise.resolve(null);
  }

  if (!seBuffers.has(seKey)) {
    const promise = fetchSeData(seKey)
      // decodeAudioData は渡した ArrayBuffer を使い切るので、複製を渡す
      .then((data) => (data === SE_MISSING ? null : decodeAudio(data.slice(0))))
      .catch(() => {
        seBuffers.delete(seKey);
        return null;
      });
    seBuffers.set(seKey, promise);
  }

  return seBuffers.get(seKey);
}

function preloadSe() {
  prefetchSeData();
  Object.keys(SE_TRACKS).forEach((seKey) => {
    loadSeBuffer(seKey);
  });
}

function stopSeVoice(voice) {
  activeSe.delete(voice);
  try {
    voice.source.stop();
  } catch {
    /* 再生前・停止済み */
  }
}

function stopAllSe() {
  [...activeSe].forEach(stopSeVoice);
}

function startSe(seKey, buffer) {
  const source = ctx.createBufferSource();
  source.buffer = buffer;

  const keyGain = ctx.createGain();
  keyGain.gain.value = SE_NORMALIZE_GAIN[seKey] ?? 1;

  source.connect(keyGain);
  keyGain.connect(isBoostedSe(seKey) && seLimiterIn ? seLimiterIn : seGain);

  const voice = { source, keyGain };
  source.onended = () => {
    activeSe.delete(voice);
    source.disconnect();
    keyGain.disconnect();
  };

  activeSe.add(voice);
  while (activeSe.size > SE_MAX_VOICES) {
    stopSeVoice(activeSe.values().next().value);
  }

  source.start(0);
}

function isBgmPaused() {
  return !bgm || bgm.paused;
}

function unlock() {
  ensureBgm();
  if (ctx) {
    enablePlaybackAudioSession();
    resumeContext();
    playSilentBlip();
  }
  preloadSe();
  unlocked.value = true;
}

function cancelFadeOut() {
  if (fadeTimer) {
    clearTimeout(fadeTimer);
    fadeTimer = null;
  }
  if (ctx && bgmGain) {
    bgmGain.gain.cancelScheduledValues(ctx.currentTime);
  }
}

async function switchBgm(trackKey, { restart = false } = {}) {
  const url = BGM_TRACKS[trackKey];
  if (!url) {
    return false;
  }

  cancelFadeOut();
  ensureBgm();
  const previousTrack = currentBgmTrack.value;
  currentBgmTrack.value = trackKey;

  if (!settings.value.bgmEnabled) {
    bgm.src = url;
    return false;
  }

  const needsSrcChange = previousTrack !== trackKey || !bgm.src;
  if (needsSrcChange) {
    bgm.pause();
    bgm.src = url;
  }

  applyVolumes();
  if (restart || needsSrcChange) {
    bgm.currentTime = 0;
  }

  try {
    await bgm.play();
    return true;
  } catch {
    return false;
  }
}

async function playBgm({ restart = true, track = 'battle' } = {}) {
  return switchBgm(track, { restart });
}

async function resumeBgm() {
  if (!settings.value.bgmEnabled) return false;
  if (bgm && !bgm.paused && currentBgmTrack.value) return true;
  if (currentBgmTrack.value) {
    return switchBgm(currentBgmTrack.value, { restart: false });
  }
  return false;
}

async function unlockAndPlay(track = currentBgmTrack.value) {
  unlock();
  if (track) {
    return switchBgm(track, { restart: false });
  }
  return resumeBgm();
}

function setBgmEnabled(enabled) {
  settings.value.bgmEnabled = enabled;
  persistSettings();
  applyVolumes();

  if (!enabled) {
    if (bgm) {
      bgm.pause();
    }
    return;
  }

  if (currentBgmTrack.value) {
    switchBgm(currentBgmTrack.value, { restart: false });
  }
}

function setSeEnabled(enabled) {
  settings.value.seEnabled = enabled;
  persistSettings();
  applyVolumes();
  if (!enabled) {
    stopAllSe();
  }
}

function setBgmVolume(volume) {
  const next = Number(volume);
  if (!Number.isFinite(next)) return;
  settings.value.bgmVolume = Math.min(1, Math.max(0, next));
  persistSettings();
  applyVolumes();
}

function setSeVolume(volume) {
  const next = Number(volume);
  if (!Number.isFinite(next)) return;
  settings.value.seVolume = Math.min(1, Math.max(0, next));
  persistSettings();
  applyVolumes();
}

function playSe(seKey) {
  if (!unlocked.value || !settings.value.seEnabled || !SE_TRACKS[seKey]) {
    return;
  }

  const context = ensureContext();
  if (!context) {
    return;
  }

  // 読み込み済みなら待ちはほぼ発生しない。初回だけデコードが終わってから鳴る
  Promise.all([loadSeBuffer(seKey), resumeContext()]).then(([buffer]) => {
    if (!buffer || !settings.value.seEnabled || context.state !== 'running') {
      return;
    }
    startSe(seKey, buffer);
  });
}

function fadeOutBgm(ms = 800) {
  if (!bgm) return;
  cancelFadeOut();

  if (ctx) {
    const now = ctx.currentTime;
    bgmGain.gain.cancelScheduledValues(now);
    bgmGain.gain.setValueAtTime(bgmGain.gain.value, now);
    bgmGain.gain.linearRampToValueAtTime(0, now + ms / 1000);
  }

  fadeTimer = setTimeout(() => {
    fadeTimer = null;
    bgm.pause();
    applyVolumes();
  }, ms);
}

export function useBattleAudio({ persistent = false } = {}) {
  mountCount += 1;
  prefetchSeData();

  onBeforeUnmount(() => {
    mountCount -= 1;
    if (mountCount <= 0) {
      mountCount = 0;
      cancelFadeOut();
      if (!persistent && bgm) {
        bgm.pause();
      }
      stopAllSe();
    }
  });

  return {
    settings,
    unlocked,
    currentBgmTrack,
    unlock,
    switchBgm,
    playBgm,
    resumeBgm,
    unlockAndPlay,
    setBgmEnabled,
    setSeEnabled,
    setBgmVolume,
    setSeVolume,
    playSe,
    fadeOutBgm,
    isBgmPaused,
  };
}
