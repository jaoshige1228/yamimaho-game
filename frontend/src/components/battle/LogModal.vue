<script setup>
defineProps({
  visible: { type: Boolean, default: false },
  lines: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="log-modal-root" @click.self="emit('close')">
      <div class="log-modal" role="dialog" aria-modal="true" aria-label="戦いの記録">
        <header class="modal-header">
          <h3>戦いの記録</h3>
          <button type="button" class="close" aria-label="やめる" @click="emit('close')">×</button>
        </header>
        <div class="log-body">
          <p v-if="lines.length === 0" class="empty">まだ記録はありません</p>
          <p v-for="(line, i) in lines" :key="i">{{ line }}</p>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.log-modal-root {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 3.5rem 1rem 1rem;
  background: rgba(0, 0, 0, 0.5);
}
.log-modal {
  width: min(430px, 100%);
  max-height: 60vh;
  background: rgba(12, 8, 24, 0.95);
  border: 1px solid rgba(157, 124, 255, 0.35);
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.modal-header h3 {
  margin: 0;
  font-size: 0.9rem;
  color: var(--text);
}
.close {
  border: none;
  background: transparent;
  color: #aaa;
  font-size: 1.5rem;
  min-width: 44px;
  min-height: 44px;
  cursor: pointer;
}
.log-body {
  overflow-y: auto;
  padding: 0.75rem 0.85rem;
  font-size: 0.8rem;
  line-height: 1.5;
  color: #c8b8e8;
}
.log-body p {
  margin: 0.2rem 0;
}
.empty {
  color: #888;
}
</style>
