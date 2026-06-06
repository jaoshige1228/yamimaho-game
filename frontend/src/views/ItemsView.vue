<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const error = ref('');

function effectLabel(effect, power) {
  if (effect === 'heal_hp') return `HP +${power}`;
  if (effect === 'heal_mp') return `MP +${power}`;
  if (effect === 'revive') return '蘇生';
  return effect ?? '';
}

async function loadItems() {
  loading.value = true;
  error.value = '';
  try {
    await player.fetchParty();
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

onMounted(loadItems);
</script>

<template>
  <div class="hub-screen items-screen">
    <header class="sub-screen-header">
      <button type="button" class="hub-screen-back" @click="router.push({ name: 'hub' })">←</button>
      <h1>持ち物</h1>
    </header>

    <p v-if="loading" class="hub-screen-muted items-body">読み込み中…</p>
    <p v-else-if="error" class="hub-screen-error items-body">{{ error }}</p>

    <div v-else class="items-body">
      <p v-if="player.items.length === 0" class="items-empty">
        持っているアイテムはありません。
      </p>

      <ul v-else class="items-list">
        <li v-for="item in player.items" :key="item.code" class="items-row">
          <div class="items-row-main">
            <div class="items-row-title">
              <strong>{{ item.name }}</strong>
              <span class="items-qty">×{{ item.quantity }}</span>
            </div>
            <span v-if="item.description" class="items-desc">{{ item.description }}</span>
            <span class="items-effect">{{ effectLabel(item.effect, item.power) }}</span>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.items-screen {
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
  flex: 1;
  font-size: 1.1rem;
}
.items-body {
  padding: 0.5rem 1rem 1.5rem;
}
.items-empty {
  margin: 2rem 0 0;
  text-align: center;
  color: #a898c8;
  font-size: 0.9rem;
  line-height: 1.6;
}
.items-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}
.items-row {
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(157, 124, 255, 0.18);
}
.items-row-main {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}
.items-row-title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}
.items-row-title strong {
  font-size: 0.9rem;
}
.items-qty {
  font-size: 0.85rem;
  font-weight: 700;
  color: #ffd98a;
  font-variant-numeric: tabular-nums;
}
.items-desc {
  font-size: 0.72rem;
  color: #a898c8;
  line-height: 1.4;
}
.items-effect {
  font-size: 0.72rem;
  color: #b8ffd8;
  font-weight: 600;
}
</style>
