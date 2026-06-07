import { onBeforeUnmount, ref } from 'vue';
import { publicAssetUrl } from '../utils/publicAssetUrl.js';

const BGM_TRACKS = {
  home: publicAssetUrl('/assets/audio/bgm/home.mp3'),
  dungeon: publicAssetUrl('/assets/audio/bgm/dungeon.mp3'),
  battle: publicAssetUrl('/assets/audio/bgm/battle.mp3'),
  boss: publicAssetUrl('/assets/audio/bgm/boss_battle_2.mp3'),
};

const SE_TRACKS = {
  cursor: publicAssetUrl('/assets/audio/se/cursor.mp3'),
  attack_before: publicAssetUrl('/assets/audio/se/attack_before.mp3'),
  magic: publicAssetUrl('/assets/audio/se/magic.mp3'),
  damage: publicAssetUrl('/assets/audio/se/damage.mp3'),
  miss: publicAssetUrl('/assets/audio/se/miss.mp3'),
  success: publicAssetUrl('/assets/audio/se/success.mp3'),
  heal: publicAssetUrl('/assets/audio/se/heal.mp3'),
};

const STORAGE_KEY = 'yamimaho-audio';
const SE_POOL_SIZE = 4;

const DEFAULT_SETTINGS = {
  bgmEnabled: false,
  bgmVolume: 0.25,
  seEnabled: true,
};

/** 音源マスター（スライダー倍率とは別） */
const BGM_MASTER_GAIN = 0.25;
const SE_MASTER_GAIN = 1.45;
/** cursor のみ追加ブースト */
const SE_KEY_GAIN = {
  cursor: 1.5,
};

const settings = ref(loadSettings());
const unlocked = ref(false);
const currentBgmTrack = ref(null);

let bgm = null;
/** @type {Array<{ audio: HTMLAudioElement, seKey: string | null, playing: boolean }>} */
let sePool = [];
let fadeTimer = null;
let mountCount = 0;

function loadSettings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      const volume = Number(parsed.bgmVolume);
      return {
        ...DEFAULT_SETTINGS,
        bgmEnabled: typeof parsed.bgmEnabled === 'boolean' ? parsed.bgmEnabled : DEFAULT_SETTINGS.bgmEnabled,
        seEnabled: typeof parsed.seEnabled === 'boolean' ? parsed.seEnabled : DEFAULT_SETTINGS.seEnabled,
        bgmVolume: Number.isFinite(volume) ? Math.min(1, Math.max(0, volume)) : DEFAULT_SETTINGS.bgmVolume,
      };
    }
  } catch {
    /* ignore */
  }
  return { ...DEFAULT_SETTINGS };
}

function persistSettings() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(settings.value));
}

function effectiveBgmVolume() {
  if (!settings.value.bgmEnabled) {
    return 0;
  }

  return Math.min(1, settings.value.bgmVolume * BGM_MASTER_GAIN);
}

function effectiveSeVolume(seKey) {
  if (!settings.value.seEnabled) {
    return 0;
  }

  const gain = seKey && SE_KEY_GAIN[seKey] ? SE_KEY_GAIN[seKey] : SE_MASTER_GAIN;

  return Math.min(1, settings.value.bgmVolume * gain);
}

function ensureBgm() {
  if (!bgm) {
    bgm = new Audio();
    bgm.loop = true;
    bgm.preload = 'auto';
  }
  applyVolumes();
}

function ensureSePool() {
  if (sePool.length >= SE_POOL_SIZE) {
    return;
  }

  while (sePool.length < SE_POOL_SIZE) {
    const audio = new Audio();
    audio.preload = 'auto';
    audio.addEventListener('ended', () => {
      const slot = sePool.find((entry) => entry.audio === audio);
      if (slot) {
        slot.playing = false;
        slot.seKey = null;
      }
    });
    sePool.push({ audio, seKey: null, playing: false });
  }
}

function preloadSePool() {
  ensureSePool();
  const urls = Object.values(SE_TRACKS);
  sePool.forEach((slot, index) => {
    const url = urls[index % urls.length];
    if (url && slot.audio.src !== url) {
      slot.audio.src = url;
      slot.audio.load();
    }
  });
}

function applyVolumes() {
  if (bgm) {
    bgm.volume = effectiveBgmVolume();
  }
  sePool.forEach(({ audio, seKey, playing }) => {
    if (playing && seKey) {
      audio.volume = effectiveSeVolume(seKey);
    }
  });
}

function stopAllSe() {
  sePool.forEach((slot) => {
    slot.audio.pause();
    slot.audio.currentTime = 0;
    slot.playing = false;
    slot.seKey = null;
  });
}

function findSeSlot() {
  const idle = sePool.find((slot) => !slot.playing || slot.audio.paused || slot.audio.ended);
  if (idle) {
    return idle;
  }
  return sePool.reduce((oldest, slot) => (
    slot.audio.currentTime >= oldest.audio.currentTime ? slot : oldest
  ));
}

function isBgmPaused() {
  return !bgm || bgm.paused;
}

function unlock() {
  ensureBgm();
  ensureSePool();
  preloadSePool();
  unlocked.value = true;
}

function cancelFadeOut() {
  if (fadeTimer) {
    clearInterval(fadeTimer);
    fadeTimer = null;
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

function playSe(seKey) {
  if (!unlocked.value || !settings.value.seEnabled) {
    return;
  }

  const url = SE_TRACKS[seKey];
  if (!url) {
    return;
  }

  ensureSePool();
  const slot = findSeSlot();
  const { audio } = slot;

  if (slot.seKey !== seKey || audio.src !== url) {
    audio.pause();
    audio.src = url;
    slot.seKey = seKey;
  } else {
    audio.pause();
    audio.currentTime = 0;
  }

  slot.playing = true;
  audio.volume = effectiveSeVolume(seKey);
  audio.play().catch(() => {
    slot.playing = false;
    slot.seKey = null;
  });
}

function fadeOutBgm(ms = 800) {
  if (!bgm) return;
  cancelFadeOut();

  const start = bgm.volume;
  const steps = 16;
  let i = 0;
  fadeTimer = setInterval(() => {
    i += 1;
    bgm.volume = Math.max(0, start * (1 - i / steps));
    if (i >= steps) {
      clearInterval(fadeTimer);
      fadeTimer = null;
      bgm.pause();
      applyVolumes();
    }
  }, ms / steps);
}

export function useBattleAudio({ persistent = false } = {}) {
  mountCount += 1;

  onBeforeUnmount(() => {
    mountCount -= 1;
    if (mountCount <= 0) {
      mountCount = 0;
      if (fadeTimer) {
        clearInterval(fadeTimer);
        fadeTimer = null;
      }
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
    playSe,
    fadeOutBgm,
    isBgmPaused,
  };
}
