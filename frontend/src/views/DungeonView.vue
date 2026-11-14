<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import ExplorationChoiceOverlay from '../components/exploration/ExplorationChoiceOverlay.vue';
import ScenePanel from '../components/exploration/ScenePanel.vue';
import StorySequencePlayer from '../components/exploration/StorySequencePlayer.vue';
import RetreatConfirmModal from '../components/exploration/RetreatConfirmModal.vue';
import DungeonHealModal from '../components/exploration/DungeonHealModal.vue';
import HubPartyFooter from '../components/hub/HubPartyFooter.vue';
import { useBattleStore } from '../stores/battle';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';
import '../styles/exploration.css';
import { dungeonBackgroundUrl } from '../utils/dungeonTheme.js';
import { useAppAudio } from '../composables/useAppAudio.js';

const router = useRouter();
const { playSe, unlock } = useAppAudio();
const battle = useBattleStore();
const player = usePlayerStore();
const loading = ref(true);
const advancing = ref(false);
const explorationBusy = ref(false);
const error = ref('');
const step = ref(0);
// ルーターが画面を開く前にナビゲーションを取得済みなので、読み込み中から正しい層の背景を出せる
const floor = ref(player.dungeonFloor);
const dungeonBgSrc = computed(() => dungeonBackgroundUrl(floor.value));
const unlockedFloor = ref(1);
const maxFloor = ref(3);
const playableFloor = ref(1);
const party = ref([]);
const retreatModalOpen = ref(false);
const retreating = ref(false);
const storyActive = ref(false);
const storyLines = ref([]);
const storyPerLinePartySync = ref(false);
const explorationSessionId = ref('');
/** 探索の「続き」を送れなかった（通信エラーなど）。探索は途中のまま残っているので、「もう一度」を出す */
const continueFailed = ref(false);
const choiceActive = ref(false);
const choicePrompt = ref('');
const choiceOptions = ref([]);
const pendingChoice = ref(null);
const pendingCommittedParty = ref(null);
const healModalOpen = ref(false);
const sceneFadeActive = ref(false);
/** @type {ReturnType<typeof setTimeout> | null} */
let sceneFadeTimer = null;

function applyParty(data) {
  if (Array.isArray(data?.party)) {
    party.value = data.party;
  }
}

function applyExplorationParty(data) {
  if (Array.isArray(data?.party_snapshot)) {
    party.value = data.party_snapshot;
    pendingCommittedParty.value = Array.isArray(data?.party) ? data.party : null;
    return;
  }

  pendingCommittedParty.value = null;
  applyParty(data);
}

function onStorySyncParty(partialParty) {
  if (Array.isArray(partialParty)) {
    party.value = partialParty;
    return;
  }
  // 行ごとに party を持つセグメントでは、sync_party だけの行で一括反映しない
  if (storyPerLinePartySync.value) {
    return;
  }
  if (pendingCommittedParty.value) {
    party.value = pendingCommittedParty.value;
    pendingCommittedParty.value = null;
  }
}

function applyDungeonStatus(data) {
  if (data.step != null) step.value = data.step;
  if (data.floor != null) {
    floor.value = data.floor;
    player.setDungeonFloor(data.floor);
  }
  if (data.unlocked_floor != null) unlockedFloor.value = data.unlocked_floor;
  if (data.max_floor != null) maxFloor.value = data.max_floor;
  if (data.playable_floor != null) playableFloor.value = data.playable_floor;
  if (data.gold != null) player.setGold(data.gold);
}

const canHeal = computed(
  () =>
    !loading.value &&
    !storyActive.value &&
    !choiceActive.value &&
    !explorationSessionId.value &&
    !advancing.value &&
    !explorationBusy.value,
);

/** 「続き」を送れずに止まっている（探索は途中のまま）。「もう一度」を出し、撤退もできるようにする */
const continueRetryable = computed(
  () =>
    continueFailed.value &&
    !!explorationSessionId.value &&
    !loading.value &&
    !storyActive.value &&
    !choiceActive.value &&
    !advancing.value &&
    !explorationBusy.value,
);

const canRetreat = computed(() => canHeal.value || continueRetryable.value);

function onHealUpdated(data) {
  if (Array.isArray(data?.party)) {
    party.value = data.party;
  }
  if (data?.items != null) {
    player.setItems(data.items);
  }
}

function showStorySegment(segment) {
  const lines = segment?.lines ?? [];
  if (lines.length === 0) {
    return false;
  }
  storyLines.value = lines;
  storyPerLinePartySync.value = lines.some((line) => Array.isArray(line.party));
  storyActive.value = true;
  return true;
}

function showChoiceSegment(segment) {
  choicePrompt.value = segment?.prompt ?? '';
  choiceOptions.value = segment?.options ?? [];
  choiceActive.value = true;
}

function playStatCheckResultSe(segment) {
  // 結果の行（ダイスロールのログ付き）がある場合は、その行を表示するとき StorySequencePlayer が鳴らす
  if (segment?.lines?.some((line) => line?.dice)) {
    return;
  }
  const result = segment?.stat_check_result;
  if (result === 'success') {
    playSe('success');
  } else if (result === 'fail') {
    playSe('miss');
  }
}

function launchExplorationBattle(data) {
  if (data.mid_boss_encounter && (data.exploration_session_id || data.session_id)) {
    explorationSessionId.value = data.exploration_session_id ?? data.session_id ?? explorationSessionId.value;
  } else {
    explorationSessionId.value = '';
  }
  choiceActive.value = false;
  battle.battleId = data.battle_id;
  battle.state = data.state;
  battle.pendingEvents = data.events ?? [];
  router.push({
    name: 'battle',
    params: { id: data.battle_id },
    query: {
      from: 'dungeon',
      ...(data.boss_encounter ? { boss: '1' } : {}),
      ...(data.mid_boss_encounter ? { mid_boss: '1' } : {}),
    },
  });
}

async function handleExplorationPayload(data) {
  applyDungeonStatus(data);
  applyExplorationParty(data);

  if (data.event === 'battle' && data.battle_id) {
    unlock();
    launchExplorationBattle(data);
    return;
  }

  const segment = data.segment;
  if (!segment) {
    explorationSessionId.value = '';
    return;
  }

  unlock();
  playStatCheckResultSe(segment);

  explorationSessionId.value = data.session_id ?? explorationSessionId.value;

  if (segment.kind === 'complete') {
    explorationSessionId.value = '';
    choiceActive.value = false;
    await loadParty();
    return;
  }

  if (segment.kind === 'game_over') {
    explorationSessionId.value = '';
    choiceActive.value = false;
    await loadParty();
    router.push('/hub');
    return;
  }

  if (segment.kind === 'choice') {
    if (segment.lines?.length) {
      pendingChoice.value = segment;
      showStorySegment({ lines: segment.lines });
      return;
    }
    showChoiceSegment(segment);
    return;
  }

  if (segment.kind === 'story') {
    if (showStorySegment(segment)) {
      return;
    }
    await continueExploration();
  }
}

async function continueExploration() {
  if (!explorationSessionId.value) {
    return;
  }

  explorationBusy.value = true;
  continueFailed.value = false;
  error.value = '';
  try {
    const data = await api('/dungeon/exploration/continue', {
      method: 'POST',
      body: JSON.stringify({ session_id: explorationSessionId.value }),
    });
    await handleExplorationPayload(data);
  } catch (e) {
    await recoverFromContinueError(e);
  } finally {
    explorationBusy.value = false;
  }
}

/**
 * 探索の「続き」を送れなかったとき。探索がもう無いのか（セッション切れ・すでに終わっている）、通信が切れただけなのかを、
 * いまの状態を取り直して確かめる。何もしないと、「進む」も「撤退」も押せなくなって、先に進めなくなる。
 * - 探索がもう無い … ダンジョンの外なら待機画面へ。中なら「進む」が出る
 * - 探索が途中のまま … 「もう一度」で続きを送り直せる。撤退もできる
 */
async function recoverFromContinueError(e) {
  error.value = e.message;
  try {
    const data = await api('/dungeon', { method: 'GET' });
    applyDungeonStatus(data);
    explorationSessionId.value = data.exploration_session_id ?? '';
    if (explorationSessionId.value === '') {
      error.value = '';
      await loadParty();
      if (data.in_dungeon === false) {
        await player.fetchNavigation();
        router.push('/hub');
      }
      return;
    }
  } catch {
    // 通信できていない。探索は残っているはずなので、「もう一度」で続きを送り直す
  }
  continueFailed.value = explorationSessionId.value !== '';
}

async function retryContinue() {
  if (explorationBusy.value) {
    return;
  }
  unlock();
  playSe('cursor');
  await continueExploration();
}

async function onStoryComplete() {
  storyActive.value = false;
  storyLines.value = [];
  storyPerLinePartySync.value = false;

  if (pendingChoice.value) {
    showChoiceSegment(pendingChoice.value);
    pendingChoice.value = null;
    return;
  }

  if (explorationSessionId.value) {
    await continueExploration();
  }
}

function onScreenFade(ms) {
  if (sceneFadeTimer !== null) {
    clearTimeout(sceneFadeTimer);
  }
  sceneFadeActive.value = true;
  sceneFadeTimer = setTimeout(() => {
    sceneFadeActive.value = false;
    sceneFadeTimer = null;
  }, ms);
}

async function onChoice(slotId) {
  if (!explorationSessionId.value) {
    return;
  }

  choiceActive.value = false;
  explorationBusy.value = true;
  error.value = '';
  try {
    const data = await api('/dungeon/exploration/choose', {
      method: 'POST',
      body: JSON.stringify({
        session_id: explorationSessionId.value,
        slot_id: slotId,
      }),
    });
    await handleExplorationPayload(data);
  } catch (e) {
    error.value = e.message;
    choiceActive.value = true;
  } finally {
    explorationBusy.value = false;
  }
}

async function loadParty() {
  try {
    const data = await api('/player/party', { method: 'GET' });
    party.value = data.characters ?? [];
    if (data.gold != null) player.setGold(data.gold);
    if (data.items != null) player.setItems(data.items);
  } catch (e) {
    error.value = e.message;
  }
}

async function confirmRetreat() {
  // 「はい」の二重押しで、撤退（所持金のペナルティ）が 2 回行われないようにする
  if (retreating.value) {
    return;
  }
  retreating.value = true;
  error.value = '';
  try {
    const data = await api('/dungeon/retreat', { method: 'POST' });
    applyDungeonStatus(data);
    retreatModalOpen.value = false;
    await player.fetchNavigation();
    router.push('/hub');
  } catch (e) {
    error.value = e.message;
  } finally {
    retreating.value = false;
  }
}

async function loadStatus() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/dungeon', { method: 'GET' });
    applyDungeonStatus(data);
    if (data.exploration_session_id) {
      explorationSessionId.value = data.exploration_session_id;
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function advance() {
  if (advancing.value || explorationBusy.value) {
    return;
  }

  advancing.value = true;
  error.value = '';
  explorationSessionId.value = '';
  continueFailed.value = false;
  choiceActive.value = false;
  unlock();
  playSe('cursor');

  let keepAdvanceLocked = false;
  try {
    const data = await api('/dungeon/advance', { method: 'POST' });

    if (data.event === 'battle') {
      step.value = data.step ?? step.value;
      keepAdvanceLocked = true;
      launchExplorationBattle(data);
      return;
    }

    if (data.event === 'exploration') {
      await handleExplorationPayload(data);
      return;
    }

    if (data.event === 'flavor') {
      applyDungeonStatus(data);
      if (data.segment?.kind === 'story' && showStorySegment(data.segment)) {
        return;
      }

      error.value = '不明な応答です';
      return;
    }

    error.value = '不明な応答です';
  } catch (e) {
    error.value = e.message;
  } finally {
    if (!keepAdvanceLocked) {
      advancing.value = false;
    }
  }
}

onMounted(async () => {
  await Promise.all([loadStatus(), loadParty()]);
  if (explorationSessionId.value) {
    await continueExploration();
  }
});
</script>

<template>
  <div class="hub-screen">
    <div class="exploration-body">
      <ScenePanel :image-src="dungeonBgSrc" :loading="loading" :fade-active="sceneFadeActive">
        <button
          v-if="!loading"
          type="button"
          class="scene-overlay-retreat"
          :disabled="!canRetreat || retreating"
          @click="retreatModalOpen = true"
        >
          撤退する
        </button>

        <p class="scene-overlay-depth">
          {{ floor }}層　深さ {{ step }}
        </p>

        <button
          v-if="canHeal"
          type="button"
          class="scene-overlay-heal"
          aria-label="回復"
          @click="healModalOpen = true"
        >
          <span class="scene-overlay-heal__plus" aria-hidden="true">＋</span>回復
        </button>

        <button
          v-if="!loading && !storyActive && !choiceActive && !explorationSessionId"
          type="button"
          class="scene-overlay-advance"
          :disabled="advancing || explorationBusy"
          @click="advance"
        >
          {{ advancing || explorationBusy ? '進行中…' : '進む' }}
        </button>

        <button
          v-if="continueRetryable"
          type="button"
          class="scene-overlay-advance"
          @click="retryContinue"
        >
          もう一度
        </button>

        <p v-if="error" class="scene-error">{{ error }}</p>
      </ScenePanel>
    </div>

    <HubPartyFooter v-if="party.length" :units="party" />
  </div>

  <Teleport to="body">
    <StorySequencePlayer
      v-if="storyActive && storyLines.length"
      :lines="storyLines"
      :party="party"
      @complete="onStoryComplete"
      @sync-party="onStorySyncParty"
      @screen-fade="onScreenFade"
    />
    <ExplorationChoiceOverlay
      v-if="choiceActive"
      :prompt="choicePrompt"
      :options="choiceOptions"
      :disabled="explorationBusy"
      @choose="onChoice"
    />
    <RetreatConfirmModal
      :visible="retreatModalOpen"
      :busy="retreating"
      @cancel="retreatModalOpen = false"
      @confirm="confirmRetreat"
    />
    <DungeonHealModal
      :visible="healModalOpen"
      :party="party"
      :items="player.items"
      @close="healModalOpen = false"
      @updated="onHealUpdated"
    />
  </Teleport>
</template>

<style scoped>
.scene-overlay-heal {
  position: absolute;
  left: 1rem;
  bottom: 5.5rem;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  min-height: 40px;
  border: 1px solid rgba(141, 255, 180, 0.55);
  background: rgba(14, 40, 30, 0.8);
  color: #f0fff6;
  border-radius: 999px;
  padding: 0.4rem 1rem 0.4rem 0.75rem;
  font-size: 0.85rem;
  font-weight: 800;
  cursor: pointer;
  touch-action: manipulation;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.45);
}
.scene-overlay-heal__plus {
  color: #8dffb4;
  font-size: 1.05rem;
  line-height: 1;
}
</style>
