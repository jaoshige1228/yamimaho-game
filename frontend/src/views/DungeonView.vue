<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import ExplorationChoiceOverlay from '../components/exploration/ExplorationChoiceOverlay.vue';
import ScenePanel from '../components/exploration/ScenePanel.vue';
import StorySequencePlayer from '../components/exploration/StorySequencePlayer.vue';
import HubPartyFooter from '../components/hub/HubPartyFooter.vue';
import { useBattleStore } from '../stores/battle';
import '../styles/hub-screen.css';
import '../styles/exploration.css';

const router = useRouter();
const battle = useBattleStore();
const loading = ref(true);
const advancing = ref(false);
const explorationBusy = ref(false);
const error = ref('');
const step = ref(0);
const party = ref([]);
const storyActive = ref(false);
const storyLines = ref([]);
const explorationSessionId = ref('');
const choiceActive = ref(false);
const choicePrompt = ref('');
const choiceOptions = ref([]);
const pendingChoice = ref(null);

function applyParty(data) {
  if (Array.isArray(data?.party)) {
    party.value = data.party;
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

async function handleExplorationPayload(data) {
  step.value = data.step ?? step.value;
  applyParty(data);

  const segment = data.segment;
  if (!segment) {
    explorationSessionId.value = '';
    return;
  }

  explorationSessionId.value = data.session_id ?? explorationSessionId.value;

  if (segment.kind === 'complete') {
    explorationSessionId.value = '';
    choiceActive.value = false;
    await loadParty();
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
  } catch (e) {
    error.value = e.message;
  }
}

async function loadStatus() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/dungeon', { method: 'GET' });
    step.value = data.step ?? 0;
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
        query: { from: 'dungeon' },
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
      <ScenePanel image-src="/assets/bg/dungeon1.jpg" :loading="loading">
        <p class="scene-overlay-depth">深さ {{ step }}</p>

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
    />
    <ExplorationChoiceOverlay
      v-if="choiceActive"
      :prompt="choicePrompt"
      :options="choiceOptions"
      :disabled="explorationBusy"
      @choose="onChoice"
    />
  </Teleport>
</template>
