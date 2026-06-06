<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useAppAudio } from '../../composables/useAppAudio.js';
import StoryCharacterSprite from './StoryCharacterSprite.vue';
import StoryTextWindow from './StoryTextWindow.vue';
import '../../styles/story-window.css';

const { playSe, unlock } = useAppAudio();

const props = defineProps({
  lines: { type: Array, required: true },
  party: { type: Array, default: () => [] },
});

const emit = defineEmits(['complete', 'sync-party', 'screen-fade']);

const index = ref(0);
const inputLocked = ref(false);
/** @type {ReturnType<typeof setTimeout> | null} */
let fadeTimer = null;

const currentLine = computed(() => props.lines[index.value] ?? null);

const currentMode = computed(() => {
  const type = currentLine.value?.type;
  if (type === 'dialogue') return 'dialogue';
  return 'narration';
});

const characterInfo = computed(() => {
  const code = currentLine.value?.character;
  if (!code) {
    return { name: 'イルミ', sprite: 'PC1' };
  }
  const member = props.party.find(
    (u) => u.slot_id === code || u.id === code,
  );
  return {
    name: member?.name ?? 'イルミ',
    sprite: member?.sprite ?? 'PC1',
  };
});

function clearFadeTimer() {
  if (fadeTimer !== null) {
    clearTimeout(fadeTimer);
    fadeTimer = null;
  }
}

function advanceLine() {
  if (index.value >= props.lines.length - 1) {
    emit('complete');
    return;
  }
  index.value += 1;
}

function runScreenFade(ms) {
  clearFadeTimer();
  emit('screen-fade', ms);
  inputLocked.value = true;
  fadeTimer = setTimeout(() => {
    inputLocked.value = false;
    fadeTimer = null;
    advanceLine();
  }, ms);
}

watch(
  () => props.lines,
  () => {
    clearFadeTimer();
    inputLocked.value = false;
    index.value = 0;
  },
);

function emitSyncPartyIfNeeded(lineIndex) {
  const line = props.lines[lineIndex];
  if (line?.sync_party) {
    emit('sync-party', Array.isArray(line.party) ? line.party : null);
  }
}

watch(index, (lineIndex) => {
  emitSyncPartyIfNeeded(lineIndex);
  const line = props.lines[lineIndex];
  const fadeMs = Number(line?.screen_fade_ms);
  if (fadeMs > 0) {
    runScreenFade(fadeMs);
  }
}, { immediate: true });

onUnmounted(() => {
  clearFadeTimer();
});

function onPointerDown() {
  if (inputLocked.value) return;
  unlock();
  playSe('cursor');
}

function onTap() {
  if (inputLocked.value) return;
  advanceLine();
}
</script>

<template>
  <button
    type="button"
    class="story-sequence"
    aria-label="次へ"
    @pointerdown="onPointerDown"
    @click="onTap"
  >
    <div class="story-sequence__viewport">
      <div class="story-sequence__scene">
        <div v-if="currentMode === 'dialogue' && currentLine" class="story-dialogue-stage">
          <StoryCharacterSprite
            variant="dialogue"
            :sprite="characterInfo.sprite"
            :alt="characterInfo.name"
          />
          <StoryTextWindow
            mode="dialogue"
            :text="currentLine.text"
            :character-name="characterInfo.name"
          />
        </div>
        <StoryTextWindow
          v-else-if="currentLine"
          mode="narration"
          :text="currentLine.text"
        />
      </div>
    </div>
  </button>
</template>
