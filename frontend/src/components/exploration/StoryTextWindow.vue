<script setup>
import { toRef } from 'vue';
import { useTypewriterText } from '../../composables/useTypewriterText.js';

const props = defineProps({
  mode: {
    type: String,
    required: true,
    validator: (v) => v === 'narration' || v === 'dialogue',
  },
  text: { type: String, required: true },
  characterName: { type: String, default: '' },
});

const textSource = toRef(props, 'text');
const { displayed, isComplete } = useTypewriterText(textSource);
</script>

<template>
  <div
    class="story-text-window"
    :class="mode === 'narration' ? 'story-text-window--narration' : 'story-text-window--dialogue'"
  >
    <p v-if="mode === 'dialogue' && characterName" class="story-text-window__name">
      {{ characterName }}
    </p>
    <p class="story-text-window__body">
      <template v-if="mode === 'dialogue'">
        <span class="story-text-window__quote-open">「</span>{{ displayed }}<span
          v-if="isComplete"
          class="story-text-window__quote-close"
        >」</span>
      </template>
      <template v-else>{{ displayed }}</template>
    </p>
  </div>
</template>
