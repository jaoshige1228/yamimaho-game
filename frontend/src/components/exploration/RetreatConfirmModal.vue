<script setup>
defineProps({
  visible: { type: Boolean, default: false },
});

const emit = defineEmits(['cancel', 'confirm']);
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="retreat-modal-backdrop" @click.self="emit('cancel')">
      <div class="retreat-modal" role="dialog" aria-modal="true" aria-labelledby="retreat-title">
        <p id="retreat-title" class="retreat-modal__message">
          撤退すると所持金を少し失います。よろしいですか？
        </p>
        <div class="retreat-modal__actions">
          <button type="button" class="retreat-modal__btn retreat-modal__btn--ghost" @click="emit('cancel')">
            いいえ
          </button>
          <button type="button" class="retreat-modal__btn retreat-modal__btn--primary" @click="emit('confirm')">
            はい
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.retreat-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 3000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(0, 0, 0, 0.65);
}
.retreat-modal {
  width: min(340px, 100%);
  padding: 1.25rem 1rem 1rem;
  border-radius: 12px;
  background: rgba(12, 8, 24, 0.96);
  border: 1px solid rgba(157, 124, 255, 0.45);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.55);
}
.retreat-modal__message {
  margin: 0 0 1.25rem;
  font-size: 0.95rem;
  line-height: 1.55;
  color: #f8f4ff;
  text-align: center;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
}
.retreat-modal__actions {
  display: flex;
  gap: 0.65rem;
  justify-content: center;
}
.retreat-modal__btn {
  min-width: 96px;
  min-height: 44px;
  padding: 0.5rem 1rem;
  border-radius: 999px;
  font-size: 0.95rem;
  font-weight: 700;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  cursor: pointer;
  touch-action: manipulation;
}
.retreat-modal__btn--ghost {
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: transparent;
  color: #f8f4ff;
}
.retreat-modal__btn--primary {
  border: none;
  background: linear-gradient(135deg, #7c5cff, #c45cff);
  color: #fff;
}
</style>
