<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api';

const loading = ref(true);
const available = ref(false);
const initError = ref('');

const expInput = ref(50);
const expSubmitting = ref(false);
const expError = ref('');
const expMessage = ref('');
const party = ref([]);
const lastResults = ref([]);

const goldInput = ref(1000);
const goldNow = ref(0);
const goldSubmitting = ref(false);
const goldError = ref('');
const goldMessage = ref('');

const skipBattles = ref(false);
const forceDialogueEvents = ref(false);
const resetProgress = ref(false);
const dungeonSubmitting = ref(false);
const dungeonError = ref('');
const dungeonMessage = ref('');
const dungeonStatus = ref({
  floor: 1,
  step: 0,
  unlocked_floor: 1,
  cleared_floor: 0,
  max_floor: 3,
  playable_floor: 1,
});
const clearSubmitting = ref(false);
const clearError = ref('');
const clearMessage = ref('');

const equipment = ref({ weapons: [], armors: [], characters: [] });
/** キャラごとに、いま選んでいる武器・防具（「装備を変更」を押すまで反映しない） */
const equipDraft = ref({});
const equipSubmittingSlot = ref('');
const equipError = ref('');
const equipMessage = ref('');

function toDungeonStatus(data) {
  return {
    floor: data.floor ?? 1,
    step: data.step ?? 0,
    unlocked_floor: data.unlocked_floor ?? 1,
    cleared_floor: data.cleared_floor ?? 0,
    max_floor: data.max_floor ?? 3,
    playable_floor: data.playable_floor ?? 1,
  };
}

async function loadParty() {
  const data = await api('/admins/party', { method: 'GET' });
  party.value = data.characters ?? [];
  goldNow.value = Number(data.gold ?? 0);
}

function applyEquipment(data) {
  equipment.value = {
    weapons: data.weapons ?? [],
    armors: data.armors ?? [],
    characters: data.characters ?? [],
  };
  equipDraft.value = Object.fromEntries(
    equipment.value.characters.map((c) => [
      c.slot_id,
      { weapon_code: c.weapon_code ?? '', armor_code: c.armor_code ?? '' },
    ]),
  );
}

async function loadEquipment() {
  applyEquipment(await api('/admins/equipment', { method: 'GET' }));
}

/** 選んでいる装備が、いま付けているものと違うか */
function equipChanged(member) {
  const draft = equipDraft.value[member.slot_id];
  if (!draft) return false;
  return draft.weapon_code !== (member.weapon_code ?? '') || draft.armor_code !== (member.armor_code ?? '');
}

async function applyEquip(member) {
  const draft = equipDraft.value[member.slot_id];
  if (!draft?.weapon_code || !draft?.armor_code) {
    equipError.value = '武器と防具を選んでください';
    equipMessage.value = '';
    return;
  }

  equipSubmittingSlot.value = member.slot_id;
  equipError.value = '';
  equipMessage.value = '';
  try {
    const data = await api('/admins/equipment', {
      method: 'POST',
      body: JSON.stringify({
        slot_id: member.slot_id,
        weapon_code: draft.weapon_code,
        armor_code: draft.armor_code,
      }),
    });
    applyEquipment(data);
    equipMessage.value = data.message ?? '装備を変更しました';
  } catch (e) {
    equipError.value = e.message;
  } finally {
    equipSubmittingSlot.value = '';
  }
}

async function loadDungeonSettings() {
  const data = await api('/admins/dungeon-settings', { method: 'GET' });
  skipBattles.value = Boolean(data.skip_battles);
  forceDialogueEvents.value = Boolean(data.force_dialogue_events);
  dungeonStatus.value = toDungeonStatus(data);
}

async function init() {
  loading.value = true;
  initError.value = '';
  try {
    await api('/admins/status', { method: 'GET' });
    available.value = true;
    await Promise.all([loadParty(), loadDungeonSettings(), loadEquipment()]);
  } catch (e) {
    available.value = false;
    if (e.status !== 404) {
      initError.value = e.message;
    }
  } finally {
    loading.value = false;
  }
}

async function grantExp() {
  const exp = Number(expInput.value);
  if (!Number.isFinite(exp) || exp < 1) {
    expError.value = '1 以上の数値を入力してください';
    expMessage.value = '';
    return;
  }

  expSubmitting.value = true;
  expError.value = '';
  expMessage.value = '';
  try {
    const data = await api('/admins/grant-exp', {
      method: 'POST',
      body: JSON.stringify({ exp }),
    });
    expMessage.value = data.message ?? '経験値を配りました';
    lastResults.value = data.characters ?? [];
    await loadParty();
  } catch (e) {
    expError.value = e.message;
  } finally {
    expSubmitting.value = false;
  }
}

async function grantGold() {
  const gold = Number(goldInput.value);
  if (!Number.isInteger(gold) || gold < 1) {
    goldError.value = '1 以上の整数を入力してください';
    goldMessage.value = '';
    return;
  }

  goldSubmitting.value = true;
  goldError.value = '';
  goldMessage.value = '';
  try {
    const data = await api('/admins/grant-gold', {
      method: 'POST',
      body: JSON.stringify({ gold }),
    });
    goldMessage.value = data.message ?? 'ゴールドを追加しました';
    if (data.gold != null) goldNow.value = Number(data.gold);
  } catch (e) {
    goldError.value = e.message;
  } finally {
    goldSubmitting.value = false;
  }
}

async function applyDungeonSettings() {
  dungeonSubmitting.value = true;
  dungeonError.value = '';
  dungeonMessage.value = '';
  try {
    const data = await api('/admins/dungeon-settings', {
      method: 'POST',
      body: JSON.stringify({
        skip_battles: skipBattles.value,
        force_dialogue_events: forceDialogueEvents.value,
        reset_progress: resetProgress.value,
      }),
    });
    dungeonMessage.value = data.message ?? 'ダンジョン設定を反映しました';
    skipBattles.value = Boolean(data.skip_battles);
    forceDialogueEvents.value = Boolean(data.force_dialogue_events);
    dungeonStatus.value = toDungeonStatus(data);
    resetProgress.value = false;
  } catch (e) {
    dungeonError.value = e.message;
  } finally {
    dungeonSubmitting.value = false;
  }
}

/** 指定した層をクリア済みにする。元に戻せないので、押す前に確認する */
async function clearFloor(floor) {
  if (!window.confirm(`${floor}層をクリア済みにします。元に戻せません。よろしいですか？`)) {
    return;
  }

  clearSubmitting.value = true;
  clearError.value = '';
  clearMessage.value = '';
  try {
    const data = await api('/admins/dungeon-clear', {
      method: 'POST',
      body: JSON.stringify({ floor }),
    });
    clearMessage.value = data.message ?? `${floor}層をクリア済みにしました`;
    dungeonStatus.value = toDungeonStatus(data);
  } catch (e) {
    clearError.value = e.message;
  } finally {
    clearSubmitting.value = false;
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
      <p v-if="initError" class="error">{{ initError }}</p>
    </section>

    <template v-else>
      <section class="admin-panel">
        <h2>経験値配布</h2>
        <p class="desc">パーティー全員に同じ経験値を配り、レベルアップ処理を実行します。</p>
        <div class="exp-form">
          <label class="field">
            <span>経験値</span>
            <input
              v-model.number="expInput"
              type="number"
              min="1"
              step="1"
              inputmode="numeric"
              @keydown.enter.prevent="grantExp"
            />
          </label>
          <button type="button" class="btn-primary" :disabled="expSubmitting" @click="grantExp">
            {{ expSubmitting ? '処理中…' : '経験値を配る' }}
          </button>
        </div>
        <p v-if="expMessage" class="success">{{ expMessage }}</p>
        <p v-if="expError" class="error">{{ expError }}</p>
      </section>

      <section class="admin-panel">
        <h2>ゴールド追加</h2>
        <p class="desc">
          パーティーの所持金に、指定した数のゴールドを足します（いまの所持金: <strong>{{ goldNow.toLocaleString() }} G</strong>）。
        </p>
        <div class="exp-form">
          <label class="field">
            <span>追加するゴールド</span>
            <input
              v-model.number="goldInput"
              type="number"
              min="1"
              max="9999999"
              step="1"
              inputmode="numeric"
              @keydown.enter.prevent="grantGold"
            />
          </label>
          <button type="button" class="btn-primary" :disabled="goldSubmitting" @click="grantGold">
            {{ goldSubmitting ? '処理中…' : 'ゴールドを追加' }}
          </button>
        </div>
        <p v-if="goldMessage" class="success">{{ goldMessage }}</p>
        <p v-if="goldError" class="error">{{ goldError }}</p>
      </section>

      <section class="admin-panel">
        <h2>ダンジョン設定</h2>
        <p class="desc">
          デモユーザーの探索進行を調整します。敵無しモードは永続、探索リセットは「設定を適用」時のみ深さを 0 に戻します。
        </p>
        <div class="dungeon-form">
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
            クリア済み {{ dungeonStatus.cleared_floor }}層まで /
            挑戦可能 {{ dungeonStatus.playable_floor }}層まで
          </p>
          <button
            type="button"
            class="btn-primary"
            :disabled="dungeonSubmitting"
            @click="applyDungeonSettings"
          >
            {{ dungeonSubmitting ? '処理中…' : '設定を適用' }}
          </button>
        </div>
        <p v-if="dungeonMessage" class="success">{{ dungeonMessage }}</p>
        <p v-if="dungeonError" class="error">{{ dungeonError }}</p>
      </section>

      <section class="admin-panel">
        <h2>ダンジョンのクリア状況</h2>
        <p class="desc">
          指定した層をクリア済みにします（その層より浅い層もクリア済みになり、次の層が解放されます）。
          元に戻す機能はありません。
        </p>
        <ul class="clear-list">
          <li v-for="n in dungeonStatus.max_floor" :key="n">
            <strong>{{ n }}層</strong>
            <span class="clear-state" :class="{ done: dungeonStatus.cleared_floor >= n }">
              {{ dungeonStatus.cleared_floor >= n ? 'クリア済み' : '未クリア' }}
            </span>
            <button
              v-if="dungeonStatus.cleared_floor < n"
              type="button"
              class="btn-primary"
              :disabled="clearSubmitting"
              @click="clearFloor(n)"
            >
              クリア済みにする
            </button>
          </li>
        </ul>
        <p v-if="clearMessage" class="success">{{ clearMessage }}</p>
        <p v-if="clearError" class="error">{{ clearError }}</p>
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
        <h2>装備の操作</h2>
        <p class="desc">
          味方の武器・防具を、持っているかどうかに関係なく好きなものに替えます。替えた装備は所持扱いになり、
          装備画面でも選べます。装備なしにはできません（いちばん弱い装備は基本の杖・基本のローブです）。
        </p>
        <ul v-if="equipment.characters.length" class="equip-list">
          <li v-for="member in equipment.characters" :key="member.slot_id" class="equip-row">
            <div class="equip-head">
              <strong>{{ member.name }}</strong>
              <span class="muted">Lv {{ member.level }}</span>
            </div>
            <label class="field">
              <span>武器</span>
              <select v-model="equipDraft[member.slot_id].weapon_code">
                <option v-for="w in equipment.weapons" :key="w.code" :value="w.code">
                  {{ w.name }}（魔力 +{{ w.mag_bonus }}）
                </option>
              </select>
            </label>
            <label class="field">
              <span>防具</span>
              <select v-model="equipDraft[member.slot_id].armor_code">
                <option v-for="a in equipment.armors" :key="a.code" :value="a.code">
                  {{ a.name }}（防御 +{{ a.def_bonus }}）
                </option>
              </select>
            </label>
            <button
              type="button"
              class="btn-primary equip-btn"
              :disabled="!equipChanged(member) || equipSubmittingSlot !== ''"
              @click="applyEquip(member)"
            >
              {{ equipSubmittingSlot === member.slot_id ? '処理中…' : '装備を変更' }}
            </button>
          </li>
        </ul>
        <p v-else class="muted">パーティーデータがありません</p>
        <p v-if="equipMessage" class="success">{{ equipMessage }}</p>
        <p v-if="equipError" class="error">{{ equipError }}</p>
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
.equip-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.equip-row {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 0.7rem 0.75rem;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
}
.equip-head {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
}
.field select {
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  border: 1px solid rgba(157, 124, 255, 0.45);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.25);
  color: #fff;
  font-size: 0.9rem;
}
.equip-row .field {
  min-width: 0;
}
.equip-btn {
  align-self: flex-end;
  min-height: 40px;
  padding: 0.45rem 1.1rem;
  font-size: 0.85rem;
}
.clear-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.clear-list li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.55rem 0.65rem;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
  font-size: 0.85rem;
}
.clear-list strong {
  min-width: 2.5rem;
}
.clear-state {
  flex: 1;
  color: #8a7aa8;
}
.clear-state.done {
  color: #9dffb8;
}
.clear-list .btn-primary {
  min-height: 36px;
  padding: 0.4rem 0.9rem;
  font-size: 0.78rem;
}
</style>
