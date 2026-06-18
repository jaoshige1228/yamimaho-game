<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import ScenePanel from '../components/exploration/ScenePanel.vue';
import HubPartyFooter from '../components/hub/HubPartyFooter.vue';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';
import '../styles/exploration.css';
import { publicAssetUrl } from '../utils/publicAssetUrl.js';

const hubBgSrc = publicAssetUrl('/assets/bg/home.jpg');

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const enteringFloor = ref(null);
const error = ref('');
const party = ref([]);
const unlockedFloor = ref(1);
const playableFloor = ref(1);

const enterableFloors = computed(() => {
  const max = Math.min(unlockedFloor.value, playableFloor.value);
  return Array.from({ length: max }, (_, index) => index + 1);
});

async function loadHubData() {
  loading.value = true;
  error.value = '';
  try {
    const [partyData, dungeonData] = await Promise.all([
      api('/player/party', { method: 'GET' }),
      api('/dungeon', { method: 'GET' }),
    ]);
    party.value = partyData.characters ?? [];
    if (partyData.gold != null) player.setGold(partyData.gold);
    if (partyData.items != null) player.setItems(partyData.items);
    unlockedFloor.value = dungeonData.unlocked_floor ?? 1;
    playableFloor.value = dungeonData.playable_floor ?? 1;
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

onMounted(() => loadHubData());
</script>

<template>
  <div class="hub-screen">
    <div class="exploration-body">
      <ScenePanel :image-src="hubBgSrc" :loading="loading">
        <div v-if="!loading" class="hub-actions">
          <button
            v-for="floor in enterableFloors"
            :key="floor"
            type="button"
            class="hub-enter-btn"
            :disabled="enteringFloor !== null"
            @click="enterDungeon(floor)"
          >
            {{ enteringFloor === floor ? '入場中…' : `${floor}層に潜る` }}
          </button>
          <button type="button" class="hub-secondary-btn" @click="router.push({ name: 'shop' })">
            武具屋へ
          </button>
          <button type="button" class="hub-secondary-btn" @click="router.push({ name: 'equip' })">
            装備を整える
          </button>
          <button type="button" class="hub-secondary-btn" @click="router.push({ name: 'items' })">
            持ち物を見る
          </button>
        </div>
        <p v-if="error" class="scene-error">{{ error }}</p>
      </ScenePanel>
    </div>

    <HubPartyFooter v-if="party.length" :units="party" />
  </div>
</template>

<style scoped>
.hub-actions {
  position: absolute;
  left: 50%;
  bottom: 1rem;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  align-items: stretch;
  width: min(240px, 72%);
  z-index: 3;
}
.hub-enter-btn {
  min-width: 88px;
  min-height: 44px;
  padding: 0.55rem 1.35rem;
  border: 2px solid rgba(255, 255, 255, 0.9);
  border-radius: 999px;
  background: rgba(12, 8, 24, 0.55);
  color: #fff;
  font-size: 1rem;
  font-weight: 800;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.45);
  touch-action: manipulation;
}
.hub-enter-btn:disabled {
  opacity: 0.55;
  cursor: default;
  pointer-events: none;
}
.hub-secondary-btn {
  border: 1px solid rgba(200, 176, 255, 0.45);
  background: rgba(20, 10, 40, 0.72);
  color: #f8f4ff;
  border-radius: 999px;
  padding: 0.55rem 1rem;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}
.hub-secondary-btn:hover {
  background: rgba(40, 24, 72, 0.88);
}
</style>
