import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../api';

export const usePlayerStore = defineStore('player', () => {
  const gold = ref(0);
  const items = ref([]);
  const inDungeon = ref(false);
  /** 探索中の層（探索画面の背景・BGM の切り替えに使う） */
  const dungeonFloor = ref(1);
  const activeBattleId = ref(null);
  const battleFromDungeon = ref(false);
  const navigationLoaded = ref(false);

  function setGold(value) {
    gold.value = Math.max(0, Number(value) || 0);
  }

  function setItems(value) {
    items.value = Array.isArray(value) ? value : [];
  }

  function setDungeonFloor(value) {
    dungeonFloor.value = Math.max(1, Number(value) || 1);
  }

  function setNavigation(data) {
    inDungeon.value = Boolean(data?.in_dungeon);
    setDungeonFloor(data?.dungeon_floor);
    activeBattleId.value = data?.active_battle_id ?? null;
    battleFromDungeon.value = Boolean(data?.battle_from_dungeon);
    navigationLoaded.value = true;
  }

  async function fetchParty() {
    const data = await api('/player/party', { method: 'GET' });
    if (data.gold != null) setGold(data.gold);
    if (data.items != null) setItems(data.items);
    return data;
  }

  async function fetchGold() {
    const data = await fetchParty();
    return gold.value;
  }

  async function fetchNavigation() {
    const data = await api('/player/navigation', { method: 'GET' });
    setNavigation(data);
    return data;
  }

  return {
    gold,
    items,
    inDungeon,
    dungeonFloor,
    activeBattleId,
    battleFromDungeon,
    navigationLoaded,
    setGold,
    setItems,
    setDungeonFloor,
    setNavigation,
    fetchParty,
    fetchGold,
    fetchNavigation,
  };
});
