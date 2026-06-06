<script setup>
import { computed, ref, watch } from 'vue';
import { useAppAudio } from '../../composables/useAppAudio.js';
import StoryCharacterSprite from './StoryCharacterSprite.vue';
import StoryTextWindow from './StoryTextWindow.vue';
import '../../styles/story-window.css';

const { playSe, unlock } = useAppAudio();

const props = defineProps({
  lines: { type: Array, required: true },
  party: { type: Array, default: () => [] },
});

const emit = defineEmits(['complete', 'sync-party']);

const index = ref(0);

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

watch(
  () => props.lines,
  () => {
    index.value = 0;
  },
);

function emitSyncPartyIfNeeded(lineIndex) {
  const line = props.lines[lineIndex];
  if (line?.sync_party) {
    emit('sync-party');
  }
}

watch(index, (lineIndex) => {
  emitSyncPartyIfNeeded(lineIndex);
}, { immediate: true });

function onPointerDown() {
  unlock();
  playSe('cursor');
}

function onTap() {
  if (index.value >= props.lines.length - 1) {
    emit('complete');
    return;
  }
  index.value += 1;
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
