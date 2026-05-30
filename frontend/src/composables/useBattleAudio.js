import { onBeforeUnmount, ref } from 'vue';

const BGM_URL = '/assets/audio/bgm/battle.mp3';
const SE_URL = '/assets/audio/se/attack.mp3';
const STORAGE_KEY = 'yamimaho-audio';

const DEFAULT_SETTINGS = {
  bgmEnabled: false,
  bgmVolume: 0.25,
};

/** SE は BGM 音量スライダーに対する倍率（最大 1.0 にクランプ） */
const SE_BGM_RATIO = 1.2;

const settings = ref(loadSettings());
const unlocked = ref(false);

let bgm = null;
let se = null;
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

function ensureAudio() {
  if (!bgm) {
    bgm = new Audio(BGM_URL);
    bgm.loop = true;
    bgm.preload = 'auto';
  }
  if (!se) {
    se = new Audio(SE_URL);
    se.preload = 'auto';
  }
  applyVolumes();
}

function effectiveSeVolume() {
  if (!settings.value.bgmEnabled) {
    return 0;
  }

  return Math.min(1, settings.value.bgmVolume * SE_BGM_RATIO);
}

function applyVolumes() {
  if (bgm) {
    bgm.volume = settings.value.bgmEnabled ? settings.value.bgmVolume : 0;
  }
  if (se) {
    se.volume = effectiveSeVolume();
  }
}

function isBgmPaused() {
  return !bgm || bgm.paused;
}

function unlock() {
  ensureAudio();
  unlocked.value = true;
}

async function playBgm({ restart = true } = {}) {
  ensureAudio();
  if (!settings.value.bgmEnabled) return false;

  applyVolumes();
  if (restart) {
    bgm.currentTime = 0;
  }

  try {
    await bgm.play();
    return true;
  } catch {
    return false;
  }
}

async function resumeBgm() {
  if (!settings.value.bgmEnabled) return false;
  if (bgm && !bgm.paused) return true;
  return playBgm({ restart: false });
}

async function unlockAndPlay() {
  unlock();
  return resumeBgm();
}

function setBgmEnabled(enabled) {
  settings.value.bgmEnabled = enabled;
  persistSettings();
  applyVolumes();

  if (!enabled && bgm) {
    bgm.pause();
    return;
  }

  if (enabled) {
    resumeBgm();
  }
}

function setBgmVolume(volume) {
  const next = Number(volume);
  if (!Number.isFinite(next)) return;
  settings.value.bgmVolume = Math.min(1, Math.max(0, next));
  persistSettings();
  applyVolumes();
}

function playAttackSe({ requireBgm = true } = {}) {
  ensureAudio();
  if (!unlocked.value || !se) return;
  if (requireBgm && !settings.value.bgmEnabled) return;
  const clip = se.cloneNode();
  clip.volume = effectiveSeVolume();
  clip.play().catch(() => {});
}

function fadeOutBgm(ms = 800) {
  if (!bgm) return;
  if (fadeTimer) {
    clearInterval(fadeTimer);
    fadeTimer = null;
  }

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
    }
  });

  return {
    settings,
    unlocked,
    unlock,
    playBgm,
    resumeBgm,
    unlockAndPlay,
    setBgmEnabled,
    setBgmVolume,
    playAttackSe,
    fadeOutBgm,
    isBgmPaused,
  };
}
