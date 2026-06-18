<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api';

const loading = ref(true);
const submitting = ref(false);
const available = ref(false);
const error = ref('');
const message = ref('');
const expInput = ref(50);
const party = ref([]);
const lastResults = ref([]);
const skipBattles = ref(false);
const forceDialogueEvents = ref(false);
const resetProgress = ref(false);
const dungeonStatus = ref({
  floor: 1,
  step: 0,
  unlocked_floor: 1,
  max_floor: 3,
  playable_floor: 1,
});

async function loadParty() {
  const data = await api('/admins/party', { method: 'GET' });
  party.value = data.characters ?? [];
}

async function loadDungeonSettings() {
  const data = await api('/admins/dungeon-settings', { method: 'GET' });
  skipBattles.value = Boolean(data.skip_battles);
  forceDialogueEvents.value = Boolean(data.force_dialogue_events);
  dungeonStatus.value = {
    floor: data.floor ?? 1,
    step: data.step ?? 0,
    unlocked_floor: data.unlocked_floor ?? 1,
    max_floor: data.max_floor ?? 3,
    playable_floor: data.playable_floor ?? 1,
  };
}

async function init() {
  loading.value = true;
  error.value = '';
  try {
    await api('/admins/status', { method: 'GET' });
    available.value = true;
    await Promise.all([loadParty(), loadDungeonSettings()]);
  } catch (e) {
    available.value = false;
    if (e.status !== 404) {
      error.value = e.message;
    }
  } finally {
    loading.value = false;
  }
}

async function grantExp() {
  const exp = Number(expInput.value);
  if (!Number.isFinite(exp) || exp < 1) {
    error.value = '1 以上の数値を入力してください';
    return;
  }

  submitting.value = true;
  error.value = '';
  message.value = '';
  try {
    const data = await api('/admins/grant-exp', {
      method: 'POST',
      body: JSON.stringify({ exp }),
    });
    message.value = data.message ?? '経験値を配りました';
    lastResults.value = data.characters ?? [];
    await loadParty();
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}

async function applyDungeonSettings() {
  submitting.value = true;
  error.value = '';
  message.value = '';
  try {
    const data = await api('/admins/dungeon-settings', {
      method: 'POST',
      body: JSON.stringify({
        skip_battles: skipBattles.value,
        force_dialogue_events: forceDialogueEvents.value,
        reset_progress: resetProgress.value,
      }),
    });
    message.value = data.message ?? 'ダンジョン設定を反映しました';
    skipBattles.value = Boolean(data.skip_battles);
    forceDialogueEvents.value = Boolean(data.force_dialogue_events);
    dungeonStatus.value = {
      floor: data.floor ?? 1,
      step: data.step ?? 0,
      unlocked_floor: data.unlocked_floor ?? 1,
      max_floor: data.max_floor ?? 3,
      playable_floor: data.playable_floor ?? 1,
    };
    resetProgress.value = false;
  } catch (e) {
    error.value = e.message;
  } finally {
    submitting.value = false;
  }
}

onMounted(() => init());
</script>

<template>
  <div class="admin">
    <p v-if="loading" class="muted">読み込み中…</p>

    <section v-else-if="!available" class="admin-panel blocked">
      <h2>利用できません</h2>
      <p>このページは本番環境ではアクセスできません。</p>
      <p v-if="error" class="error">{{ error }}</p>
    </section>

    <template v-else>
      <section class="admin-panel">
        <h2>経験値配布</h2>
        <p class="desc">パーティー全員に同じ経験値を配り、レベルアップ処理を実行します。</p>
        <form class="exp-form" @submit.prevent="grantExp">
          <label class="field">
            <span>経験値</span>
            <input v-model.number="expInput" type="number" min="1" step="1" required />
          </label>
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? '処理中…' : '決定' }}
          </button>
        </form>
        <p v-if="message" class="success">{{ message }}</p>
        <p v-if="error" class="error">{{ error }}</p>
      </section>

      <section class="admin-panel">
        <h2>ダンジョン設定</h2>
        <p class="desc">
          デモユーザーの探索進行を調整します。敵無しモードは永続、探索リセットは「適用」時のみ深さを 0 に戻します。
        </p>
        <form class="dungeon-form" @submit.prevent="applyDungeonSettings">
          <label class="check">
            <input v-model="skipBattles" type="checkbox" />
            <span>ダンジョン敵無しモード（戦闘抽選をスキップ）</span>
          </label>
          <label class="check">
            <input v-model="forceDialogueEvents" type="checkbox" />
            <span>会話のみイベント100%モード（デバッグ用）</span>
          </label>
          <label class="check">
            <input v-model="resetProgress" type="checkbox" />
            <span>ダンジョン探索リセット（適用時に深さ 0）</span>
          </label>
          <p class="muted">
            現在: {{ dungeonStatus.floor }}層 / 深さ {{ dungeonStatus.step }} /
            解放 {{ dungeonStatus.unlocked_floor }}層まで /
            挑戦可能 {{ dungeonStatus.playable_floor }}層まで
          </p>
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? '処理中…' : '適用' }}
          </button>
        </form>
      </section>

      <section v-if="lastResults.length" class="admin-panel">
        <h2>直近の結果</h2>
        <ul class="result-list">
          <li v-for="row in lastResults" :key="row.slot_id">
            {{ row.name }} … Lv{{ row.level }}（+{{ row.levels_gained }}） EXP {{ row.exp }}
          </li>
        </ul>
      </section>

      <section class="admin-panel">
        <h2>現在のパーティー</h2>
        <ul v-if="party.length" class="party-list">
          <li v-for="member in party" :key="member.slot_id">
            <strong>{{ member.name }}</strong>
            <span>Lv {{ member.level }}</span>
            <span>EXP {{ member.exp }}</span>
            <span>HP {{ member.hp }}</span>
            <span>MP {{ member.mp }}</span>
          </li>
        </ul>
        <p v-else class="muted">パーティーデータがありません</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.admin {
  width: min(430px, 100vw);
  height: 100%;
  min-height: 0;
  overflow-y: auto;
  padding: 1rem 1.25rem 2rem;
  color: #f8f4ff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  background: #12091f;
}
.admin-panel {
  margin-bottom: 1rem;
  padding: 1rem;
  border: 1px solid rgba(157, 124, 255, 0.35);
  border-radius: 12px;
  background: rgba(20, 12, 34, 0.85);
}
.admin-panel.blocked {
  border-color: rgba(255, 120, 120, 0.35);
}
.admin-panel h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
}
.desc {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  color: #c8b8e8;
  line-height: 1.5;
}
.exp-form,
.dungeon-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
}
.dungeon-form {
  flex-direction: column;
  align-items: stretch;
}
.check {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: 0.85rem;
  line-height: 1.45;
  cursor: pointer;
}
.check input {
  margin-top: 0.2rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  min-width: 140px;
  font-size: 0.85rem;
}
.field input {
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  border: 1px solid rgba(157, 124, 255, 0.45);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.25);
  color: #fff;
  font-size: 1rem;
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
.success {
  margin: 0.75rem 0 0;
  color: #9dffb8;
  font-size: 0.85rem;
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
.party-list,
.result-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.party-list li,
.result-list li {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  padding: 0.55rem 0.65rem;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
  font-size: 0.82rem;
}
.party-list strong {
  min-width: 4.5rem;
}
</style>
