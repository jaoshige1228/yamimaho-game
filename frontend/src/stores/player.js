import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../api';

export const usePlayerStore = defineStore('player', () => {
  const gold = ref(0);
  const items = ref([]);
  const inDungeon = ref(false);
  const activeBattleId = ref(null);
  const battleFromDungeon = ref(false);
  const navigationLoaded = ref(false);

  function setGold(value) {
    gold.value = Math.max(0, Number(value) || 0);
  }

  function setItems(value) {
    items.value = Array.isArray(value) ? value : [];
  }

  function setNavigation(data) {
    inDungeon.value = Boolean(data?.in_dungeon);
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
    activeBattleId,
    battleFromDungeon,
    navigationLoaded,
    setGold,
    setItems,
    setNavigation,
    fetchParty,
    fetchGold,
    fetchNavigation,
  };
});
