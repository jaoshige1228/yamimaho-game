<script setup>
import { computed } from 'vue';

// 防御・攻撃のバフ／デバフは「段階」（-3〜+3）。バッジの矢印の数が段階の大きさ（防御↑↑ = 2段階上昇、防御↓↓↓ = 3段階低下）
const STAGE_LABELS = { def: '防御', atk: '攻撃' };

const ICON_LABELS = {
  evasion: '回避',
  invincible: '無敵',
  life_ward: '加護',
};

const props = defineProps({
  buffs: { type: Object, default: () => ({}) },
  /** 防御・攻撃の現在の段階 { def, atk }（-3〜+3。0 は表示しない） */
  stages: { type: Object, default: () => ({}) },
  buffIcons: { type: Object, default: () => ({}) },
  damageShield: { type: Boolean, default: false },
  lifeWard: { type: Boolean, default: false },
});

function stageLabel(stat, stage) {
  return `${STAGE_LABELS[stat]}${(stage > 0 ? '↑' : '↓').repeat(Math.abs(stage))}`;
}

const entries = computed(() => {
  const list = [];
  for (const stat of ['def', 'atk']) {
    const stage = Number(props.stages?.[stat] ?? 0);
    if (stage !== 0) {
      list.push({ key: `stage_${stat}`, label: stageLabel(stat, stage), isEvasion: false, isInvincible: false });
    }
  }
  if (props.damageShield) {
    list.push({ key: 'damage_shield', label: ICON_LABELS.invincible, isEvasion: false, isInvincible: true });
  }
  if (props.lifeWard) {
    list.push({ key: 'life_ward', label: ICON_LABELS.life_ward, isEvasion: false, isInvincible: true });
  }
  for (const [key, turns] of Object.entries(props.buffs ?? {})) {
    if (!turns || turns <= 0) continue;
    const iconKey = props.buffIcons[key] ?? key;
    const label = ICON_LABELS[iconKey] ?? iconKey;
    list.push({ key, label, isEvasion: iconKey === 'evasion', isInvincible: false });
  }
  return list;
});
</script>

<template>
  <div v-if="entries.length" class="buff-icons">
    <span
      v-for="entry in entries"
      :key="entry.key"
      class="buff-badge"
      :class="{ evasion: entry.isEvasion, invincible: entry.isInvincible }"
    >
      {{ entry.label }}
    </span>
  </div>
</template>

<style scoped>
.buff-icons {
  position: absolute;
  left: 2px;
  bottom: 2px;
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  max-width: calc(100% - 4px);
  pointer-events: none;
  z-index: 2;
}
.buff-badge {
  font-size: 0.48rem;
  font-weight: 700;
  line-height: 1.1;
  padding: 1px 3px;
  border-radius: 3px;
  background: rgba(20, 12, 40, 0.88);
  border: 1px solid rgba(157, 124, 255, 0.55);
  color: #e8dcff;
  white-space: nowrap;
}
.buff-badge.evasion {
  border-color: rgba(126, 200, 255, 0.65);
  color: #b8e8ff;
}
.buff-badge.invincible {
  border-color: rgba(255, 220, 120, 0.75);
  color: #fff0b0;
  background: rgba(48, 36, 12, 0.92);
}
</style>
