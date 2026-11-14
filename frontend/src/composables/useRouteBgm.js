import { watch } from 'vue';
import { useRoute } from 'vue-router';
import { useBattleStore } from '../stores/battle';
import { usePlayerStore } from '../stores/player';
import { battleBgmTrack, dungeonBgmTrack } from '../utils/dungeonTheme.js';
import { useAppAudio } from './useAppAudio';

/** 戦闘中の BGM。ボス・中ボスは URL のクエリでも判定する（戦闘 state を読み込む前でも切り替えられるように） */
function battleTrackFor(route, battleState) {
  return battleBgmTrack({
    floor: battleState?.meta?.floor,
    boss: route.query.boss === '1' || battleState?.meta?.boss === true,
    midBoss: route.query.mid_boss === '1' || battleState?.meta?.mid_boss === true,
    bgm: battleState?.meta?.bgm,
  });
}

function resolveBgmTrack(route, battleState, player) {
  const name = route.name;
  // タイトル画面（'/'）の BGM。空間の裂け目のボス戦と同じ曲
  if (name === 'home') {
    return 'last_boss';
  }
  if (name === 'hub' || name === 'shop' || name === 'equip' || name === 'items' || name === 'familiar' || name === 'dungeon-enter') {
    return 'home';
  }
  if (name === 'dungeon') {
    return dungeonBgmTrack(player.dungeonFloor);
  }
  if (name === 'status') {
    if (player.activeBattleId) {
      return battleTrackFor(route, battleState);
    }
    return player.inDungeon ? dungeonBgmTrack(player.dungeonFloor) : 'home';
  }
  if (name === 'battle') {
    return battleTrackFor(route, battleState);
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
      route.query.mid_boss,
      battle.state?.meta?.boss,
      battle.state?.meta?.mid_boss,
      battle.state?.meta?.bgm,
      battle.state?.meta?.floor,
      settings.value.bgmEnabled,
      player.inDungeon,
      player.dungeonFloor,
      player.activeBattleId,
    ],
    () => {
      applyRouteBgm({ restart: false });
    },
    { immediate: true },
  );

  return { applyRouteBgm };
}
