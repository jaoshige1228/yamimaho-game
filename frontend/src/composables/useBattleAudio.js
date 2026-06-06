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
};

const STORAGE_KEY = 'yamimaho-audio';

const DEFAULT_SETTINGS = {
  bgmEnabled: false,
  bgmVolume: 0.25,
};

/** 音源マスター（スライダー倍率とは別） */
const BGM_MASTER_GAIN = 0.45;
const SE_MASTER_GAIN = 1.45;
/** cursor のみ追加ブースト */
const SE_KEY_GAIN = {
  cursor: 1.5,
};

const settings = ref(loadSettings());
const unlocked = ref(false);
const currentBgmTrack = ref(null);

let bgm = null;
let activeSe = null;
let activeSeKey = null;
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

function effectiveSeVolume(seKey = activeSeKey) {
  if (!settings.value.bgmEnabled) {
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

function ensureActiveSe() {
  if (!activeSe) {
    activeSe = new Audio();
    activeSe.preload = 'auto';
  }
}

function applyVolumes() {
  if (bgm) {
    bgm.volume = effectiveBgmVolume();
  }
  if (activeSe && !activeSe.paused) {
    activeSe.volume = effectiveSeVolume();
  }
}

function stopActiveSe() {
  if (!activeSe) {
    return;
  }
  activeSe.pause();
  activeSe.currentTime = 0;
  activeSeKey = null;
}

function isBgmPaused() {
  return !bgm || bgm.paused;
}

function preloadSeTracks() {
  Object.entries(SE_TRACKS).forEach(([key, url]) => {
    const clip = new Audio(url);
    clip.preload = 'auto';
    clip.load();
    if (key === 'cursor' && activeSe) {
      activeSe.src = url;
      activeSeKey = 'cursor';
    }
  });
}

function unlock() {
  ensureBgm();
  ensureActiveSe();
  preloadSeTracks();
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
    stopActiveSe();
    return;
  }

  if (currentBgmTrack.value) {
    switchBgm(currentBgmTrack.value, { restart: false });
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
  if (!unlocked.value || !settings.value.bgmEnabled) {
    return;
  }

  const url = SE_TRACKS[seKey];
  if (!url) {
    return;
  }

  ensureActiveSe();

  if (activeSeKey !== seKey) {
    activeSe.pause();
    activeSe.src = url;
    activeSeKey = seKey;
  } else {
    activeSe.pause();
    activeSe.currentTime = 0;
  }

  activeSe.volume = effectiveSeVolume(seKey);
  activeSe.play().catch(() => {});
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
      stopActiveSe();
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
    setBgmVolume,
    playSe,
    fadeOutBgm,
    isBgmPaused,
  };
}
