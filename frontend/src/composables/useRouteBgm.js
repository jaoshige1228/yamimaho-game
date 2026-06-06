import { watch } from 'vue';
import { useRoute } from 'vue-router';
import { useBattleStore } from '../stores/battle';
import { usePlayerStore } from '../stores/player';
import { useAppAudio } from './useAppAudio';

function resolveBgmTrack(route, battleState, player) {
  const name = route.name;
  if (name === 'hub' || name === 'shop' || name === 'equip' || name === 'items') {
    return 'home';
  }
  if (name === 'dungeon') {
    return 'dungeon';
  }
  if (name === 'status') {
    if (player.activeBattleId) {
      const isBoss =
        route.query.boss === '1' || battleState?.meta?.boss === true;
      return isBoss ? 'boss' : 'battle';
    }
    return player.inDungeon ? 'dungeon' : 'home';
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
  const player = usePlayerStore();
  const { switchBgm, fadeOutBgm, settings, unlock } = useAppAudio();

  const applyRouteBgm = async ({ restart = false } = {}) => {
    const track = resolveBgmTrack(route, battle.state, player);
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
      player.inDungeon,
      player.activeBattleId,
    ],
    () => {
      applyRouteBgm({ restart: false });
    },
    { immediate: true },
  );

  return { applyRouteBgm };
}
