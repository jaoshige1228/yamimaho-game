<script setup>
import BuffIcons from './BuffIcons.vue';
import UnitBars from './UnitBars.vue';
import { useLongPress } from '../../composables/useLongPress';
import { enemySpriteAssetKey } from '../../utils/enemySprite';
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

const props = defineProps({
  units: { type: Array, required: true },
  displayHp: { type: Object, default: () => ({}) },
  buffIcons: { type: Object, default: () => ({}) },
  targetMode: { type: String, default: null },
  shakeTarget: { type: String, default: null },
  supportEffectTarget: { type: Object, default: null },
  activeLunge: { type: String, default: null },
});

function supportEffectClass(unit) {
  const effect = props.supportEffectTarget;
  if (!effect || effect.id !== unit.id) return null;
  return `support-${effect.kind}`;
}

function displayHpFor(unit) {
  return props.displayHp[unit.id] ?? unit.hp;
}

const emit = defineEmits(['select', 'show-stats']);

const longPress = useLongPress((unit) => emit('show-stats', unit));

function spriteUrl(unit) {
  return publicAssetUrl(`/assets/enemies/${enemySpriteAssetKey(unit)}.png`);
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
      :class="[
        {
          dead: !unit.alive,
          selectable: canSelect(unit),
          shake: shakeTarget === unit.id,
          lunge: activeLunge === unit.id,
        },
        supportEffectClass(unit),
      ]"
      @click="onSlotClick(unit)"
      @pointerdown="longPress.onPressStart($event, unit)"
      @pointerup="longPress.onPressEnd"
      @pointerleave="longPress.onPressEnd"
      @pointercancel="longPress.onPressCancel"
      @contextmenu.prevent
    >
      <div class="enemy-sprite-wrap">
        <img :src="spriteUrl(unit)" :alt="unit.name" class="sprite" />
        <BuffIcons
          :buffs="unit.buffs"
          :buff-icons="buffIcons"
          :damage-shield="unit.damage_shield"
        />
      </div>
      <UnitBars :hp="displayHpFor(unit)" :max-hp="unit.max_hp" />
      <span class="name">Lv{{ unit.level ?? 1 }} {{ unit.name }}</span>
    </button>
  </div>
</template>

<style scoped>
.enemy-row {
  display: flex;
  justify-content: center;
  align-items: flex-end;
  gap: 0.5rem;
  width: 100%;
  z-index: 1;
}
.enemy-slot {
  flex: 1;
  max-width: 120px;
  min-width: 0;
  border: none;
  background: transparent;
  padding: 0.2rem 0.25rem 0.35rem;
  color: var(--text);
  font-size: 0.7rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
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
.enemy-sprite-wrap {
  position: relative;
  width: 100%;
}
.sprite {
  width: 100%;
  max-height: 88px;
  object-fit: contain;
  transform: scale(1.05);
  transform-origin: bottom center;
  filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.5));
  pointer-events: none;
}
.name {
  display: block;
  flex-shrink: 0;
  font-size: 0.62rem;
  font-weight: 600;
  line-height: 1.35;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding-bottom: 1px;
}
</style>
