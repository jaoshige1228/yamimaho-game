<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BattleField from '../components/battle/BattleField.vue';
import CommandModal from '../components/battle/CommandModal.vue';
import EffectLayer from '../components/battle/EffectLayer.vue';
import TargetOverlay from '../components/battle/TargetOverlay.vue';
import UnitStatsModal from '../components/battle/UnitStatsModal.vue';
import { useAppAudio } from '../composables/useAppAudio';
import { useBattleStore } from '../stores/battle';
import { usePlayerStore } from '../stores/player';

const props = defineProps({
  id: { type: String, default: '' },
});

const router = useRouter();
const route = useRoute();
const battle = useBattleStore();
const player = usePlayerStore();
const {
  settings: audioSettings,
  unlock,
  unlockAndPlay,
  switchBgm,
  playSe,
  fadeOutBgm,
  isBgmPaused,
} = useAppAudio();

const battleBgmTrack = computed(() =>
  route.query.boss === '1' || state.value?.meta?.boss ? 'boss' : 'battle',
);

const busy = ref(false);
const targetMode = ref(null);
const pendingAction = ref(null);
const showCommandModal = ref(false);
const statsUnitId = ref(null);
const shakeTarget = ref(null);
const supportEffectTarget = ref(null);
const visualCurrentActor = ref(null);
const activeEnemyLunge = ref(null);
const flashClass = ref('');
const floatingDamages = ref([]);
const floatingSupports = ref([]);
const actionBanners = ref([]);
const displayHp = ref({});
/** @type {import('vue').Ref<'hidden' | 'entering' | 'ready'>} */
const partyPhase = ref('hidden');
const showResult = ref(false);
const victoryRewards = ref([]);
const goldGained = ref(0);
const dungeonCleared = ref(false);

const state = computed(() => battle.state);
const units = computed(() => state.value?.units ?? []);
const currentActor = computed(() => state.value?.current_actor);
const displayCurrentActor = computed(() => {
  if (busy.value && visualCurrentActor.value) {
    return visualCurrentActor.value;
  }
  return currentActor.value;
});
const commands = computed(() => state.value?.commands);
const buffIcons = computed(() => state.value?.buff_icons ?? {});
const awaiting = computed(() => state.value?.awaiting_input && state.value?.status === 'active');
const canOpenCommands = computed(
  () => awaiting.value && !busy.value && !targetMode.value && partyPhase.value === 'ready',
);

const isDungeonBattle = computed(
  () => route.query.from === 'dungeon' || state.value?.meta?.source === 'dungeon',
);

const canShowFlee = computed(
  () =>
    isDungeonBattle.value &&
    state.value?.meta?.can_flee !== false &&
    route.query.boss !== '1',
);

const canFlee = computed(
  () => canShowFlee.value && awaiting.value && !busy.value && partyPhase.value === 'ready',
);

const floor1BossSprite = computed(
  () => state.value?.meta?.boss === true && Number(state.value?.meta?.floor) === 1,
);

const resultTitle = computed(() => {
  if (dungeonCleared.value) {
    return 'クリア！';
  }
  if (state.value?.status === 'victory') {
    return '勝利！';
  }
  return '敗北…';
});

const resultPrimaryLabel = computed(() => {
  if (dungeonCleared.value) {
    return '待機画面へ';
  }
  if (isDungeonBattle.value && state.value?.status === 'defeat') {
    return '待機画面へ';
  }
  if (isDungeonBattle.value && state.value?.status === 'victory') {
    return 'ダンジョンへ';
  }
  return 'もう一度';
});

function onResultPrimary() {
  if (isDungeonBattle.value) {
    leaveBattle();
    return;
  }
  retry();
}

const statsUnit = computed(() => {
  if (!statsUnitId.value) {
    return null;
  }
  return units.value.find((u) => u.id === statsUnitId.value) ?? null;
});

const activeActorUnit = computed(() =>
  units.value.find((u) => u.id === displayCurrentActor.value),
);

const turnLabel = computed(() => {
  if (!state.value) return '';
  if (state.value.status !== 'active') return state.value.status === 'victory' ? '勝利！' : '敗北…';
  const actor = activeActorUnit.value;
  if (!actor) return '…';
  if (actor.side === 'enemy') return '敵のターン';
  return `${actor.name} のターン`;
});

const isAllTarget = computed(
  () => targetMode.value === 'enemy_all' || targetMode.value === 'ally_all',
);

function onBattleViewClick(event) {
  onUserGesture();
  if (!targetMode.value) return;
  const target = event.target;
  if (target instanceof Element && target.closest('.target-panel')) return;
  if (
    target instanceof Element
    && target.closest('.enemy-slot.selectable, .party-slot.selectable')
  ) {
    return;
  }
  cancelTarget();
}

const pendingSkillInfo = computed(() => {
  const pending = pendingAction.value;
  if (!pending) return null;
  if (pending.action === 'punch') {
    return {
      label: commands.value?.punch?.label ?? 'こぶし',
      description: commands.value?.punch?.description ?? '',
      mp_cost: 0,
    };
  }
  if (pending.action === 'kick') {
    return {
      label: commands.value?.kick?.label ?? 'キック',
      description: commands.value?.kick?.description ?? '',
      mp_cost: 0,
    };
  }
  if (pending.action === 'spell' && pending.spell) {
    return {
      label: pending.spell.label,
      description: pending.spell.description ?? '',
      mp_cost: pending.spell.mp_cost,
    };
  }
  return null;
});

const spellEffect = computed(() => pendingAction.value?.spell?.effect ?? null);

function delay(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

const PARTY_ENTRANCE_DELAY_MS = 500;
const PARTY_ENTRANCE_ANIM_MS = 450;
const SPELL_CAST_ANNOUNCE_DELAY_MS = 700;

async function runPartyEntrance() {
  partyPhase.value = 'hidden';
  await delay(PARTY_ENTRANCE_DELAY_MS);
  partyPhase.value = 'entering';
  await delay(PARTY_ENTRANCE_ANIM_MS);
  partyPhase.value = 'ready';
}

function unitPosition(id) {
  const u = units.value.find((x) => x.id === id);
  if (!u) return { x: 50, y: 50 };
  const allies = units.value.filter((x) => x.side === 'ally');
  const enemies = units.value.filter((x) => x.side === 'enemy');
  const list = u.side === 'ally' ? allies : enemies;
  const idx = list.findIndex((x) => x.id === id);
  const count = list.length || 1;
  const x = ((idx + 0.5) / count) * 100;
  const y = u.side === 'enemy' ? 28 : 72;
  return { x, y };
}

function showFlash(element) {
  flashClass.value = '';
  requestAnimationFrame(() => {
    flashClass.value = element ? `flash-${element}` : 'flash-hit';
    setTimeout(() => {
      flashClass.value = '';
    }, 480);
  });
}

function flashKindForSpellAnnounce(ev) {
  const element = ev.spell_element;
  if (element) {
    return element;
  }

  const effect = String(ev.spell_effect ?? '');
  if (effect.includes('heal')) {
    return 'heal';
  }
  if (effect.startsWith('debuff') || effect.includes('down')) {
    return 'debuff';
  }
  if (effect.startsWith('buff') || effect.includes('up') || effect.includes('evasion')) {
    return 'buff';
  }
  if (effect === 'shield_next') {
    return 'shield';
  }
  if (effect.includes('revive')) {
    return 'revive';
  }

  return 'buff';
}

function showFloatingDamage(targetId, value) {
  const pos = unitPosition(targetId);
  floatingDamages.value.push({
    id: `${targetId}-${Date.now()}`,
    value: `-${value}`,
    x: pos.x,
    y: pos.y,
  });
  setTimeout(() => {
    floatingDamages.value = floatingDamages.value.slice(1);
  }, 950);
}

function showFloatingSupport(targetId, text, kind = 'heal') {
  const pos = unitPosition(targetId);
  floatingSupports.value.push({
    id: `${targetId}-${Date.now()}`,
    value: text,
    kind,
    x: pos.x,
    y: pos.y,
  });
  setTimeout(() => {
    floatingSupports.value = floatingSupports.value.slice(1);
  }, 950);
}

async function showSupportEffect(targetId, kind) {
  supportEffectTarget.value = { id: targetId, kind };
  await delay(420);
  supportEffectTarget.value = null;
}

function unitName(unitId) {
  return units.value.find((u) => u.id === unitId)?.name ?? unitId;
}

function spellLabel(spellId) {
  if (!spellId) return null;
  const found = commands.value?.spells?.find((s) => s.id === spellId);
  return found?.label ?? null;
}

function showActionBanner(text) {
  const id = `${Date.now()}-${Math.random()}`;
  actionBanners.value = [{ id, text }];
  setTimeout(() => {
    actionBanners.value = actionBanners.value.filter((b) => b.id !== id);
  }, 1100);
}

function syncDisplayHp(force = false) {
  for (const unit of units.value) {
    if (force || displayHp.value[unit.id] === undefined) {
      displayHp.value[unit.id] = unit.hp;
    }
  }
}

function snapshotDisplayHp() {
  const snap = {};
  for (const unit of units.value) {
    snap[unit.id] = unit.id in displayHp.value
      ? displayHp.value[unit.id]
      : unit.hp;
  }
  return snap;
}

function applyDisplayHpSnapshot(snap) {
  displayHp.value = { ...snap };
}

/** state はイベント適用後だが、再生前の表示 HP に戻す */
function rewindDisplayHpForEvents(events) {
  const snap = snapshotDisplayHp();
  for (const ev of events) {
    if (ev.type === 'damage') {
      const unit = units.value.find((u) => u.id === ev.target);
      const max = unit?.max_hp ?? Infinity;
      snap[ev.target] = Math.min(max, (snap[ev.target] ?? 0) + Number(ev.value || 0));
    }
    if (ev.type === 'heal') {
      snap[ev.target] = Math.max(0, (snap[ev.target] ?? 0) - Number(ev.value || 0));
    }
    if (ev.type === 'revive') {
      snap[ev.target] = 0;
    }
  }
  applyDisplayHpSnapshot(snap);
}

function applyDisplayHpDelta(targetId, delta) {
  const unit = units.value.find((u) => u.id === targetId);
  if (!unit) return;
  const current = targetId in displayHp.value
    ? displayHp.value[targetId]
    : unit.hp;
  displayHp.value[targetId] = Math.max(0, Math.min(unit.max_hp, current + delta));
}

function setDisplayHp(targetId, hp) {
  const unit = units.value.find((u) => u.id === targetId);
  if (!unit) return;
  displayHp.value[targetId] = Math.max(0, Math.min(unit.max_hp, hp));
}

async function playEvents(events) {
  for (let i = 0; i < events.length; i += 1) {
    const ev = events[i];
    if (ev.type === 'turn_start') {
      visualCurrentActor.value = ev.actor;
      playSe('attack_before');
      if (String(ev.actor).startsWith('enemy')) {
        activeEnemyLunge.value = ev.actor;
        await delay(280);
        activeEnemyLunge.value = null;
      }
      await delay(120);
    }
    if (ev.type === 'announce') {
      showActionBanner(ev.text);
      const isSpell = ev.text?.includes('唱え');
      if (isSpell) {
        showFlash(flashKindForSpellAnnounce(ev));
        playSe('magic');
        await delay(SPELL_CAST_ANNOUNCE_DELAY_MS);
      } else {
        await delay(360);
      }
    }
    if (ev.type === 'miss') {
      showActionBanner('ミス！');
      playSe('miss');
      await delay(280);
      await delay(120);
    }
    if (ev.type === 'damage') {
      shakeTarget.value = ev.target;
      showFloatingDamage(ev.target, ev.value);
      playSe('damage');
      await delay(300);
      applyDisplayHpDelta(ev.target, -ev.value);
      shakeTarget.value = null;
      await delay(120);
    }
    if (ev.type === 'shield_break') {
      showActionBanner('シールドが砕けた！');
      await showSupportEffect(ev.target, 'shield');
      await delay(120);
    }
    if (ev.type === 'heal') {
      applyDisplayHpDelta(ev.target, ev.value);
      showFloatingSupport(ev.target, `+${ev.value}`, 'heal');
      playSe('success');
      await showSupportEffect(ev.target, 'heal');
      await delay(120);
    }
    if (ev.type === 'buff_applied') {
      const isDebuff = String(ev.buff ?? '').includes('down');
      showFloatingSupport(ev.target, isDebuff ? '▼' : '▲', isDebuff ? 'debuff' : 'buff');
      playSe('success');
      await showSupportEffect(ev.target, isDebuff ? 'debuff' : 'buff');
      await delay(120);
    }
    if (ev.type === 'shield_applied') {
      showFloatingSupport(ev.target, '盾', 'shield');
      playSe('success');
      await showSupportEffect(ev.target, 'shield');
      await delay(120);
    }
    if (ev.type === 'revive') {
      setDisplayHp(ev.target, ev.hp);
      showFloatingSupport(ev.target, '蘇生', 'revive');
      playSe('success');
      await showSupportEffect(ev.target, 'revive');
      showActionBanner(`${unitName(ev.target)}が蘇生した！`);
      await delay(280);
    }
    if (ev.type === 'revive_failed') {
      showActionBanner('効果がなかった…');
      await delay(280);
    }
    if (ev.type === 'gold_gained') {
      goldGained.value += Number(ev.amount) || 0;
    }
    if (ev.type === 'gold_reward_applied') {
      if (ev.gold != null) player.setGold(ev.gold);
      if (ev.amount != null && !goldGained.value) {
        goldGained.value = Number(ev.amount) || 0;
      }
    }
    if (ev.type === 'exp_gained') {
      victoryRewards.value.push(`${ev.name}は${ev.amount}の経験値を得た`);
    }
    if (ev.type === 'level_up') {
      victoryRewards.value.push(`${ev.name}が Lv${ev.level} になった！`);
    }
    if (ev.type === 'dungeon_floor_cleared') {
      dungeonCleared.value = true;
      if (ev.text) {
        victoryRewards.value.push(ev.text);
      }
      await delay(400);
    }
    if (ev.type === 'battle_end') {
      showResult.value = true;
      fadeOutBgm();
    }
  }
  visualCurrentActor.value = null;
}

function closeModals() {
  showCommandModal.value = false;
  statsUnitId.value = null;
}

function onShowStats(unit) {
  statsUnitId.value = unit.id;
}

function closeStatsModal() {
  statsUnitId.value = null;
}

function shouldPlayBgm() {
  return state.value?.status === 'active' && audioSettings.value.bgmEnabled;
}

async function tryPlayBgm({ restart = false } = {}) {
  if (!shouldPlayBgm()) return;
  unlock();
  await switchBgm(battleBgmTrack.value, { restart });
}

function onUserGesture() {
  if (shouldPlayBgm() && isBgmPaused()) {
    unlockAndPlay(battleBgmTrack.value);
  } else {
    unlock();
  }
}

async function initBattle() {
  busy.value = true;
  closeModals();
  dungeonCleared.value = false;
  goldGained.value = 0;
  try {
    let startedNew = false;
    let playPartyEntrance = false;
    if (props.id) {
      if (battle.battleId !== props.id) {
        await battle.load(props.id);
        partyPhase.value = 'ready';
      } else {
        playPartyEntrance = true;
        partyPhase.value = 'hidden';
      }
    } else if (!battle.battleId) {
      await battle.startDemo();
      startedNew = true;
      playPartyEntrance = true;
      partyPhase.value = 'hidden';
    } else {
      playPartyEntrance = true;
      partyPhase.value = 'hidden';
    }
    syncDisplayHp(true);
    await tryPlayBgm({ restart: startedNew });
    if (playPartyEntrance) {
      await runPartyEntrance();
    }
    const events = battle.dequeueEvents();
    if (events.length > 0) {
      rewindDisplayHpForEvents(events);
    }
    await playEvents(events);
    syncDisplayHp(true);
  } catch (e) {
    battle.error = e.message;
    partyPhase.value = 'ready';
  } finally {
    busy.value = false;
  }
}

async function afterAction() {
  busy.value = true;
  targetMode.value = null;
  pendingAction.value = null;
  closeModals();
  try {
    const events = battle.dequeueEvents();
    await playEvents(events);
    syncDisplayHp(true);
    if (state.value?.status === 'fled') {
      leaveBattleFled();
    }
  } finally {
    busy.value = false;
  }
}

function leaveBattleFled() {
  battle.battleId = null;
  battle.state = null;
  battle.pendingEvents = [];
  router.push({ name: 'dungeon' });
}

async function onFlee() {
  if (!canFlee.value) return;
  await doAction({ action: 'flee' });
}

async function doAction(payload) {
  if (busy.value || !awaiting.value) return;
  busy.value = true;
  closeModals();
  const hpSnapshot = snapshotDisplayHp();
  try {
    await battle.submitAction(payload);
    applyDisplayHpSnapshot(hpSnapshot);
    await afterAction();
  } catch (e) {
    battle.error = e.message;
  } finally {
    busy.value = false;
  }
}

function onOpenCommands(unitId) {
  if (!canOpenCommands.value || unitId !== displayCurrentActor.value) return;
  showCommandModal.value = true;
}

function onPunch() {
  showCommandModal.value = false;
  targetMode.value = 'enemy_single';
  pendingAction.value = { action: 'punch' };
}

function onKick() {
  showCommandModal.value = false;
  targetMode.value = 'enemy_single';
  pendingAction.value = { action: 'kick' };
}

function onSpell(spell) {
  showCommandModal.value = false;
  pendingAction.value = { action: 'spell', spell_id: spell.id, spell };
  targetMode.value = spell.target_type;
}

function cancelTarget() {
  targetMode.value = null;
  pendingAction.value = null;
}

async function onSelectTarget(targetId) {
  if (!pendingAction.value) return;
  const payload = { action: pendingAction.value.action };
  if (pendingAction.value.spell_id) {
    payload.spell_id = pendingAction.value.spell_id;
  }
  if (targetId) {
    payload.target_id = targetId;
  }
  await doAction(payload);
}

async function confirmAll() {
  if (!pendingAction.value) return;
  const payload = { action: pendingAction.value.action };
  if (pendingAction.value.spell_id) {
    payload.spell_id = pendingAction.value.spell_id;
  }
  await doAction(payload);
}

async function retry() {
  showResult.value = false;
  victoryRewards.value = [];
  dungeonCleared.value = false;
  busy.value = true;
  try {
    await battle.startDemo();
    router.replace({ name: 'battle', params: { id: battle.battleId } });
    syncDisplayHp(true);
    await tryPlayBgm({ restart: true });
    await runPartyEntrance();
    const events = battle.dequeueEvents();
    if (events.length > 0) {
      rewindDisplayHpForEvents(events);
    }
    await playEvents(events);
    syncDisplayHp(true);
  } catch (e) {
    battle.error = e.message;
    partyPhase.value = 'ready';
  } finally {
    busy.value = false;
  }
}

function leaveBattle() {
  const wasDungeon = isDungeonBattle.value;
  const status = state.value?.status;
  const cleared = dungeonCleared.value;

  showResult.value = false;
  victoryRewards.value = [];
  dungeonCleared.value = false;
  goldGained.value = 0;
  battle.battleId = null;
  battle.state = null;
  battle.pendingEvents = [];
  player.fetchNavigation();

  if (cleared || (status === 'defeat' && wasDungeon)) {
    router.push('/hub');
    return;
  }
  if ((status === 'victory' || status === 'fled') && wasDungeon) {
    router.push('/dungeon');
    return;
  }
  router.push('/');
}

watch(currentActor, () => {
  if (!awaiting.value) {
    showCommandModal.value = false;
  }
});

onMounted(() => initBattle());

watch(
  () => props.id,
  () => initBattle(),
);
</script>

<template>
  <div class="battle-view" @click="onBattleViewClick" @touchstart.passive="onUserGesture">
    <EffectLayer
      :flash-class="flashClass"
      :floating-damages="floatingDamages"
      :floating-supports="floatingSupports"
      :action-banners="actionBanners"
    />

    <div class="battle-toolbar">
      <button
        v-if="canShowFlee"
        type="button"
        class="flee-btn"
        :disabled="!canFlee"
        @click="onFlee"
      >
        逃げる
      </button>
      <span class="turn">{{ turnLabel }}</span>
    </div>

    <main v-if="state" class="battle-stage">
      <BattleField
        :units="units"
        :display-hp="displayHp"
        :buff-icons="buffIcons"
        :party-phase="partyPhase"
        :current-actor="displayCurrentActor"
        :target-mode="targetMode"
        :spell-effect="spellEffect"
        :shake-target="shakeTarget"
        :support-effect-target="supportEffectTarget"
        :active-enemy-lunge="activeEnemyLunge"
        :floor1-boss-sprite="floor1BossSprite"
        :can-open-commands="canOpenCommands"
        @select-target="onSelectTarget"
        @open-commands="onOpenCommands"
        @show-stats="onShowStats"
      />
    </main>

    <TargetOverlay
      :visible="!!targetMode"
      :skill="pendingSkillInfo"
      :show-execute="isAllTarget"
      @cancel="cancelTarget"
      @confirm-all="confirmAll"
    />

    <CommandModal
      :visible="showCommandModal"
      :commands="commands"
      :actor-name="activeActorUnit?.name ?? ''"
      @close="showCommandModal = false"
      @punch="onPunch"
      @kick="onKick"
      @spell="onSpell"
    />

    <UnitStatsModal
      :visible="!!statsUnit"
      :unit="statsUnit"
      @close="closeStatsModal"
    />

    <p v-if="battle.error" class="error">{{ battle.error }}</p>

    <div v-if="showResult" class="result-modal">
      <h2>{{ resultTitle }}</h2>
      <ul v-if="victoryRewards.length || goldGained > 0" class="reward-list">
        <li v-if="goldGained > 0">ゴールドを {{ goldGained }} 獲得した</li>
        <li v-for="(line, i) in victoryRewards" :key="i">{{ line }}</li>
      </ul>
      <button type="button" class="btn-primary" @click="onResultPrimary">
        {{ resultPrimaryLabel }}
      </button>
      <button v-if="!isDungeonBattle" type="button" class="btn-ghost" @click="router.push('/')">
        タイトルへ
      </button>
    </div>
  </div>
</template>

<style scoped>
.battle-view {
  --field-height: 800px;
  --field-bottom-height: 496px;
  position: relative;
  width: min(430px, 100vw);
  height: 100%;
  max-height: 100%;
  display: flex;
  flex-direction: column;
  color: var(--text);
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  overflow: hidden;
  background: #0f0a1a;
}
.battle-toolbar {
  position: relative;
  z-index: 10;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.75rem;
  background: rgba(0, 0, 0, 0.35);
}
.flee-btn {
  min-height: 44px;
  padding: 0.35rem 0.75rem;
  border: 1px solid rgba(157, 124, 255, 0.5);
  border-radius: 8px;
  background: rgba(40, 28, 70, 0.85);
  color: var(--text);
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}
.flee-btn:disabled {
  opacity: 0.45;
  cursor: default;
}
.turn {
  flex: 1;
  font-weight: 700;
  font-size: 0.95rem;
  text-align: center;
}
.battle-stage {
  position: relative;
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: stretch;
  justify-content: center;
  padding: 0;
}
.error {
  position: absolute;
  bottom: calc(var(--field-bottom-height) + 0.75rem);
  left: 50%;
  transform: translateX(-50%);
  z-index: 25;
  color: #ff8a8a;
  text-align: center;
  padding: 0.5rem 0.75rem;
  background: rgba(0, 0, 0, 0.7);
  border-radius: 8px;
  font-size: 0.8rem;
  max-width: 90%;
}
.result-modal {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  z-index: 50;
}
.result-modal h2 {
  font-size: 2rem;
  margin: 0;
}
.reward-list {
  list-style: none;
  margin: 0;
  padding: 0 1rem;
  max-width: 320px;
  text-align: center;
  font-size: 0.85rem;
  line-height: 1.6;
  color: #d4c8f0;
}
.reward-list li {
  margin: 0.15rem 0;
}
.btn-primary {
  min-height: 48px;
  padding: 0.75rem 2rem;
  border: none;
  border-radius: 999px;
  background: linear-gradient(135deg, #7c5cff, #c45cff);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}
.btn-ghost {
  min-height: 44px;
  border: none;
  background: transparent;
  color: #aaa;
  cursor: pointer;
}
</style>
