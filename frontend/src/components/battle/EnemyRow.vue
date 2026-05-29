<script setup>
import UnitBars from './UnitBars.vue';
import { useLongPress } from '../../composables/useLongPress';

const props = defineProps({
  units: { type: Array, required: true },
  targetMode: { type: String, default: null },
  shakeTarget: { type: String, default: null },
  activeLunge: { type: String, default: null },
});

const emit = defineEmits(['select', 'show-stats']);

const longPress = useLongPress((unit) => emit('show-stats', unit));

function spriteUrl(unit) {
  return `/assets/enemies/${unit.sprite}.png`;
}

function canSelect(unit) {
  if (!props.targetMode?.startsWith('enemy')) return false;
  return unit.alive && props.targetMode === 'enemy_single';
}

function onSlotClick(unit) {
  if (longPress.shouldSuppressClick()) {
    return;
  }
  if (canSelect(unit)) {
    emit('select', unit.id);
  }
}
</script>

<template>
  <div class="enemy-row">
    <button
      v-for="unit in units"
      :key="unit.id"
      type="button"
      class="enemy-slot"
      :class="{
        dead: !unit.alive,
        selectable: canSelect(unit),
        shake: shakeTarget === unit.id,
        lunge: activeLunge === unit.id,
      }"
      @click="onSlotClick(unit)"
      @pointerdown="longPress.onPressStart($event, unit)"
      @pointerup="longPress.onPressEnd"
      @pointerleave="longPress.onPressEnd"
      @pointercancel="longPress.onPressCancel"
      @contextmenu.prevent
    >
      <img :src="spriteUrl(unit)" :alt="unit.name" class="sprite" />
      <UnitBars :hp="unit.hp" :max-hp="unit.max_hp" />
      <span class="name">{{ unit.name }}</span>
    </button>
  </div>
</template>

<style scoped>
.enemy-row {
  display: flex;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  max-height: 150px;
  z-index: 1;
}
.enemy-slot {
  flex: 1;
  max-width: 120px;
  min-width: 0;
  border: none;
  background: transparent;
  padding: 0.25rem;
  color: var(--text);
  font-size: 0.7rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  touch-action: manipulation;
  -webkit-user-select: none;
  user-select: none;
}
.enemy-slot.dead {
  opacity: 0.3;
}
.enemy-slot.selectable {
  cursor: pointer;
  outline: 2px dashed #ff9d5c;
  outline-offset: 4px;
  border-radius: 12px;
}
.enemy-slot.shake .sprite {
  animation: shake 0.35s ease;
}
.enemy-slot.lunge .sprite {
  animation: enemyLunge 0.5s ease;
}
.sprite {
  width: 100%;
  max-height: 100px;
  object-fit: contain;
  transform: scale(1.08);
  transform-origin: bottom center;
  filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.5));
  pointer-events: none;
}
.name {
  display: block;
  font-size: 0.62rem;
  font-weight: 600;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
