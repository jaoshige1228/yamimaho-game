<script setup>
defineProps({
  mode: {
    type: String,
    required: true,
    validator: (v) => v === 'narration' || v === 'dialogue',
  },
  text: { type: String, required: true },
  characterName: { type: String, default: '' },
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
      <template v-if="mode === 'dialogue'">「{{ text }}」</template>
      <template v-else>{{ text }}</template>
    </p>
  </div>
</template>
