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
  /**
   * 成功・失敗が判明したときの「ダイスロールのログ」（{ text, result: 'success' | 'fail' }）。
   * 本文の 1 行目に、文字送りなしですぐ出す。成功は薄い青、失敗は薄い赤。
   */
  dice: { type: Object, default: null },
});

const textSource = toRef(props, 'text');
const { displayed, isComplete, skip } = useTypewriterText(textSource);

defineExpose({
  get isComplete() {
    return isComplete.value;
  },
  skip,
});
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
      <span
        v-if="dice?.text"
        class="story-text-window__dice"
        :class="dice.result === 'success' ? 'story-text-window__dice--success' : 'story-text-window__dice--fail'"
      >{{ dice.text }}</span>
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
