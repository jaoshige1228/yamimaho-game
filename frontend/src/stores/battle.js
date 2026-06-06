import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../api';

export const useBattleStore = defineStore('battle', () => {
  const battleId = ref(null);
  const state = ref(null);
  const pendingEvents = ref([]);
  const error = ref('');

  async function startDemo() {
    const data = await api('/battles/demo', { method: 'POST' });
    battleId.value = data.battle_id;
    state.value = data.state;
    pendingEvents.value = data.events || [];
    error.value = '';
  }

  async function startDemoBoss() {
    const data = await api('/battles/demo-boss', { method: 'POST' });
    battleId.value = data.battle_id;
    state.value = data.state;
    pendingEvents.value = data.events || [];
    error.value = '';
  }

  async function load(id) {
    const data = await api(`/battles/${id}`, { method: 'GET' });
    battleId.value = data.battle_id;
    state.value = data.state;
    pendingEvents.value = [];
  }

  async function submitAction(payload) {
    if (!battleId.value) return null;
    error.value = '';
    const data = await api(`/battles/${battleId.value}/actions`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    state.value = data.state;
    pendingEvents.value = data.events || [];
    return data;
  }

  function dequeueEvents() {
    const events = [...pendingEvents.value];
    pendingEvents.value = [];
    return events;
  }

  return {
    battleId,
    state,
    pendingEvents,
    error,
    startDemo,
    startDemoBoss,
    load,
    submitAction,
    dequeueEvents,
  };
});
