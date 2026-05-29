<script setup>
defineProps({
  imageSrc: { type: String, required: true },
  loading: { type: Boolean, default: false },
});
</script>

<template>
  <section class="scene-panel">
    <img :src="imageSrc" alt="" class="scene-panel__image" />
    <div v-if="loading" class="scene-loading">読み込み中…</div>
    <div class="scene-panel__overlays">
      <slot />
    </div>
  </section>
</template>
