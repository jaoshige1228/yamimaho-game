<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import ScenePanel from '../components/exploration/ScenePanel.vue';
import HubPartyFooter from '../components/hub/HubPartyFooter.vue';
import '../styles/hub-screen.css';
import '../styles/exploration.css';

const router = useRouter();
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
    await api('/dungeon/enter', { method: 'POST' });
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
      <ScenePanel image-src="/assets/bg/home.jpg" :loading="loading">
        <button
          v-if="!loading"
          type="button"
          class="scene-overlay-enter"
          :disabled="entering"
          @click="enterDungeon"
        >
          {{ entering ? '入場中…' : 'ダンジョンに潜る' }}
        </button>
        <p v-if="error" class="scene-error">{{ error }}</p>
      </ScenePanel>
    </div>

    <HubPartyFooter v-if="party.length" :units="party" />
  </div>
</template>
