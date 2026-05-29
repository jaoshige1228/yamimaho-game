<script setup>
import { computed } from 'vue';
import CharacterStatCard from '../character/CharacterStatCard.vue';

const props = defineProps({
  visible: { type: Boolean, default: false },
  unit: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const sideLabel = computed(() => {
  if (!props.unit) {
    return '';
  }
  return props.unit.side === 'enemy' ? '敵' : '味方';
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible && unit"
      class="stats-modal-root"
      @click.self="emit('close')"
    >
      <div
        class="stats-modal"
        role="dialog"
        aria-modal="true"
        :aria-label="`${unit.name}のステータス`"
      >
        <header class="modal-header">
          <div class="title-block">
            <span class="side-tag">{{ sideLabel }}</span>
            <h3>{{ unit.name }}のステータス</h3>
          </div>
          <button type="button" class="close" aria-label="やめる" @click="emit('close')">×</button>
        </header>

        <CharacterStatCard :unit="unit" />
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.stats-modal-root {
  position: fixed;
  inset: 0;
  z-index: 45;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  padding-top: calc(1rem + env(safe-area-inset-top));
  padding-bottom: calc(1rem + env(safe-area-inset-bottom));
  background: rgba(0, 0, 0, 0.6);
}
.stats-modal {
  width: min(360px, 100%);
  background: rgba(12, 8, 24, 0.96);
  border: 1px solid rgba(157, 124, 255, 0.4);
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.55);
}
.modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.75rem 0.85rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.title-block {
  min-width: 0;
}
.side-tag {
  display: inline-block;
  margin-bottom: 0.2rem;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  background: rgba(157, 124, 255, 0.18);
  color: #c8b0ff;
  font-size: 0.62rem;
  font-weight: 600;
}
.modal-header h3 {
  margin: 0;
  font-size: 0.95rem;
  color: var(--text);
}
.close {
  border: none;
  background: transparent;
  color: #aaa;
  font-size: 1.5rem;
  line-height: 1;
  min-width: 44px;
  min-height: 44px;
  cursor: pointer;
  flex-shrink: 0;
}
.stats-modal :deep(.stat-card) {
  border: none;
  border-radius: 0;
  background: transparent;
}
</style>
