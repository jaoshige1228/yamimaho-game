import { watch } from 'vue';
import { useRoute } from 'vue-router';
import { useBattleStore } from '../stores/battle';
import { useAppAudio } from './useAppAudio';

function resolveBgmTrack(route, battleState) {
  const name = route.name;
  if (name === 'hub') {
    return 'home';
  }
  if (name === 'dungeon') {
    return 'dungeon';
  }
  if (name === 'battle') {
    const isBoss =
      route.query.boss === '1' || battleState?.meta?.boss === true;
    return isBoss ? 'boss' : 'battle';
  }
  return null;
}

/** App.vue で1回だけ呼ぶ: ルートに応じて BGM を切り替える */
export function useRouteBgm() {
  const route = useRoute();
  const battle = useBattleStore();
  const { switchBgm, fadeOutBgm, settings, unlock } = useAppAudio();

  const applyRouteBgm = async ({ restart = false } = {}) => {
    const track = resolveBgmTrack(route, battle.state);
    if (!track) {
      fadeOutBgm();
      return;
    }
    if (settings.value.bgmEnabled) {
      unlock();
      await switchBgm(track, { restart });
    } else {
      await switchBgm(track, { restart: false });
    }
  };

  watch(
    () => [
      route.name,
      route.query.boss,
      battle.state?.meta?.boss,
      settings.value.bgmEnabled,
    ],
    () => {
      applyRouteBgm({ restart: false });
    },
    { immediate: true },
  );

  return { applyRouteBgm };
}
