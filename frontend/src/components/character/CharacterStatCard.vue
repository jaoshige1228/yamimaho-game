<script setup>
import { computed } from 'vue';
import UnitBars from '../battle/UnitBars.vue';
import { enemySpriteAssetKey } from '../../utils/enemySprite';
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

const props = defineProps({
  unit: { type: Object, required: true },
  compact: { type: Boolean, default: false },
  wide: { type: Boolean, default: false },
});

const statRows = [
  { key: 'str', label: '筋力' },
  { key: 'mag', label: '魔力' },
  { key: 'def', label: '防御' },
  { key: 'spd', label: '素早' },
  { key: 'know', label: '知力' },
  { key: 'spirit', label: '精神' },
  { key: 'vit', label: '体力' },
];

const spriteUrl = computed(() => {
  const folder = props.unit.side === 'enemy' ? 'enemies' : 'characters';
  const key =
    props.unit.side === 'enemy'
      ? enemySpriteAssetKey(props.unit)
      : props.unit.sprite;
  return publicAssetUrl(`/assets/${folder}/${key}.png`);
});

const showEquipment = computed(
  () => props.unit.side === 'ally' && (props.unit.weapon || props.unit.armor),
);
</script>

<template>
  <article class="stat-card" :class="{ compact, wide }">
    <div class="card-body">
      <header class="card-header">
        <div class="sprite-frame">
          <img :src="spriteUrl" :alt="unit.name" class="sprite" />
        </div>
        <div class="title-block">
          <h3>{{ unit.name }}</h3>
          <p v-if="unit.level" class="level">Lv {{ unit.level }}</p>
          <UnitBars
            :hp="unit.hp"
            :max-hp="unit.max_hp"
            :mp="unit.mp"
            :max-mp="unit.max_mp"
          />
          <p v-if="!unit.alive" class="status-note">戦闘不能</p>
          <p v-else-if="unit.defending" class="status-note defending">防御の構え</p>
        </div>
      </header>

    </div>

    <template v-if="wide">
      <dl v-if="showEquipment" class="equipment-list equipment-list-wide">
        <div v-if="unit.weapon" class="equipment-row">
          <dt>武器</dt>
          <dd>
            <span class="equipment-name">{{ unit.weapon.name }} (+{{ unit.weapon.mag_bonus }} 魔力)</span>
            <span v-if="unit.weapon.description" class="equipment-desc">{{ unit.weapon.description }}</span>
          </dd>
        </div>
        <div v-if="unit.armor" class="equipment-row">
          <dt>防具</dt>
          <dd>
            <span class="equipment-name">{{ unit.armor.name }} (+{{ unit.armor.def_bonus }} 防御)</span>
            <span v-if="unit.armor.description" class="equipment-desc">{{ unit.armor.description }}</span>
          </dd>
        </div>
      </dl>

      <dl class="stat-grid stat-grid-wide">
        <div v-for="row in statRows" :key="row.key" class="stat-row">
          <dt>{{ row.label }}</dt>
          <dd>{{ unit[row.key] ?? '—' }}</dd>
        </div>
      </dl>
    </template>

    <template v-else>
      <dl v-if="showEquipment" class="equipment-list">
        <div v-if="unit.weapon" class="equipment-row">
          <dt>武器</dt>
          <dd>
            <span class="equipment-name">{{ unit.weapon.name }} (+{{ unit.weapon.mag_bonus }} 魔力)</span>
            <span v-if="unit.weapon.description" class="equipment-desc">{{ unit.weapon.description }}</span>
          </dd>
        </div>
        <div v-if="unit.armor" class="equipment-row">
          <dt>防具</dt>
          <dd>
            <span class="equipment-name">{{ unit.armor.name }} (+{{ unit.armor.def_bonus }} 防御)</span>
            <span v-if="unit.armor.description" class="equipment-desc">{{ unit.armor.description }}</span>
          </dd>
        </div>
      </dl>

      <dl class="stat-grid">
        <div v-for="row in statRows" :key="row.key" class="stat-row">
          <dt>{{ row.label }}</dt>
          <dd>{{ unit[row.key] ?? '—' }}</dd>
        </div>
      </dl>
    </template>
  </article>
</template>

<style scoped>
.stat-card {
  background: rgba(12, 8, 24, 0.96);
  border: 1px solid rgba(157, 124, 255, 0.35);
  border-radius: 12px;
  overflow: hidden;
}
.stat-card.compact {
  font-size: 0.92rem;
}
.stat-card.wide {
  width: 100%;
  border-radius: 10px;
}
.card-body {
  display: block;
}
.stat-card.wide .card-header {
  padding: 0.4rem 0.55rem;
  gap: 0.4rem;
  border-bottom: none;
}
.stat-card.wide .sprite-frame {
  flex: 0 0 48px;
  height: 58px;
  border-radius: 6px;
}
.stat-card.wide .title-block h3 {
  font-size: 0.88rem;
  line-height: 1.2;
}
.stat-card.wide .level {
  margin: 0 0 0.15rem;
  font-size: 0.65rem;
}
.stat-card.wide :deep(.unit-bars) {
  gap: 0.18rem;
}
.stat-card.wide :deep(.bar-track) {
  height: 7px;
  border-radius: 4px;
}
.stat-card.wide :deep(.label),
.stat-card.wide :deep(.value) {
  font-size: 0.54rem;
}
.equipment-list-wide {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.15rem 0.5rem;
  padding: 0 0.55rem 0.35rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.equipment-list-wide .equipment-row {
  font-size: 0.6rem;
  line-height: 1.25;
}
.equipment-list-wide .equipment-row + .equipment-row {
  margin-top: 0;
}
.equipment-list-wide .equipment-row dt {
  flex: 0 0 1.8rem;
}
.stat-grid-wide {
  grid-template-columns: repeat(3, 1fr);
  padding: 0.35rem 0.55rem 0.45rem;
  gap: 0.28rem;
}
.stat-card.wide .stat-row {
  padding: 0.22rem 0.35rem;
  border-radius: 5px;
}
.stat-card.wide .stat-row dt {
  font-size: 0.58rem;
}
.stat-card.wide .stat-row dd {
  font-size: 0.72rem;
}
.card-header {
  display: flex;
  gap: 0.55rem;
  padding: 0.65rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.sprite-frame {
  flex: 0 0 64px;
  height: 80px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
  overflow: hidden;
}
.compact .sprite-frame {
  flex-basis: 52px;
  height: 68px;
}
.sprite {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.45));
}
.title-block {
  flex: 1;
  min-width: 0;
}
.title-block h3 {
  margin: 0;
  font-size: 0.95rem;
  color: var(--text, #f8f4ff);
}
.compact .title-block h3 {
  font-size: 0.85rem;
}
.level {
  margin: 0.1rem 0 0.35rem;
  font-size: 0.72rem;
  color: #d4c4ff;
  font-weight: 600;
}
.status-note {
  margin: 0.25rem 0 0;
  font-size: 0.65rem;
  color: #ff8a8a;
  font-weight: 600;
}
.status-note.defending {
  color: #8ec5ff;
}
.equipment-list {
  margin: 0;
  padding: 0.5rem 0.65rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.equipment-row {
  display: flex;
  gap: 0.35rem;
  font-size: 0.68rem;
  line-height: 1.4;
}
.equipment-row + .equipment-row {
  margin-top: 0.25rem;
}
.equipment-row dt {
  flex: 0 0 2.2rem;
  margin: 0;
  color: #b8a8d8;
  font-weight: 600;
}
.equipment-row dd {
  margin: 0;
  color: #e8dcff;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}
.equipment-desc {
  display: block;
  font-size: 0.62rem;
  line-height: 1.35;
  color: #a898c8;
  font-weight: 400;
}
.equipment-list-wide .equipment-desc {
  font-size: 0.55rem;
}
.stat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
  margin: 0;
  padding: 0.55rem 0.65rem 0.65rem;
}
.stat-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.25rem;
  padding: 0.35rem 0.45rem;
  border-radius: 6px;
  background: rgba(40, 28, 70, 0.55);
  border: 1px solid rgba(157, 124, 255, 0.12);
}
.stat-row dt {
  margin: 0;
  font-size: 0.65rem;
  color: #b8a8d8;
}
.stat-row dd {
  margin: 0;
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--text, #f8f4ff);
}
.compact .stat-row dt {
  font-size: 0.6rem;
}
.compact .stat-row dd {
  font-size: 0.75rem;
}
</style>
