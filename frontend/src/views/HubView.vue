<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import ScenePanel from '../components/exploration/ScenePanel.vue';
import HubPartyFooter from '../components/hub/HubPartyFooter.vue';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';
import '../styles/exploration.css';
import { publicAssetUrl } from '../utils/publicAssetUrl.js';

const hubBgSrc = publicAssetUrl('/assets/bg/home.webp');

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const error = ref('');
const party = ref([]);

async function loadHubData() {
  loading.value = true;
  error.value = '';
  try {
    const partyData = await api('/player/party', { method: 'GET' });
    party.value = partyData.characters ?? [];
    if (partyData.gold != null) player.setGold(partyData.gold);
    if (partyData.items != null) player.setItems(partyData.items);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

onMounted(() => loadHubData());
</script>

<template>
  <div class="hub-screen">
    <div class="exploration-body">
      <ScenePanel :image-src="hubBgSrc" :loading="loading">
        <div v-if="!loading" class="hub-actions">
          <button type="button" class="hub-enter-btn" @click="router.push({ name: 'dungeon-enter' })">
            ダンジョンに潜る
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
