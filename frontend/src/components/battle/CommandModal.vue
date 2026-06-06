<script setup>
import { useAppAudio } from '../../composables/useAppAudio.js';

defineProps({
  visible: { type: Boolean, default: false },
  commands: { type: Object, default: null },
  actorName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'punch', 'kick', 'spell']);

const { playSe, unlock } = useAppAudio();

function isSpellDisabled(spell) {
  return !spell.affordable;
}

function onChoose(event, payload) {
  unlock();
  playSe('cursor');
  emit(event, payload);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible && commands" class="command-modal-root" @click.self="emit('close')">
      <div class="command-modal" role="dialog" aria-modal="true" :aria-label="`${actorName}のコマンド`">
        <header class="modal-header">
          <h3>{{ actorName }} のコマンド</h3>
          <button type="button" class="close" aria-label="やめる" @click="emit('close')">×</button>
        </header>
        <div class="row basic">
          <button type="button" class="cmd" @click="onChoose('punch')">こぶし</button>
          <button type="button" class="cmd" @click="onChoose('kick')">キック</button>
        </div>
        <div class="spells">
          <button
            v-for="spell in commands.spells"
            :key="spell.id"
            type="button"
            class="cmd spell"
            :disabled="isSpellDisabled(spell)"
            @click="onChoose('spell', spell)"
          >
            <span class="label">{{ spell.label }}</span>
            <span class="mp">MP {{ spell.mp_cost }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.command-modal-root {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgba(0, 0, 0, 0.55);
  padding: 1rem;
  padding-bottom: calc(1rem + env(safe-area-inset-bottom));
}
.command-modal {
  width: min(430px, 100%);
  background: var(--panel);
  border-radius: 16px;
  padding: 0.85rem;
  backdrop-filter: blur(10px);
  box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.45);
}
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.65rem;
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
}
.row.basic {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}
.spells {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}
.cmd {
  min-height: 48px;
  border: 1px solid rgba(157, 124, 255, 0.4);
  border-radius: 10px;
  background: rgba(40, 28, 70, 0.9);
  color: var(--text);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}
.cmd:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.cmd.spell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 0.5rem;
}
.mp {
  font-size: 0.7rem;
  color: var(--mp);
  font-weight: 500;
}
</style>
