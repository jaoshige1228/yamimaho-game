<script setup>
import { computed, ref } from 'vue';
import StoryCharacterSprite from './StoryCharacterSprite.vue';
import StoryTextWindow from './StoryTextWindow.vue';
import '../../styles/story-window.css';

const props = defineProps({
  lines: { type: Array, required: true },
  party: { type: Array, default: () => [] },
});

const emit = defineEmits(['complete']);

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

function onTap() {
  if (index.value >= props.lines.length - 1) {
    emit('complete');
    return;
  }
  index.value += 1;
}
</script>

<template>
  <div class="story-sequence" role="button" tabindex="0" @click="onTap" @keydown.enter="onTap">
    <div class="story-sequence__viewport">
      <div class="story-sequence__scene">
        <StoryCharacterSprite
          v-if="currentMode === 'dialogue'"
          :sprite="characterInfo.sprite"
          :alt="characterInfo.name"
        />
        <StoryTextWindow
          v-if="currentLine"
          :mode="currentMode"
          :text="currentLine.text"
          :character-name="currentMode === 'dialogue' ? characterInfo.name : ''"
        />
      </div>
    </div>
  </div>
</template>
