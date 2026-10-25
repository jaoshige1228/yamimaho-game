<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useAppAudio } from '../composables/useAppAudio';
import { useBattleStore } from '../stores/battle';

const router = useRouter();
const battle = useBattleStore();
const { unlock } = useAppAudio();

const loading = ref(true);
const available = ref(false);
const initError = ref('');
const enemies = ref([]);
const bossPresets = ref([]);
const selectedKey = ref('');
const count = ref(1);
const starting = ref(false);
const startingBoss = ref('');
const error = ref('');

const enemyOptions = computed(() =>
  enemies.value.map((enemy) => ({
    ...enemy,
    key: `${enemy.floor}:${enemy.code}`,
    label: `${enemy.floor}層 ${enemy.name}（Lv${enemy.level}）`,
  })),
);

async function init() {
  loading.value = true;
  initError.value = '';
  try {
    await api('/test_battles/status', { method: 'GET' });
    available.value = true;
    const data = await api('/test_battles/catalog', { method: 'GET' });
    enemies.value = data.enemies ?? [];
    bossPresets.value = data.boss_presets ?? [];
    if (enemyOptions.value.length > 0) {
      selectedKey.value = enemyOptions.value[0].key;
    }
  } catch (e) {
    available.value = false;
    if (e.status !== 404) {
      initError.value = e.message;
    }
  } finally {
    loading.value = false;
  }
}

function parseSelected() {
  const [floor, code] = selectedKey.value.split(':');
  return { floor: Number(floor), code };
}

async function startRegularBattle() {
  const { floor, code } = parseSelected();
  if (!floor || !code) {
    error.value = '敵を選んでください';
    return;
  }

  starting.value = true;
  error.value = '';
  unlock();
  try {
    const data = await api('/test_battles/start', {
      method: 'POST',
      body: JSON.stringify({ floor, code, count: count.value }),
    });
    battle.battleId = data.battle_id;
    battle.state = data.state;
    battle.pendingEvents = data.events ?? [];
    router.push({
      name: 'battle',
      params: { id: data.battle_id },
    });
  } catch (e) {
    error.value = e.message;
  } finally {
    starting.value = false;
  }
}

async function startBossBattle(presetId) {
  startingBoss.value = presetId;
  error.value = '';
  unlock();
  try {
    const data = await api('/test_battles/start', {
      method: 'POST',
      body: JSON.stringify({ preset: presetId }),
    });
    battle.battleId = data.battle_id;
    battle.state = data.state;
    battle.pendingEvents = data.events ?? [];
    router.push({
      name: 'battle',
      params: { id: data.battle_id },
      query: {
        ...(data.boss_encounter ? { boss: '1' } : {}),
        ...(data.mid_boss_encounter ? { mid_boss: '1' } : {}),
      },
    });
  } catch (e) {
    error.value = e.message;
  } finally {
    startingBoss.value = '';
  }
}

onMounted(() => init());
</script>

<template>
  <div class="test-battles">
    <p v-if="loading" class="muted">読み込み中…</p>

    <section v-else-if="!available" class="panel blocked">
      <h2>利用できません</h2>
      <p>このページは本番環境ではアクセスできません。</p>
      <p v-if="initError" class="error">{{ initError }}</p>
    </section>

    <template v-else>
      <section class="panel">
        <h2>通常敵との戦闘</h2>
        <p class="desc">マスターに登録されている通常敵を選び、出現数（1〜3）を指定して戦闘を開始します。</p>
        <div class="form">
          <label class="field">
            <span>敵</span>
            <select v-model="selectedKey">
              <option v-for="enemy in enemyOptions" :key="enemy.key" :value="enemy.key">
                {{ enemy.label }}
              </option>
            </select>
          </label>
          <label class="field field-count">
            <span>出現数</span>
            <select v-model.number="count">
              <option :value="1">1 体</option>
              <option :value="2">2 体</option>
              <option :value="3">3 体</option>
            </select>
          </label>
          <button
            type="button"
            class="btn-primary"
            :disabled="starting || !selectedKey"
            @click="startRegularBattle"
          >
            {{ starting ? '開始中…' : '戦闘を始める' }}
          </button>
        </div>
      </section>

      <section class="panel">
        <h2>ボス戦</h2>
        <p class="desc">ボス戦は出現数を選べません。ボタンを押すとその構成で戦闘が始まります。</p>
        <div class="boss-list">
          <button
            v-for="preset in bossPresets"
            :key="preset.id"
            type="button"
            class="btn-boss"
            :disabled="startingBoss !== ''"
            @click="startBossBattle(preset.id)"
          >
            <span class="boss-label">{{ preset.label }}</span>
            <span class="boss-desc">{{ preset.description }}</span>
            <span v-if="startingBoss === preset.id" class="boss-busy">開始中…</span>
          </button>
        </div>
      </section>

      <p v-if="error" class="error">{{ error }}</p>
    </template>
  </div>
</template>

<style scoped>
.test-battles {
  width: min(430px, 100vw);
  height: 100%;
  min-height: 0;
  overflow-y: auto;
  padding: 1rem 1.25rem 2rem;
  color: #f8f4ff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  background: #12091f;
}
.panel {
  margin-bottom: 1rem;
  padding: 1rem;
  border: 1px solid rgba(157, 124, 255, 0.35);
  border-radius: 12px;
  background: rgba(20, 12, 34, 0.85);
}
.panel.blocked {
  border-color: rgba(255, 120, 120, 0.35);
}
.panel h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
}
.desc {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  color: #c8b8e8;
  line-height: 1.5;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.85rem;
}
.field select {
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  border: 1px solid rgba(157, 124, 255, 0.45);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.25);
  color: #fff;
  font-size: 1rem;
}
.field-count {
  max-width: 160px;
}
.btn-primary {
  min-height: 44px;
  padding: 0.65rem 1.25rem;
  border: none;
  border-radius: 999px;
  background: linear-gradient(135deg, #7c5cff, #c45cff);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}
.btn-primary:disabled {
  opacity: 0.6;
}
.boss-list {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.btn-boss {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
  min-height: 56px;
  padding: 0.75rem 1rem;
  border: 1px solid rgba(255, 180, 120, 0.45);
  border-radius: 12px;
  background: rgba(48, 24, 12, 0.55);
  color: #ffe8cc;
  cursor: pointer;
  text-align: left;
}
.btn-boss:disabled {
  opacity: 0.6;
}
.boss-label {
  font-size: 0.95rem;
  font-weight: 800;
}
.boss-desc {
  font-size: 0.78rem;
  color: #d8c0a8;
}
.boss-busy {
  font-size: 0.75rem;
  color: #ffd54a;
}
.error {
  margin: 0.75rem 0 0;
  color: #ff8a8a;
  font-size: 0.85rem;
}
.muted {
  color: #8a7aa8;
  font-size: 0.85rem;
}
</style>
