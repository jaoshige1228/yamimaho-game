<script setup>
defineProps({
  visible: { type: Boolean, default: false },
  skill: { type: Object, default: null },
  showExecute: { type: Boolean, default: false },
});

const emit = defineEmits(['cancel', 'confirm-all']);
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="target-root">
      <div class="target-panel">
        <div v-if="skill" class="skill-info">
          <h4 class="skill-name">{{ skill.label }}</h4>
          <p v-if="skill.description" class="skill-desc">{{ skill.description }}</p>
          <p v-if="skill.mp_cost != null" class="skill-mp">消費 MP: {{ skill.mp_cost }}</p>
        </div>
        <div class="actions">
          <button type="button" class="btn" @click="emit('cancel')">やめる</button>
          <button v-if="showExecute" type="button" class="btn primary" @click="emit('confirm-all')">
            決定
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.target-root {
  position: fixed;
  top: calc(3.25rem + env(safe-area-inset-top));
  left: 50%;
  transform: translateX(-50%);
  z-index: 35;
  width: min(430px, calc(100vw - 1.5rem));
  pointer-events: none;
}
.target-panel {
  pointer-events: auto;
  background: rgba(0, 0, 0, 0.82);
  border: 1px solid rgba(157, 124, 255, 0.4);
  color: #fff;
  padding: 0.75rem 1rem;
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}
.skill-info {
  margin-bottom: 0.5rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.15);
}
.skill-name {
  margin: 0 0 0.25rem;
  font-size: 1rem;
  color: #e8dcff;
}
.skill-desc {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.45;
  color: #c8b8e8;
}
.skill-mp {
  margin: 0.35rem 0 0;
  font-size: 0.75rem;
  color: var(--mp, #5cb8ff);
}
.actions {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  margin-top: 0.65rem;
}
.skill-info + .actions {
  margin-top: 0;
}
.actions:only-child {
  margin-top: 0;
}
.btn {
  min-height: 44px;
  padding: 0.4rem 1.25rem;
  border-radius: 8px;
  border: 1px solid #666;
  background: #333;
  color: #fff;
  cursor: pointer;
  touch-action: manipulation;
}
.btn.primary {
  background: var(--accent, #7c5cff);
  border-color: transparent;
  font-weight: 700;
}
</style>
