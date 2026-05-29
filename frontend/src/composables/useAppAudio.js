import { inject } from 'vue';
import { useBattleAudio } from './useBattleAudio';

export const APP_AUDIO_KEY = Symbol('appAudio');

let sharedAudio = null;

/** App.vue で1回だけ呼び、provide する */
export function createAppAudio() {
  if (!sharedAudio) {
    sharedAudio = useBattleAudio({ persistent: true });
  }
  return sharedAudio;
}

export function useAppAudio() {
  const injected = inject(APP_AUDIO_KEY, null);
  if (injected) {
    return injected;
  }
  return useBattleAudio({ persistent: true });
}
