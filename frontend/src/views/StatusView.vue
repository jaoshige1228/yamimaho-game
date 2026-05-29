<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import CharacterStatCard from '../components/character/CharacterStatCard.vue';
import '../styles/hub-screen.css';

const router = useRouter();
const loading = ref(true);
const error = ref('');
const party = ref([]);

async function loadParty() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/player/stats', { method: 'GET' });
    party.value = data.characters ?? [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

onMounted(() => loadParty());
</script>

<template>
  <div class="hub-screen status-screen">
    <main class="status-main">
      <p v-if="loading" class="hub-screen-muted">読み込み中…</p>
      <p v-else-if="error" class="hub-screen-error">{{ error }}</p>
      <div v-else class="status-list">
        <CharacterStatCard
          v-for="member in party"
          :key="member.id"
          :unit="member"
          wide
        />
      </div>
    </main>
  </div>
</template>

<style scoped>
.status-screen .status-main {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  padding: 0.35rem 0.6rem 0.5rem;
  -webkit-overflow-scrolling: touch;
}
.status-list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  width: 100%;
}
</style>
