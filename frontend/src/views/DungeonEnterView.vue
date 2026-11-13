<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import AdviceCat from '../components/hub/AdviceCat.vue';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const enteringFloor = ref(null);
const error = ref('');
const unlockedFloor = ref(1);
const playableFloor = ref(1);
const allFloorsCleared = ref(false);

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
    allFloorsCleared.value = data.all_floors_cleared === true;
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
      <AdviceCat class="dungeon-enter-cat" />

      <p class="dungeon-enter-guide">
        <span>トラップや<wbr />モンスターに<wbr />対応しながら、<wbr />最深部を<wbr />目指しましょう。</span>
        <span>最深部の<wbr />ボスを倒せば、<wbr />次の層が<wbr />解放されます</span>
      </p>

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
      </div>

      <!-- 最終層まで踏破したら、画面の下のほうに出す -->
      <p v-if="allFloorsCleared" class="all-cleared">全ての層を踏破した</p>
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
  gap: 1rem;
  padding: 0.5rem 1rem 1rem;
}
.dungeon-enter-cat {
  width: min(360px, 100%);
}
.dungeon-enter-guide {
  box-sizing: border-box;
  width: min(360px, 100%);
  margin: 0;
  padding: 0 0.5rem;
  color: #e6dcff;
  font-size: 0.78rem;
  line-height: 1.7;
  text-align: center;
}
.dungeon-enter-guide span {
  display: block;
  /* 文節の切れ目（<wbr>）でだけ折り返す。言葉の途中で切れないようにする */
  word-break: keep-all;
  overflow-wrap: anywhere;
  text-wrap: balance;
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
.all-cleared {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.7rem;
  width: min(360px, 100%);
  /* 余白を上に寄せて、画面の下のほうに置く */
  margin: auto 0 0;
  padding-top: 1.5rem;
  color: #ffe08a;
  font-size: 0.95rem;
  font-weight: 800;
  letter-spacing: 0.14em;
  text-shadow: 0 0 10px rgba(255, 210, 110, 0.45);
}
.all-cleared::before,
.all-cleared::after {
  content: '';
  flex: 1;
  height: 1px;
  background: linear-gradient(to right, transparent, rgba(255, 224, 138, 0.7));
}
.all-cleared::after {
  background: linear-gradient(to left, transparent, rgba(255, 224, 138, 0.7));
}
</style>
