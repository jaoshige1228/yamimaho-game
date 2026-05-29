<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BattleField from '../components/battle/BattleField.vue';
import CommandModal from '../components/battle/CommandModal.vue';
import EffectLayer from '../components/battle/EffectLayer.vue';
import LogModal from '../components/battle/LogModal.vue';
import TargetOverlay from '../components/battle/TargetOverlay.vue';
import UnitStatsModal from '../components/battle/UnitStatsModal.vue';
import { useAppAudio } from '../composables/useAppAudio';
import { useBattleStore } from '../stores/battle';

const props = defineProps({
  id: { type: String, default: '' },
});

const router = useRouter();
const route = useRoute();
const battle = useBattleStore();
const {
  settings: audioSettings,
  unlock,
  unlockAndPlay,
  playBgm,
  resumeBgm,
  playAttackSe,
  fadeOutBgm,
  isBgmPaused,
} = useAppAudio();

const busy = ref(false);
const targetMode = ref(null);
const pendingAction = ref(null);
const showCommandModal = ref(false);
const showLogModal = ref(false);
const statsUnitId = ref(null);
const shakeTarget = ref(null);
const activeEnemyLunge = ref(null);
const flashClass = ref('');
const floatingDamages = ref([]);
const actionBanners = ref([]);
const showResult = ref(false);
const victoryRewards = ref([]);
const dungeonCleared = ref(false);

const state = computed(() => battle.state);
const units = computed(() => state.value?.units ?? []);
const currentActor = computed(() => state.value?.current_actor);
const commands = computed(() => state.value?.commands);
const awaiting = computed(() => state.value?.awaiting_input && state.value?.status === 'active');
const canOpenCommands = computed(() => awaiting.value && !busy.value && !targetMode.value);

const isDungeonBattle = computed(
  () => route.query.from === 'dungeon' || state.value?.meta?.source === 'dungeon',
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
  units.value.find((u) => u.id === currentActor.value),
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

const targetMessage = computed(() => {
  if (!targetMode.value) return '';
  if (isAllTarget.value) {
    return '対象を確認して、決定してください';
  }
  if (targetMode.value === 'enemy_single') return '攻撃する敵を選んでください';
  if (targetMode.value === 'ally_single') return '対象の味方を選んでください';
  return '';
});

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
  if (pending.action === 'spell' && pending.spell) {
    return {
      label: pending.spell.label,
      description: pending.spell.description ?? '',
      mp_cost: pending.spell.mp_cost,
    };
  }
  return null;
});

function delay(ms) {
  return new Promise((r) => setTimeout(r, ms));
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

async function playEvents(events) {
  let pendingAttackLabel = null;

  for (const ev of events) {
    if (ev.type === 'turn_start') {
      if (String(ev.actor).startsWith('enemy')) {
        activeEnemyLunge.value = ev.actor;
        await delay(400);
        activeEnemyLunge.value = null;
      }
      await delay(200);
    }
    if (ev.type === 'spell_cast') {
      pendingAttackLabel = spellLabel(ev.spell_id) ?? pendingAttackLabel;
      if (ev.element) {
        showFlash(ev.element);
        await delay(280);
      }
    }
    if (ev.type === 'damage') {
      const attacker = unitName(ev.actor);
      const moveName = pendingAttackLabel ?? '攻撃';
      showActionBanner(`${attacker}の${moveName}！`);
      await delay(520);
      showFlash(ev.element || 'hit');
      shakeTarget.value = ev.target;
      showActionBanner(`${ev.value}ダメージ！`);
      showFloatingDamage(ev.target, ev.value);
      playAttackSe();
      await delay(480);
      shakeTarget.value = null;
      pendingAttackLabel = null;
    }
    if (ev.type === 'heal') {
      await delay(350);
    }
    if (ev.type === 'exp_gained') {
      victoryRewards.value.push(`${ev.name}は${ev.amount}の経験値を得た`);
      await delay(200);
    }
    if (ev.type === 'level_up') {
      victoryRewards.value.push(`${ev.name}が Lv${ev.level} になった！`);
      showActionBanner(`${ev.name}が Lv${ev.level} に上がった！`);
      await delay(900);
    }
    if (ev.type === 'dungeon_cleared') {
      dungeonCleared.value = true;
      await delay(400);
    }
    if (ev.type === 'battle_end') {
      showResult.value = true;
      fadeOutBgm();
    }
  }
}

function closeModals() {
  showCommandModal.value = false;
  showLogModal.value = false;
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
  if (restart) {
    await playBgm({ restart: true });
  } else {
    await resumeBgm();
  }
}

function onUserGesture() {
  if (shouldPlayBgm() && isBgmPaused()) {
    unlockAndPlay();
  } else {
    unlock();
  }
}

async function initBattle() {
  busy.value = true;
  closeModals();
  dungeonCleared.value = false;
  try {
    let startedNew = false;
    if (props.id) {
      if (battle.battleId !== props.id) {
        await battle.load(props.id);
      }
    } else if (!battle.battleId) {
      await battle.startDemo();
      startedNew = true;
    }
    await tryPlayBgm({ restart: startedNew });
    const events = battle.dequeueEvents();
    await playEvents(events);
  } catch (e) {
    battle.error = e.message;
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
  } finally {
    busy.value = false;
  }
}

async function doAction(payload) {
  if (busy.value || !awaiting.value) return;
  busy.value = true;
  closeModals();
  try {
    await battle.submitAction(payload);
    await afterAction();
  } catch (e) {
    battle.error = e.message;
  } finally {
    busy.value = false;
  }
}

function onOpenCommands(unitId) {
  if (!canOpenCommands.value || unitId !== currentActor.value) return;
  showCommandModal.value = true;
}

function onPunch() {
  showCommandModal.value = false;
  targetMode.value = 'enemy_single';
  pendingAction.value = { action: 'punch' };
}

function onDefend() {
  showCommandModal.value = false;
  doAction({ action: 'defend' });
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
  await battle.startDemo();
  router.replace({ name: 'battle', params: { id: battle.battleId } });
  await afterAction();
  await tryPlayBgm({ restart: true });
}

function leaveBattle() {
  const wasDungeon = isDungeonBattle.value;
  const status = state.value?.status;
  const cleared = dungeonCleared.value;

  showResult.value = false;
  victoryRewards.value = [];
  dungeonCleared.value = false;
  battle.battleId = null;
  battle.state = null;
  battle.pendingEvents = [];

  if (cleared || (status === 'defeat' && wasDungeon)) {
    router.push('/hub');
    return;
  }
  if (status === 'victory' && wasDungeon) {
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
  <div class="battle-view" @click="onUserGesture" @touchstart.passive="onUserGesture">
    <EffectLayer
      :flash-class="flashClass"
      :floating-damages="floatingDamages"
      :action-banners="actionBanners"
    />

    <div class="battle-toolbar">
      <button type="button" class="log-btn" @click="showLogModal = true">記録</button>
      <span class="turn">{{ turnLabel }}</span>
    </div>

    <main v-if="state" class="battle-stage">
      <BattleField
        :units="units"
        :current-actor="currentActor"
        :target-mode="targetMode"
        :shake-target="shakeTarget"
        :active-enemy-lunge="activeEnemyLunge"
        :can-open-commands="canOpenCommands"
        @select-target="onSelectTarget"
        @open-commands="onOpenCommands"
        @show-stats="onShowStats"
      />
    </main>

    <TargetOverlay
      :visible="!!targetMode"
      :message="targetMessage"
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
      @defend="onDefend"
      @spell="onSpell"
    />

    <LogModal
      :visible="showLogModal"
      :lines="state?.log ?? []"
      @close="showLogModal = false"
    />

    <UnitStatsModal
      :visible="!!statsUnit"
      :unit="statsUnit"
      @close="closeStatsModal"
    />

    <p v-if="battle.error" class="error">{{ battle.error }}</p>

    <div v-if="showResult" class="result-modal">
      <h2>{{ resultTitle }}</h2>
      <ul v-if="victoryRewards.length" class="reward-list">
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
.log-btn {
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
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0;
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
