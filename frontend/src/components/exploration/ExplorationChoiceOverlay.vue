<script setup>
defineProps({
  prompt: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['choose']);
</script>

<template>
  <div class="exploration-choice" role="dialog" aria-modal="true">
    <div class="exploration-choice__panel">
      <p v-if="prompt" class="exploration-choice__prompt">{{ prompt }}</p>
      <ul class="exploration-choice__list">
        <li v-for="opt in options" :key="opt.slot_id">
          <button
            type="button"
            class="exploration-choice__btn"
            :disabled="disabled"
            @click="emit('choose', opt.slot_id)"
          >
            {{ opt.label }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.exploration-choice {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: 1rem 1rem calc(var(--hub-party-band-height, 31dvh) + 1rem);
  background: rgba(0, 0, 0, 0.45);
  box-sizing: border-box;
}

.exploration-choice__panel {
  width: min(100%, 28rem);
  padding: 1rem;
  border-radius: 0.5rem;
  background: rgba(20, 16, 32, 0.92);
  color: #f5f0e8;
  border: 1px solid rgba(255, 255, 255, 0.15);
}

.exploration-choice__prompt {
  margin: 0 0 0.75rem;
  font-size: 0.95rem;
  line-height: 1.5;
}

.exploration-choice__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.exploration-choice__btn {
  width: 100%;
  padding: 0.65rem 1rem;
  font-size: 1rem;
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 0.35rem;
  background: rgba(60, 48, 90, 0.9);
  color: inherit;
  cursor: pointer;
}

.exploration-choice__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
