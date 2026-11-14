<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import AdviceCat from '../components/hub/AdviceCat.vue';
import { useAppAudio } from '../composables/useAppAudio.js';
import { useBattleStore } from '../stores/battle';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';

const router = useRouter();
const player = usePlayerStore();
const battle = useBattleStore();
const { unlock } = useAppAudio();
const loading = ref(true);
const enteringFloor = ref(null);
const enteringRift = ref(false);
/** 空間の裂け目でボス戦が始まったことがある（一度なったら、ずっと true）。true なら「時空の裂け目へ」を出す */
const riftReached = ref(false);
const error = ref('');
const unlockedFloor = ref(1);
const playableFloor = ref(1);
const maxFloor = ref(1);
const allFloorsCleared = ref(false);

/** 潜れる最深の層（前の層のボスを倒して解放された層と、遊べる層の、浅いほう） */
const enterableMax = computed(() => Math.min(unlockedFloor.value, playableFloor.value));

/**
 * 層のボタンは、最初から全部の層を出す。まだ潜れない層（前の層のボスを倒していない）は、グレーアウトして押せない。
 * 前の層のボスを倒すごとに、次の層のグレーアウトが外れる。
 */
const floorButtons = computed(() =>
  Array.from({ length: Math.max(maxFloor.value, 1) }, (_, index) => {
    const floor = index + 1;
    return { floor, locked: floor > enterableMax.value };
  }),
);

async function loadDungeonOptions() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/dungeon', { method: 'GET' });
    unlockedFloor.value = data.unlocked_floor ?? 1;
    playableFloor.value = data.playable_floor ?? 1;
    maxFloor.value = data.max_floor ?? Math.max(unlockedFloor.value, playableFloor.value);
    allFloorsCleared.value = data.all_floors_cleared === true;
    riftReached.value = data.rift_reached === true;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function enterDungeon(floor) {
  // まだ潜れない層は、押しても何も起きない（ボタンも押せない状態にしてあるが、念のため）
  if (floor > enterableMax.value) return;
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

/** 「時空の裂け目へ」: ダンジョンに潜らずに、裂け目のボス戦（4 人の魔法使い）を直接始める */
async function enterRift() {
  enteringRift.value = true;
  error.value = '';
  try {
    unlock();
    const data = await api('/dungeon/rift', { method: 'POST' });
    battle.battleId = data.battle_id;
    battle.state = data.state;
    battle.pendingEvents = data.events ?? [];
    await player.fetchNavigation();
    router.push({
      name: 'battle',
      params: { id: data.battle_id },
      // ボス戦（逃げられない）。探索中の戦闘と同じ扱い（勝っても負けても、待機画面に戻る）
      query: { from: 'dungeon', boss: '1' },
    });
  } catch (e) {
    error.value = e.message;
  } finally {
    enteringRift.value = false;
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
          v-for="{ floor, locked } in floorButtons"
          :key="floor"
          type="button"
          class="floor-enter-btn"
          :class="{ locked }"
          :disabled="locked || enteringFloor !== null"
          :aria-disabled="locked ? 'true' : null"
          @click="enterDungeon(floor)"
        >
          {{ enteringFloor === floor ? '入場中…' : `${floor}層に潜る` }}
        </button>
      </div>

      <!-- 一度、裂け目でボス戦が始まったら、層に潜るボタンの少し下に出す -->
      <div v-if="!loading && !error && riftReached" class="rift-actions">
        <button
          type="button"
          class="rift-enter-btn"
          :disabled="enteringFloor !== null || enteringRift"
          @click="enterRift"
        >
          {{ enteringRift ? '向かっている…' : '時空の裂け目へ' }}
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
.rift-actions {
  display: flex;
  flex-direction: column;
  width: min(280px, 100%);
  /* 層に潜るボタンの少し下（親の gap 1rem に足して、少し間をあける） */
  margin-top: 0.9rem;
}
.rift-enter-btn {
  min-height: 48px;
  padding: 0.65rem 1.25rem;
  border: 2px solid rgba(200, 160, 255, 0.85);
  border-radius: 999px;
  background: linear-gradient(135deg, rgba(60, 20, 110, 0.85), rgba(20, 8, 48, 0.9));
  color: #f0e4ff;
  font-size: 1rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35), 0 0 18px rgba(170, 110, 255, 0.35);
}
.rift-enter-btn:disabled {
  opacity: 0.55;
  cursor: default;
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
/* まだ潜れない層（前の層のボスを倒していない）: グレーアウト。押しても反応しない */
.floor-enter-btn.locked:disabled {
  opacity: 1;
  border-color: rgba(160, 160, 170, 0.35);
  background: rgba(60, 60, 68, 0.55);
  color: rgba(190, 190, 200, 0.5);
  box-shadow: none;
  cursor: not-allowed;
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
