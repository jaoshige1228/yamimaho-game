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

const hubBgSrc = publicAssetUrl('/assets/bg/home.jpg');

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const entering = ref(false);
const error = ref('');
const party = ref([]);

async function loadParty() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/player/party', { method: 'GET' });
    party.value = data.characters ?? [];
    if (data.gold != null) player.setGold(data.gold);
    if (data.items != null) player.setItems(data.items);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function enterDungeon() {
  entering.value = true;
  error.value = '';
  try {
    await api('/dungeon/enter', {
      method: 'POST',
      body: JSON.stringify({ floor: 1 }),
    });
    await player.fetchNavigation();
    router.push({ name: 'dungeon' });
  } catch (e) {
    error.value = e.message;
  } finally {
    entering.value = false;
  }
}

onMounted(() => loadParty());
</script>

<template>
  <div class="hub-screen">
    <div class="exploration-body">
      <ScenePanel :image-src="hubBgSrc" :loading="loading">
        <div v-if="!loading" class="hub-actions">
          <button
            type="button"
            class="scene-overlay-enter"
            :disabled="entering"
            @click="enterDungeon"
          >
            {{ entering ? '入場中…' : '1層に潜る' }}
          </button>
          <button type="button" class="hub-secondary-btn" @click="router.push({ name: 'shop' })">
            武具屋へ
          </button>
          <button type="button" class="hub-secondary-btn" @click="router.push({ name: 'equip' })">
            装備を整える
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
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  align-items: stretch;
  width: min(240px, 72%);
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
