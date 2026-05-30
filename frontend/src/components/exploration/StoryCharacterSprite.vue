<script setup>
import { computed } from 'vue';

const props = defineProps({
  sprite: { type: String, required: true },
  alt: { type: String, default: '' },
  variant: {
    type: String,
    default: 'default',
    validator: (v) => v === 'default' || v === 'dialogue',
  },
});

const imageSrc = computed(() => `/assets/characters/${props.sprite}.png`);
</script>

<template>
  <div
    class="story-character-sprite"
    :class="variant === 'dialogue' ? 'story-character-sprite--dialogue' : null"
  >
    <img :src="imageSrc" :alt="alt" class="story-character-sprite__image" />
  </div>
</template>
