<script setup>
import { computed } from 'vue';
import BuffIcons from './BuffIcons.vue';
import UnitBars from './UnitBars.vue';
import { enemySpriteAssetKey, enemySpriteUrl } from '../../utils/enemySprite';

const props = defineProps({
  units: { type: Array, required: true },
  displayHp: { type: Object, default: () => ({}) },
  buffIcons: { type: Object, default: () => ({}) },
  targetMode: { type: String, default: null },
  shakeTarget: { type: String, default: null },
  supportEffectTarget: { type: Object, default: null },
  activeLunge: { type: String, default: null },
  floor1BossSprite: { type: Boolean, default: false },
  /** ボス戦（階層ボス・中ボス）。通常戦より大きく見せて迫力を出す */
  bossBattle: { type: Boolean, default: false },
  fledIds: { type: Array, default: () => [] },
});

function supportEffectClass(unit) {
  const effect = props.supportEffectTarget;
  if (!effect || effect.id !== unit.id) return null;
  return `support-${effect.kind}`;
}

function displayHpFor(unit) {
  if (unit.id in props.displayHp) {
    return props.displayHp[unit.id];
  }
  return unit.hp;
}

function isDisplayDead(unit) {
  return displayHpFor(unit) <= 0;
}

/** 逃げ出した敵（逃走の演出が済んだあとは画面から消す） */
function isDisplayFled(unit) {
  return props.fledIds.includes(unit.id);
}

const emit = defineEmits(['select']);

function spriteUrl(unit) {
  return enemySpriteUrl(unit);
}

/**
 * NPC（グース／カース）の画像は縦長（600×1200）だが、絵があるのは上の約 40% だけで下は透明。
 * そのまま枠に収めると絵が小さく上に寄るので、絵のある上の部分だけを切り出して見せる。
 */
function isPortrait(unit) {
  return enemySpriteAssetKey(unit).startsWith('npc_');
}

/**
 * 横長の画像（絵が中央に小さくまとまっていて、左右は薄い光や余白）。
 * 枠の幅に合わせると絵が小さくなるので、枠からはみ出して大きく描く。値は 画像の縦横比（幅 / 高さ）。
 */
const WIDE_SPRITE_ASPECT = {
  '3_zero': 1280 / 600,
};

function wideAspect(unit) {
  return WIDE_SPRITE_ASPECT[enemySpriteAssetKey(unit)] ?? null;
}

function spriteStyle(unit) {
  const aspect = wideAspect(unit);
  return aspect ? { '--sprite-aspect': String(aspect) } : null;
}

function canSelect(unit) {
  if (!props.targetMode?.startsWith('enemy')) return false;
  return unit.alive && props.targetMode === 'enemy_single';
}

/**
 * 1 体あたりの幅の上限（px）。敵が少ないほど大きく見せる（高さは敵エリアいっぱいまで使い、画像の縦横比は保つ）。
 * 通常戦は小さめ、ボス戦（階層ボス・中ボス）は迫力を出すため大きいまま。
 * 敵の大きさを変えたいときは、ここの数字を直す。
 */
const SLOT_MAX_WIDTH = {
  regular: { one: 160, two: 125, many: 95 },
  boss: { one: 260, two: 200, many: 140 },
};

const rowStyle = computed(() => {
  const count = props.units.length;
  const table = props.bossBattle ? SLOT_MAX_WIDTH.boss : SLOT_MAX_WIDTH.regular;
  const maxWidth = count <= 1 ? table.one : count === 2 ? table.two : table.many;
  return { '--enemy-slot-max': `${maxWidth}px` };
});

function onSlotClick(unit) {
  if (canSelect(unit)) {
    emit('select', unit.id);
  }
}
</script>

<template>
  <div class="enemy-row" :style="rowStyle">
    <button
      v-for="unit in units"
      :key="unit.id"
      type="button"
      class="enemy-slot"
      :class="[
        {
          dead: isDisplayDead(unit),
          fled: isDisplayFled(unit),
          selectable: canSelect(unit),
          shake: shakeTarget === unit.id,
          lunge: activeLunge === unit.id,
          'boss-floor1': floor1BossSprite,
        },
        supportEffectClass(unit),
      ]"
      @click="onSlotClick(unit)"
      @contextmenu.prevent
    >
      <div class="enemy-sprite-wrap">
        <img
          :src="spriteUrl(unit)"
          :alt="unit.name"
          class="sprite"
          :class="{ portrait: isPortrait(unit), wide: wideAspect(unit) !== null }"
          :style="spriteStyle(unit)"
        />
        <BuffIcons
          :buffs="unit.buffs"
          :stages="unit.stages"
          :buff-icons="buffIcons"
          :damage-shield="unit.damage_shield"
          :life-ward="unit.life_ward"
        />
      </div>
      <UnitBars :hp="displayHpFor(unit)" :max-hp="unit.max_hp" />
      <span class="name">{{ unit.name }}</span>
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
  max-width: var(--enemy-slot-max, 120px);
  min-width: 0;
  border: none;
  background: transparent;
  padding: 0.2rem 0.25rem 0.15rem;
  color: var(--text);
  font-size: 0.7rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  touch-action: manipulation;
  -webkit-user-select: none;
  user-select: none;
  transition: opacity 0.45s ease, transform 0.45s ease;
}
.enemy-slot.dead {
  opacity: 0.3;
}
.enemy-slot.fled {
  opacity: 0;
  transform: translateX(18px);
  pointer-events: none;
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
/*
 * 画像は枠の幅いっぱいの正方形（縦横比は contain で保つ）。高さは、敵エリア（.field-top。コンテナ）の
 * 高さから名前・HP バー分を引いた分までに収める。cqh が使えない環境では 180px が上限。
 */
.sprite {
  display: block;
  width: 100%;
  aspect-ratio: 1 / 1;
  max-height: 180px;
  max-height: calc(100cqh - 56px);
  object-fit: contain;
  object-position: center bottom;
  transform: scale(1.05);
  transform-origin: bottom center;
  filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.5));
  pointer-events: none;
}
/* 横長の画像は、枠の 2.6 倍の幅（ただし 420px まで）で、枠の中央に合わせてはみ出して描く */
.sprite.wide {
  aspect-ratio: var(--sprite-aspect);
  width: min(260%, 420px);
  margin-left: calc((100% - min(260%, 420px)) / 2);
  max-width: none;
}
.sprite.portrait {
  /* 絵のある上 490px 分（600×490）だけを幅いっぱいに見せる */
  aspect-ratio: 600 / 490;
  object-fit: cover;
  object-position: center top;
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
