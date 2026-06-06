<script setup>
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { PLACEHOLDER_DAY } from '../../config/display';
import { usePlayerStore } from '../../stores/player';

const player = usePlayerStore();
const { gold } = storeToRefs(player);

const goldLabel = computed(() => `${gold.value.toLocaleString('ja-JP')}G`);
</script>

<template>
  <div class="game-hud-bar">
    <span class="day">DAY {{ PLACEHOLDER_DAY }}</span>
    <span class="gold">{{ goldLabel }}</span>
  </div>
</template>

<style scoped>
.game-hud-bar {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: min(430px, 100vw);
  margin: 0 auto;
  padding: 0.35rem 0.85rem 0.45rem;
  box-sizing: border-box;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
}
.day {
  font-size: 1.35rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: #f8f4ff;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
}
.gold {
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  color: #ffd54a;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
}
</style>
