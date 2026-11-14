<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api';
import { useAppAudio } from '../../composables/useAppAudio.js';
import { characterSpriteUrls } from '../../utils/publicAssetUrl.js';

/**
 * ダンジョン内の回復画面。
 * ① 回復する相手をカードで選ぶ（HP・MP のバーと、戦闘不能が一目で分かる）
 * ② アイテム / 魔法から使うものを選ぶ（使えないものは灰色になって押せない。理由や見込みの文は出さない）
 * ③ 使うと、カードの数字とバーが動き、カードの上に増えた数字が浮かぶ（結果の文は出さない）
 */
const props = defineProps({
  visible: { type: Boolean, default: false },
  party: { type: Array, default: () => [] },
  items: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'updated']);

const { playSe, unlock } = useAppAudio();
const tab = ref('item');
const targetSlotId = ref('');
const busy = ref(false);
const error = ref('');
const spells = ref([]);
/** カードの上に浮かぶ数字 { [slot]: [{ id, text, kind }] } */
const floats = ref({});
let floatSeq = 0;

const slotOf = (unit) => unit.slot_id ?? unit.id;
const unitBySlot = (slot) => props.party.find((u) => slotOf(u) === slot) ?? null;
const selectedTarget = computed(() => unitBySlot(targetSlotId.value));

const isDead = (u) => !u.alive || u.hp <= 0;
const hpRate = (u) => (u.max_hp > 0 ? Math.max(0, Math.min(100, (u.hp / u.max_hp) * 100)) : 0);
const mpRate = (u) => (u.max_mp > 0 ? Math.max(0, Math.min(100, (u.mp / u.max_mp) * 100)) : 0);
const faceSrc = (u) => characterSpriteUrls(u.sprite).webp;

/** 回復アイテムだけ（持っている数が 0 のものは出さない） */
const healItems = computed(() =>
  props.items.filter((i) => ['heal_hp', 'heal_mp', 'revive'].includes(i.effect) && Number(i.quantity) > 0),
);

/** 味方全体にかける魔法（精霊の虹など）。対象は選ばない */
const isAllTarget = (spell) => spell.target_type === 'ally_all';
const isReviveSpell = (spell) => spell.effect === 'revive_chance' || spell.effect === 'revive_full';

/** 回復する相手として、いま一番必要としていそうな人（HP の割合が一番低い生存者 → 戦闘不能 → MP が減っている人 → 先頭） */
function suggestTarget() {
  const alive = props.party.filter((u) => !isDead(u));
  const hurt = alive.filter((u) => u.hp < u.max_hp).sort((a, b) => hpRate(a) - hpRate(b));
  if (hurt.length > 0) return slotOf(hurt[0]);
  const dead = props.party.find(isDead);
  if (dead) return slotOf(dead);
  const lowMp = alive.filter((u) => u.mp < u.max_mp).sort((a, b) => mpRate(a) - mpRate(b));
  if (lowMp.length > 0) return slotOf(lowMp[0]);
  return props.party[0] ? slotOf(props.party[0]) : '';
}

/** 使えるか（使えないものは灰色にして押せなくする。理由の文は出さない） */
function canUseItem(item, target) {
  if (!target) return false;
  if (item.effect === 'revive') return isDead(target);
  if (isDead(target)) return false;
  if (item.effect === 'heal_hp') return target.hp < target.max_hp;
  return target.mp < target.max_mp;
}

function canUseSpell(spell, target) {
  const caster = unitBySlot(spell.caster_slot_id);
  if (!caster || isDead(caster) || caster.mp < spell.mp_cost) return false;
  // 味方全体の魔法は、傷ついた生存者が 1 人でもいれば使える
  if (isAllTarget(spell)) return props.party.some((u) => !isDead(u) && u.hp < u.max_hp);
  if (!target) return false;
  if (isReviveSpell(spell)) return isDead(target);
  return !isDead(target) && target.hp < target.max_hp;
}

const itemRows = computed(() =>
  healItems.value.map((item) => ({
    key: item.code,
    kind: item.effect === 'revive' ? 'revive' : item.effect === 'heal_mp' ? 'mp' : 'hp',
    tag: item.effect === 'revive' ? '蘇生' : item.effect === 'heal_mp' ? 'MP' : 'HP',
    title: item.name,
    sub: item.description,
    side: [`×${item.quantity}`],
    usable: canUseItem(item, selectedTarget.value),
    run: () => useItem(item),
  })),
);

const spellRows = computed(() =>
  spells.value.map((spell) => ({
    key: `${spell.caster_slot_id}-${spell.id}`,
    kind: isReviveSpell(spell) ? 'revive' : 'hp',
    tag: isReviveSpell(spell) ? '蘇生' : 'HP',
    title: spell.label,
    sub: spell.description,
    side: [spell.caster_name, `MP ${spell.mp_cost}`],
    usable: canUseSpell(spell, selectedTarget.value),
    run: () => useSpell(spell),
  })),
);

const rows = computed(() => (tab.value === 'item' ? itemRows.value : spellRows.value));
const emptyText = computed(() =>
  tab.value === 'item' ? '回復アイテムを持っていません' : '使える回復魔法がありません',
);

async function loadSpells() {
  const data = await api('/dungeon/heal/options', { method: 'GET' });
  spells.value = data.spells ?? [];
}

watch(
  () => props.visible,
  (open) => {
    if (!open) return;
    tab.value = 'item';
    targetSlotId.value = suggestTarget();
    error.value = '';
    floats.value = {};
    loadSpells().catch((e) => {
      error.value = e.message;
    });
  },
);

function pickTarget(unit) {
  if (targetSlotId.value !== slotOf(unit)) {
    playSe('cursor');
    error.value = '';
  }
  targetSlotId.value = slotOf(unit);
}

function pickTab(next) {
  if (tab.value !== next) {
    playSe('cursor');
    error.value = '';
  }
  tab.value = next;
}

function addFloat(slot, text, kind) {
  floatSeq += 1;
  const entry = { id: floatSeq, text, kind };
  floats.value = { ...floats.value, [slot]: [...(floats.value[slot] ?? []), entry] };
  setTimeout(() => {
    floats.value = { ...floats.value, [slot]: (floats.value[slot] ?? []).filter((f) => f.id !== entry.id) };
  }, 1400);
}

/** 使う前と後のパーティを比べて、カードの上に浮かべる数字（増えた HP・MP、復活）を出す */
function showFloats(before, after) {
  for (const next of after) {
    const prev = before.find((u) => slotOf(u) === slotOf(next));
    if (!prev) continue;
    const slot = slotOf(next);
    if (isDead(prev) && !isDead(next)) {
      addFloat(slot, '復活', 'revive');
      continue;
    }
    const hp = next.hp - prev.hp;
    const mp = next.mp - prev.mp;
    if (hp > 0) addFloat(slot, `+${hp}`, 'hp');
    if (mp > 0) addFloat(slot, `+${mp}`, 'mp');
  }
}

async function send(body, seName) {
  busy.value = true;
  error.value = '';
  const before = props.party.map((u) => ({ ...u }));
  try {
    unlock();
    playSe('cursor');
    const data = await api('/dungeon/heal', { method: 'POST', body: JSON.stringify(body) });
    emit('updated', data);
    showFloats(before, data.party ?? []);
    playSe(seName);
    // 魔法を使うとMPが減って使えるものが変わるので、一覧を読み直す
    await loadSpells().catch(() => {});
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function useItem(item) {
  if (!selectedTarget.value) return Promise.resolve();
  return send(
    { mode: 'item', item_code: item.code, target_slot_id: slotOf(selectedTarget.value) },
    'heal',
  );
}

function useSpell(spell) {
  const all = isAllTarget(spell);
  if (!all && !selectedTarget.value) return Promise.resolve();
  return send(
    {
      mode: 'spell',
      caster_slot_id: spell.caster_slot_id,
      spell_id: spell.id,
      // 味方全体の魔法は対象を送らない
      ...(all ? {} : { target_slot_id: slotOf(selectedTarget.value) }),
    },
    'magic',
  );
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="heal-root" @click.self="emit('close')">
      <div class="heal-sheet" role="dialog" aria-modal="true" aria-label="回復">
        <header class="heal-head">
          <h3>回復</h3>
          <button type="button" class="heal-close" aria-label="閉じる" @click="emit('close')">×</button>
        </header>

        <ul class="heal-party">
          <li v-for="unit in party" :key="slotOf(unit)">
            <button
              type="button"
              class="heal-unit"
              :class="{ selected: targetSlotId === slotOf(unit), dead: isDead(unit) }"
              :aria-pressed="targetSlotId === slotOf(unit)"
              @click="pickTarget(unit)"
            >
              <span class="heal-unit__top">
                <img class="heal-unit__face" :src="faceSrc(unit)" alt="" draggable="false" />
                <span class="heal-unit__name">{{ unit.name }}</span>
              </span>
              <span v-if="isDead(unit)" class="heal-unit__down">戦闘不能</span>
              <span class="heal-bar heal-bar--hp"><i :style="{ width: hpRate(unit) + '%' }" /></span>
              <span class="heal-num"><b>HP</b> {{ unit.hp }}<small>/{{ unit.max_hp }}</small></span>
              <span class="heal-bar heal-bar--mp"><i :style="{ width: mpRate(unit) + '%' }" /></span>
              <span class="heal-num"><b>MP</b> {{ unit.mp }}<small>/{{ unit.max_mp }}</small></span>

              <span
                v-for="f in floats[slotOf(unit)] ?? []"
                :key="f.id"
                class="heal-float"
                :class="`heal-float--${f.kind}`"
              >{{ f.text }}</span>
            </button>
          </li>
        </ul>

        <div class="heal-tabs" role="tablist">
          <button type="button" role="tab" :aria-selected="tab === 'item'" :class="{ active: tab === 'item' }" @click="pickTab('item')">
            アイテム
          </button>
          <button type="button" role="tab" :aria-selected="tab === 'spell'" :class="{ active: tab === 'spell' }" @click="pickTab('spell')">
            魔法
          </button>
        </div>

        <div class="heal-body">
          <p v-if="error" class="heal-error">{{ error }}</p>

          <ul v-if="rows.length > 0" class="heal-actions">
            <li v-for="row in rows" :key="row.key">
              <button type="button" class="heal-action" :class="{ off: !row.usable }" :disabled="busy || !row.usable" @click="row.run()">
                <span class="heal-tag" :class="`heal-tag--${row.kind}`">{{ row.tag }}</span>
                <span class="heal-action__main">
                  <span class="heal-action__title">{{ row.title }}</span>
                  <span class="heal-action__sub">{{ row.sub }}</span>
                </span>
                <span class="heal-action__side">
                  <span v-for="(s, i) in row.side" :key="i">{{ s }}</span>
                </span>
              </button>
            </li>
          </ul>
          <p v-else class="heal-empty">{{ emptyText }}</p>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.heal-root {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgba(4, 2, 12, 0.62);
}
.heal-sheet {
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  width: min(430px, 100%);
  /* 高さを固定する。タブを切り替えて一覧の数が変わっても、画面が上下にずれないように（一覧が多いときは一覧の中でスクロール） */
  height: min(80dvh, 560px);
  padding: 0.75rem 0.75rem calc(0.75rem + env(safe-area-inset-bottom));
  border: 1px solid rgba(200, 176, 255, 0.35);
  border-bottom: none;
  border-radius: 18px 18px 0 0;
  /* 後ろの画面が透けて読みにくくならないよう、完全に塗りつぶす */
  background: #150c28;
  color: #f8f4ff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  box-shadow: 0 -10px 36px rgba(0, 0, 0, 0.6);
  animation: healSheetUp 0.22s ease-out;
}
@keyframes healSheetUp {
  from {
    transform: translateY(24px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

/* ───── 見出し ───── */
.heal-head {
  position: relative;
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  margin-bottom: 0.6rem;
  padding-right: 2.2rem;
}
.heal-head h3 {
  margin: 0;
  font-size: 1.05rem;
  letter-spacing: 0.1em;
}
.heal-close {
  position: absolute;
  top: -0.2rem;
  right: -0.2rem;
  width: 40px;
  height: 40px;
  border: none;
  background: transparent;
  color: #fff;
  font-size: 1.6rem;
  line-height: 1;
  cursor: pointer;
}

/* ───── パーティのカード（回復する相手） ───── */
.heal-party {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.35rem;
  margin: 0 0 0.6rem;
  padding: 0;
  list-style: none;
}
.heal-unit {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  width: 100%;
  min-height: 112px;
  box-sizing: border-box;
  padding: 0.4rem 0.35rem 0.4rem;
  border: 2px solid rgba(157, 124, 255, 0.25);
  border-radius: 10px;
  background: #211238;
  color: #f8f4ff;
  text-align: left;
  cursor: pointer;
  touch-action: manipulation;
  transition: border-color 0.15s, background 0.15s;
}
.heal-unit.selected {
  border-color: #ffd98a;
  background: #35205c;
  box-shadow: 0 0 0 1px rgba(255, 217, 138, 0.4), 0 0 14px rgba(255, 217, 138, 0.25);
}
.heal-unit.dead:not(.selected) {
  border-color: rgba(255, 110, 110, 0.4);
}
.heal-unit.dead .heal-unit__face {
  filter: grayscale(1) brightness(0.7);
}
.heal-unit__top {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
}
.heal-unit__face {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border: 1.5px solid rgba(255, 255, 255, 0.7);
  border-radius: 50%;
  background: #000;
  object-fit: cover;
  object-position: center 6%;
}
.heal-unit__name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 800;
}
.heal-unit__down {
  align-self: flex-start;
  padding: 0 0.3rem;
  border-radius: 4px;
  background: #7a1f2e;
  color: #ffd4d4;
  font-size: 0.56rem;
  font-weight: 800;
}
.heal-bar {
  display: block;
  height: 7px;
  margin-top: 0.1rem;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
}
.heal-bar i {
  display: block;
  height: 100%;
  border-radius: 999px;
  transition: width 0.5s ease;
}
.heal-bar--hp i {
  background: #ff5d7a;
}
.heal-bar--mp i {
  background: #4fb4ff;
}
.heal-num {
  font-size: 0.66rem;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
}
.heal-num b {
  margin-right: 0.15rem;
  font-size: 0.55rem;
  color: #b8a8d8;
}
.heal-num small {
  font-size: 0.58rem;
  font-weight: 600;
  color: #b8a8d8;
}

/* 使ったあと、カードの上に数字が浮かぶ */
.heal-float {
  position: absolute;
  left: 50%;
  top: 38%;
  z-index: 2;
  transform: translateX(-50%);
  padding: 0 0.4rem;
  border-radius: 999px;
  /* 下の数字に重なっても読めるように、濃い地を敷く */
  background: rgba(10, 5, 24, 0.88);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.5;
  white-space: nowrap;
  pointer-events: none;
  animation: healFloat 1.4s ease-out forwards;
}
.heal-float--hp {
  color: #8dffb4;
  border: 1px solid rgba(141, 255, 180, 0.6);
}
.heal-float--mp {
  color: #8ccaff;
  top: 62%;
  border: 1px solid rgba(140, 202, 255, 0.6);
}
.heal-float--revive {
  color: #ffe58a;
  border: 1px solid rgba(255, 229, 138, 0.6);
}
@keyframes healFloat {
  0% {
    opacity: 0;
    transform: translate(-50%, 8px) scale(0.8);
  }
  15% {
    opacity: 1;
    transform: translate(-50%, 0) scale(1.15);
  }
  70% {
    opacity: 1;
    transform: translate(-50%, -14px) scale(1);
  }
  100% {
    opacity: 0;
    transform: translate(-50%, -26px) scale(1);
  }
}

/* ───── タブ ───── */
.heal-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
  margin-bottom: 0.5rem;
}
.heal-tabs button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 40px;
  border: 1px solid rgba(157, 124, 255, 0.3);
  border-radius: 10px;
  background: #1d1033;
  color: #cbbcf0;
  font-size: 0.82rem;
  font-weight: 800;
  cursor: pointer;
  touch-action: manipulation;
}
.heal-tabs button.active {
  border-color: #c8b0ff;
  background: #4a2c85;
  color: #fff;
}
/* ───── 一覧 ───── */
.heal-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.heal-error {
  margin: 0 0 0.5rem;
  color: #ff9d9d;
  font-size: 0.78rem;
}
.heal-actions {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.heal-action {
  display: flex;
  align-items: stretch;
  gap: 0.55rem;
  width: 100%;
  box-sizing: border-box;
  padding: 0.5rem 0.6rem;
  border: 1px solid rgba(200, 176, 255, 0.4);
  border-radius: 12px;
  background: #261640;
  color: #f8f4ff;
  text-align: left;
  cursor: pointer;
  touch-action: manipulation;
}
.heal-action:not(:disabled):active {
  background: #35205c;
}
.heal-action.off {
  border-color: rgba(157, 124, 255, 0.15);
  background: #1a0f2e;
  cursor: default;
}
.heal-action.off .heal-action__title,
.heal-action.off .heal-action__sub,
.heal-action.off .heal-tag,
.heal-action.off .heal-action__side {
  opacity: 0.55;
}
.heal-tag {
  flex-shrink: 0;
  align-self: center;
  min-width: 2.6em;
  padding: 0.25rem 0.1rem;
  border-radius: 6px;
  font-size: 0.68rem;
  font-weight: 900;
  text-align: center;
}
.heal-tag--hp {
  background: #7a2338;
  color: #ffd2dc;
}
.heal-tag--mp {
  background: #1f4a7a;
  color: #cfe8ff;
}
.heal-tag--revive {
  background: #7a6420;
  color: #fff0b8;
}
.heal-action__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}
.heal-action__title {
  font-size: 0.86rem;
  font-weight: 800;
}
.heal-action__sub {
  font-size: 0.64rem;
  line-height: 1.35;
  color: #a898c8;
}
.heal-action__side {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: center;
  gap: 0.1rem;
  font-size: 0.66rem;
  font-weight: 700;
  color: #d4c4ff;
  text-align: right;
}
.heal-empty {
  margin: 1.2rem 0;
  text-align: center;
  font-size: 0.8rem;
  color: #8a7aa8;
}

@media (prefers-reduced-motion: reduce) {
  .heal-sheet,
  .heal-float {
    animation-duration: 0.01s;
  }
  .heal-bar i {
    transition: none;
  }
}
</style>
