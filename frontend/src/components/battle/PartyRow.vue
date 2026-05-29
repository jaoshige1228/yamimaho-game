<script setup>
import UnitBars from './UnitBars.vue';
import { useLongPress } from '../../composables/useLongPress';

const props = defineProps({
  units: { type: Array, required: true },
  currentActor: { type: String, default: null },
  targetMode: { type: String, default: null },
  shakeTarget: { type: String, default: null },
  canOpenCommands: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'open-commands', 'show-stats']);

const longPress = useLongPress((unit) => emit('show-stats', unit));

function spriteUrl(unit) {
  return `/assets/characters/${unit.sprite}.png`;
}

function canSelect(unit) {
  if (!props.targetMode?.startsWith('ally')) return false;
  if (!unit.alive) return false;
  return props.targetMode === 'ally_single';
}

function canOpenCommands(unit) {
  return (
    props.canOpenCommands &&
    props.currentActor === unit.id &&
    unit.alive &&
    !props.targetMode
  );
}

function onSlotClick(unit) {
  if (longPress.shouldSuppressClick()) {
    return;
  }
  if (canSelect(unit)) {
    emit('select', unit.id);
    return;
  }
  if (canOpenCommands(unit)) {
    emit('open-commands', unit.id);
  }
}
</script>

<template>
  <div class="party-row" :class="{ 'highlight-actor': !!currentActor }">
    <button
      v-for="unit in units"
      :key="unit.id"
      type="button"
      class="party-slot"
      :class="{
        active: currentActor === unit.id,
        dead: !unit.alive,
        selectable: canSelect(unit),
        commandable: canOpenCommands(unit),
        shake: shakeTarget === unit.id,
      }"
      @click="onSlotClick(unit)"
      @pointerdown="longPress.onPressStart($event, unit)"
      @pointerup="longPress.onPressEnd"
      @pointerleave="longPress.onPressEnd"
      @pointercancel="longPress.onPressCancel"
      @contextmenu.prevent
    >
      <div class="unit-sprite-frame party">
        <img :src="spriteUrl(unit)" :alt="unit.name" class="unit-sprite-bust" />
      </div>
      <div class="unit-meta">
        <UnitBars :hp="unit.hp" :max-hp="unit.max_hp" :mp="unit.mp" :max-mp="unit.max_mp" />
        <span class="name">{{ unit.name }}</span>
        <span v-if="canOpenCommands(unit)" class="tap-hint">タップして選ぶ</span>
      </div>
    </button>
  </div>
</template>

<style scoped>
.party-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.3rem;
  width: 100%;
  max-height: 480px;
  padding: 0 0.15rem;
}
.party-slot {
  border: 1px solid rgba(157, 124, 255, 0.38);
  border-radius: 8px;
  background: rgba(8, 5, 18, 0.55);
  padding: 0.25rem 0.2rem 0.3rem;
  cursor: default;
  color: var(--text);
  font-size: 0.7rem;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.25rem;
  min-height: 0;
  min-width: 0;
  touch-action: manipulation;
  -webkit-user-select: none;
  user-select: none;
}
.party-slot.commandable {
  cursor: pointer;
}
.party-slot.active {
  border-color: rgba(157, 124, 255, 0.95);
  box-shadow: 0 0 14px rgba(157, 124, 255, 0.45);
}
.party-slot.active .unit-sprite-frame {
  filter: drop-shadow(0 0 10px rgba(157, 124, 255, 0.75));
  animation: unitPulse 1.2s ease-in-out infinite;
}
.party-row.highlight-actor .party-slot:not(.active):not(.dead) .unit-sprite-bust {
  filter: saturate(0.55) brightness(0.85);
}
.party-slot.dead {
  opacity: 0.35;
}
.party-slot.selectable {
  cursor: pointer;
  border-color: var(--accent);
  border-style: dashed;
}
.party-slot.shake .unit-sprite-frame {
  animation: shake 0.35s ease;
}
.unit-meta {
  flex: 0 0 auto;
  min-width: 0;
}
.name {
  display: block;
  margin-top: 0.15rem;
  font-weight: 600;
  font-size: 0.62rem;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tap-hint {
  display: block;
  margin-top: 1px;
  font-size: 0.52rem;
  color: #b8a0ff;
  font-weight: 500;
  text-align: center;
  line-height: 1.2;
}
</style>
