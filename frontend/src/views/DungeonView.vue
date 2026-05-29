<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
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
const error = ref('');
const lastMessage = ref('');
const step = ref(0);
const party = ref([]);
const storyActive = ref(false);
const storyLines = ref([]);

function onStoryComplete() {
  storyActive.value = false;
  storyLines.value = [];
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
  try {
    const data = await api('/dungeon/advance', { method: 'POST' });

    if (data.event === 'message') {
      lastMessage.value = data.text ?? '';
      step.value = data.step ?? step.value;
      return;
    }

    if (data.event === 'story') {
      step.value = data.step ?? step.value;
      storyLines.value = data.lines ?? [];
      storyActive.value = true;
      lastMessage.value = '';
      return;
    }

    if (data.event === 'battle') {
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

        <p v-if="lastMessage && !storyActive" class="scene-overlay-message">{{ lastMessage }}</p>

        <button
          v-if="!loading && !storyActive"
          type="button"
          class="scene-overlay-advance"
          :disabled="advancing"
          @click="advance"
        >
          {{ advancing ? '進行中…' : '進む' }}
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
  </Teleport>
</template>
