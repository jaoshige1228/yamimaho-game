<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const enteringFloor = ref(null);
const error = ref('');
const unlockedFloor = ref(1);
const playableFloor = ref(1);

const enterableFloors = computed(() => {
  const max = Math.min(unlockedFloor.value, playableFloor.value);
  return Array.from({ length: max }, (_, index) => index + 1);
});

async function loadDungeonOptions() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/dungeon', { method: 'GET' });
    unlockedFloor.value = data.unlocked_floor ?? 1;
    playableFloor.value = data.playable_floor ?? 1;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function enterDungeon(floor) {
  enteringFloor.value = floor;
  error.value = '';
  try {
    await api('/dungeon/enter', {
      method: 'POST',
      body: JSON.stringify({ floor }),
    });
    await player.fetchNavigation();
    router.push({ name: 'dungeon' });
  } catch (e) {
    error.value = e.message;
  } finally {
    enteringFloor.value = null;
  }
}

onMounted(() => loadDungeonOptions());
</script>

<template>
  <div class="hub-screen dungeon-enter-screen">
    <header class="sub-screen-header">
      <button type="button" class="hub-screen-back" @click="router.push({ name: 'hub' })">←</button>
      <h1>ダンジョンに潜る</h1>
    </header>

    <main class="dungeon-enter-main">
      <p v-if="loading" class="hub-screen-muted">読み込み中…</p>
      <p v-else-if="error" class="hub-screen-error">{{ error }}</p>
      <div v-else class="floor-actions">
        <button
          v-for="floor in enterableFloors"
          :key="floor"
          type="button"
          class="floor-enter-btn"
          :disabled="enteringFloor !== null"
          @click="enterDungeon(floor)"
        >
          {{ enteringFloor === floor ? '入場中…' : `${floor}層に潜る` }}
        </button>
        <p v-if="unlockedFloor < playableFloor" class="floor-hint">
          {{ unlockedFloor + 1 }}層以降は、前の層のボスを倒すと解放されます。
        </p>
      </div>
    </main>
  </div>
</template>

<style scoped>
.dungeon-enter-screen {
  overflow-y: auto;
  padding-bottom: calc(72px + env(safe-area-inset-bottom));
}
.sub-screen-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem 1rem 0.5rem;
  padding-top: calc(1rem + env(safe-area-inset-top));
}
.sub-screen-header h1 {
  margin: 0;
  font-size: 1.1rem;
}
.dungeon-enter-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1rem 1rem;
}
.floor-actions {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  width: min(280px, 100%);
}
.floor-enter-btn {
  min-height: 48px;
  padding: 0.65rem 1.25rem;
  border: 2px solid rgba(255, 255, 255, 0.9);
  border-radius: 999px;
  background: rgba(12, 8, 24, 0.72);
  color: #fff;
  font-size: 1rem;
  font-weight: 800;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
}
.floor-enter-btn:disabled {
  opacity: 0.55;
  cursor: default;
}
.floor-hint {
  margin: 0.75rem 0 0;
  font-size: 0.72rem;
  line-height: 1.5;
  color: #8a7aa8;
  text-align: center;
}
</style>
