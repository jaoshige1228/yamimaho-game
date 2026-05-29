<script setup>
import { computed } from 'vue';

const props = defineProps({
  sprite: { type: String, required: true },
  alt: { type: String, default: '' },
});

const imageSrc = computed(() => `/assets/characters/${props.sprite}.png`);
</script>

<template>
  <div class="story-character-sprite">
    <img :src="imageSrc" :alt="alt" class="story-character-sprite__image" />
  </div>
</template>
