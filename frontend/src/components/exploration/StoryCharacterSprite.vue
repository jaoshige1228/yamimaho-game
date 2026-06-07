<script setup>
import { computed } from 'vue';
import { characterSpriteUrls } from '../../utils/publicAssetUrl.js';

const props = defineProps({
  sprite: { type: String, required: true },
  alt: { type: String, default: '' },
  variant: {
    type: String,
    default: 'default',
    validator: (v) => v === 'default' || v === 'dialogue',
  },
});

const imageUrls = computed(() => characterSpriteUrls(props.sprite));
</script>

<template>
  <div
    class="story-character-sprite"
    :class="variant === 'dialogue' ? 'story-character-sprite--dialogue' : null"
  >
    <picture>
      <source :srcset="imageUrls.webp" type="image/webp" />
      <img
        :src="imageUrls.png"
        :alt="alt"
        class="story-character-sprite__image"
        decoding="async"
      />
    </picture>
  </div>
</template>
