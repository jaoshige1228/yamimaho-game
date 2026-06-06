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
import { publicAssetUrl } from '../utils/publicAssetUrl.js';
import { useAppAudio } from '../composables/useAppAudio.js';

const dungeonBgSrc = publicAssetUrl('/assets/bg/dungeon1.jpg');

const router = useRouter();
const { playSe, unlock } = useAppAudio();
const battle = useBattleStore();
const player = usePlayerStore();
const loading = ref(true);
const advancing = ref(false);
const explorationBusy = ref(false);
const error = ref('');
const step = ref(0);
const floor = ref(1);
const unlockedFloor = ref(1);
const maxFloor = ref(3);
const playableFloor = ref(1);
const party = ref([]);
const retreatModalOpen = ref(false);
const retreating = ref(false);
const storyActive = ref(false);
const storyLines = ref([]);
const explorationSessionId = ref('');
const choiceActive = ref(false);
const choicePrompt = ref('');
const choiceOptions = ref([]);
const pendingChoice = ref(null);
const pendingCommittedParty = ref(null);
const healModalOpen = ref(false);

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

function onStorySyncParty() {
  if (pendingCommittedParty.value) {
    party.value = pendingCommittedParty.value;
    pendingCommittedParty.value = null;
  }
}

function applyDungeonStatus(data) {
  if (data.step != null) step.value = data.step;
  if (data.floor != null) floor.value = data.floor;
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

const canRetreat = computed(() => canHeal.value);

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
  storyActive.value = true;
  return true;
}

function showChoiceSegment(segment) {
  choicePrompt.value = segment?.prompt ?? '';
  choiceOptions.value = segment?.options ?? [];
  choiceActive.value = true;
}

function playStatCheckResultSe(segment) {
  const result = segment?.stat_check_result;
  if (result === 'success') {
    playSe('success');
  } else if (result === 'fail') {
    playSe('miss');
  }
}

async function handleExplorationPayload(data) {
  applyDungeonStatus(data);
  applyExplorationParty(data);

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

  if (segment.kind === 'battle' && data.battle_id) {
    explorationSessionId.value = '';
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
      },
    });
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
  error.value = '';
  try {
    const data = await api('/dungeon/exploration/continue', {
      method: 'POST',
      body: JSON.stringify({ session_id: explorationSessionId.value }),
    });
    await handleExplorationPayload(data);
  } catch (e) {
    error.value = e.message;
  } finally {
    explorationBusy.value = false;
  }
}

async function onStoryComplete() {
  storyActive.value = false;
  storyLines.value = [];

  if (pendingChoice.value) {
    showChoiceSegment(pendingChoice.value);
    pendingChoice.value = null;
    return;
  }

  if (explorationSessionId.value) {
    await continueExploration();
  }
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
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function advance() {
  advancing.value = true;
  error.value = '';
  explorationSessionId.value = '';
  choiceActive.value = false;
  unlock();
  playSe('cursor');
  try {
    const data = await api('/dungeon/advance', { method: 'POST' });

    if (data.event === 'battle') {
      step.value = data.step ?? step.value;
      battle.battleId = data.battle_id;
      battle.state = data.state;
      battle.pendingEvents = data.events ?? [];
      router.push({
        name: 'battle',
        params: { id: data.battle_id },
        query: {
          from: 'dungeon',
          ...(data.boss_encounter ? { boss: '1' } : {}),
        },
      });
      return;
    }

    if (data.event === 'exploration') {
      await handleExplorationPayload(data);
      return;
    }

    error.value = '不明な応答です';
  } catch (e) {
    error.value = e.message;
  } finally {
    advancing.value = false;
  }
}

onMounted(async () => {
  await Promise.all([loadStatus(), loadParty()]);
});
</script>

<template>
  <div class="hub-screen">
    <div class="exploration-body">
      <ScenePanel :image-src="dungeonBgSrc" :loading="loading">
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
          @click="healModalOpen = true"
        >
          回復
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
  border: 1px solid rgba(200, 176, 255, 0.45);
  background: rgba(20, 10, 40, 0.72);
  color: #f8f4ff;
  border-radius: 999px;
  padding: 0.45rem 0.9rem;
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
}
</style>
