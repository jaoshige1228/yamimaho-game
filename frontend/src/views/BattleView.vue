<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BattleField from '../components/battle/BattleField.vue';
import CommandModal from '../components/battle/CommandModal.vue';
import EndingCredits from '../components/battle/EndingCredits.vue';
import EffectLayer from '../components/battle/EffectLayer.vue';
import TargetOverlay from '../components/battle/TargetOverlay.vue';
import UnitStatsModal from '../components/battle/UnitStatsModal.vue';
import { useAppAudio } from '../composables/useAppAudio';
import { useBattleStore } from '../stores/battle';
import { usePlayerStore } from '../stores/player';
import { battleBgmTrack as battleBgmTrackFor } from '../utils/dungeonTheme.js';

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
  battleBgmTrackFor({
    floor: state.value?.meta?.floor,
    boss: route.query.boss === '1' || !!state.value?.meta?.boss,
    midBoss: route.query.mid_boss === '1' || !!state.value?.meta?.mid_boss,
    bgm: state.value?.meta?.bgm,
  }),
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
/** 使い魔の支援のバナー（顔つき）。同時に1つだけ */
const familiarBanner = ref(null);
const displayHp = ref({});
/**
 * バッジ（防御↑↓・攻撃↑↓・回避・加護・無敵）の表示用の状態。
 * state は敵の行動などのイベントを再生する前に、先に最新になる。それをそのままバッジに出すと、敵の手番が始まった瞬間に
 * 全員のバフ・デバフが一斉に付いて見える（戦闘開始直後から敵にバッジが付く）。そのため、イベントの再生に合わせて
 * （魔法や特技を使ったと表示した時点で）バッジを付ける。持続が切れたときも、サーバーが buff_expired を送ってくるので、その場で消す。
 * 再生が終わったら、念のため state に合わせる。
 * @type {import('vue').Ref<Record<string, {buffs: Record<string, number>, stages: {def: number, atk: number}, damage_shield: boolean, life_ward: boolean}>>}
 */
const displayBadges = ref({});
/** バッジの表示状態を決めたか（決める前は、バッジなしで描く。state の最新のバッジを一瞬でも出さないため） */
const badgesReady = ref(false);
/** 逃げて画面から消えた敵の ID（逃走イベントの再生に合わせて増える） */
const fledIds = ref([]);
/** @type {import('vue').Ref<'hidden' | 'entering' | 'ready'>} */
const partyPhase = ref('hidden');
const showResult = ref(false);
/** エンドロールを流している間 */
const showCredits = ref(false);
const victoryRewards = ref([]);
const goldGained = ref(0);
const dungeonCleared = ref(false);
/** 新しい魔法を覚えたときのメッセージ（イフリーヌ初回撃破など）。あるときは結果画面が「次へ」で 2 画面になる */
const learnedSpellMessages = ref([]);
/** @type {import('vue').Ref<'rewards' | 'learned'>} */
const resultStep = ref('rewards');

const state = computed(() => battle.state);
const units = computed(() => state.value?.units ?? []);
/** 画面に出すユニット。バッジだけ、再生に合わせた表示用の状態に差し替える */
const displayUnits = computed(() =>
  units.value.map((unit) => {
    const badges = badgesReady.value ? displayBadges.value[unit.id] : emptyBadges();
    return badges ? { ...unit, ...badges } : unit;
  }),
);
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
    route.query.boss !== '1' &&
    route.query.mid_boss !== '1',
);

const canFlee = computed(
  () => canShowFlee.value && awaiting.value && !busy.value && partyPhase.value === 'ready',
);

/** ボス戦（階層ボス・中ボス）。敵を大きく見せる（通常戦は小さめ） */
const isBossBattle = computed(
  () =>
    state.value?.meta?.boss === true
    || state.value?.meta?.mid_boss === true
    || route.query.boss === '1'
    || route.query.mid_boss === '1',
);

/** 空間の裂け目のボス戦（4人の魔法使い）。敵の魔法の詠唱に専用の効果音を鳴らす */
const isRiftBattle = computed(() => state.value?.meta?.rift === true);

/**
 * 空間の裂け目のボスを倒した。結果画面の「次へ」でエンドロールが流れる（何回目に倒しても、必ず流れる）。
 * この戦闘は、戦闘が終わってもBGMを止めず、エンドロールが終わるまで同じBGMを流し続ける。
 */
const isRiftVictory = computed(() => isRiftBattle.value && state.value?.status === 'victory');

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

/** 結果画面に出すゴールド。敗北では、戦闘中に敵を倒していても入らないので出さない（勝ったときだけ） */
const resultGold = computed(() => (state.value?.status === 'victory' ? goldGained.value : 0));

const showLearnedStep = computed(
  () => learnedSpellMessages.value.length > 0 && resultStep.value === 'learned',
);
const showNextButton = computed(
  () => learnedSpellMessages.value.length > 0 && resultStep.value === 'rewards',
);

const resultPrimaryLabel = computed(() => {
  if (isRiftVictory.value) {
    return '次へ';
  }
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

function resetResultPages() {
  learnedSpellMessages.value = [];
  resultStep.value = 'rewards';
}

function onResultNext() {
  resultStep.value = 'learned';
}

/** 結果画面を閉じて、エンドロールを流す（BGMは戦闘のまま。流れきったら onCreditsFinished で待機画面へ戻る） */
function startCredits() {
  showResult.value = false;
  showCredits.value = true;
}

function onCreditsFinished() {
  showCredits.value = false;
  leaveBattle();
}

function onResultPrimary() {
  if (isRiftVictory.value) {
    startCredits();
    return;
  }
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

/** 相手を選ばず、「唱える」ボタンで発動する魔法（全体魔法と、自分に使う魔法） */
const isAllTarget = computed(
  () => targetMode.value === 'enemy_all' || targetMode.value === 'ally_all' || targetMode.value === 'self',
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
      unusable_reason: pending.spell.unusable_reason ?? null,
    };
  }
  return null;
});

/**
 * 選んだ魔法が、今は唱えられない理由（MP が足りない・回復する相手がいない、など。唱えられるなら null）。
 * 唱えられない魔法も選べる（どんな魔法か分かるように）。その代わり、対象を選ぶ画面で、何も選べず、唱えられない。
 */
const pendingUnusableReason = computed(() => pendingAction.value?.spell?.unusable_reason ?? null);

/** 画面（立ち絵・敵）に渡す対象の種類。唱えられない魔法のときは、どの立ち絵・敵も選べない状態（'locked'）にする */
const fieldTargetMode = computed(() => (targetMode.value && pendingUnusableReason.value ? 'locked' : targetMode.value));

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
  if (effect.includes('death')) {
    return 'dark';
  }
  if (effect.includes('heal')) {
    return 'heal';
  }
  if (effect.startsWith('debuff') || effect.includes('down')) {
    return 'debuff';
  }
  if (effect.startsWith('buff') || effect.includes('up') || effect.includes('evasion')) {
    return 'buff';
  }
  if (effect === 'shield_next' || effect === 'life_ward') {
    return 'shield';
  }
  if (effect.includes('revive')) {
    return 'revive';
  }

  return 'buff';
}

/** ダメージの数字が出ている時間（ms）。EffectLayer.vue の .float-damage のアニメーションの長さと合わせる */
const FLOATING_DAMAGE_MS = 1250;

/** ダメージの数字（miss = true のときは、ダメージの代わりに白い「miss」を同じ位置・同じ動きで出す） */
function showFloatingDamage(targetId, value, miss = false) {
  const pos = unitPosition(targetId);
  const isAlly = units.value.find((x) => x.id === targetId)?.side === 'ally';
  floatingDamages.value.push({
    id: `${targetId}-${Date.now()}`,
    value: miss ? 'miss' : `-${value}`,
    miss,
    // 味方のダメージは大きな字で出すので、端の味方でも画面からはみ出さないよう、左右に寄せすぎない
    x: isAlly ? Math.min(Math.max(pos.x, 17), 83) : pos.x,
    y: pos.y,
    // 味方が受けたダメージは、立ち絵の上でも見えるように強調して出す
    ally: isAlly,
  });
  setTimeout(() => {
    floatingDamages.value = floatingDamages.value.slice(1);
  }, FLOATING_DAMAGE_MS);
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

/** 使い魔の支援のバナーを出す（顔つき。時間が来たら消える） */
function showFamiliarBanner(text, portrait, ms = FAMILIAR_BANNER_MS) {
  const id = `${Date.now()}-${Math.random()}`;
  familiarBanner.value = { id, text, portrait };
  setTimeout(() => {
    if (familiarBanner.value?.id === id) familiarBanner.value = null;
  }, ms);
}

/** 告知のバナーが出ている時間（ms）。EffectLayer.vue の .action-banner のアニメーションの長さと合わせる */
const ACTION_BANNER_MS = 1100;
/** 敵の通常攻撃以外の技・魔法の告知は、これだけ長く残す（ms）。.action-banner.is-long のアニメーションの長さと合わせる */
const ENEMY_SKILL_BANNER_EXTRA_MS = 700;
/** 敵の通常攻撃以外の技・魔法は、効果音と告知が出てから、ダメージ・効果が出るまでの間をこれだけ長くする（ms） */
const ENEMY_SKILL_EFFECT_EXTRA_DELAY_MS = 200;

function showActionBanner(text, long = false) {
  const id = `${Date.now()}-${Math.random()}`;
  actionBanners.value = [{ id, text, long }];
  setTimeout(() => {
    actionBanners.value = actionBanners.value.filter((b) => b.id !== id);
  }, long ? ACTION_BANNER_MS + ENEMY_SKILL_BANNER_EXTRA_MS : ACTION_BANNER_MS);
}

function emptyBadges() {
  return { buffs: {}, stages: { def: 0, atk: 0 }, damage_shield: false, life_ward: false };
}

/** バッジの表示を、いまの state に合わせる（イベント再生が終わったあと・再読み込み時） */
function syncDisplayBadges() {
  displayBadges.value = Object.fromEntries(
    units.value.map((unit) => [
      unit.id,
      {
        buffs: { ...(unit.buffs ?? {}) },
        stages: { def: unit.stages?.def ?? 0, atk: unit.stages?.atk ?? 0 },
        damage_shield: Boolean(unit.damage_shield),
        life_ward: Boolean(unit.life_ward),
      },
    ]),
  );
  badgesReady.value = true;
}

/** バッジの表示を、全員なし（戦闘の始まり）にする。そこからイベントの再生に合わせて付いていく */
function resetDisplayBadges() {
  displayBadges.value = Object.fromEntries(units.value.map((unit) => [unit.id, emptyBadges()]));
  badgesReady.value = true;
}

/**
 * buff_applied / buff_expired を再生したときに、そのユニットのバッジを、イベントが持つ「その時点の状態」にする。
 * サーバーは、付けた直後・切れた直後の、防御・攻撃の段階（stages）と回避などの効果（buffs）をそのまま送ってくる。
 * 画面で足し引きすると、切れた効果が画面に残っているぶんだけ本当の段階とずれる（防御+2 が切れたあとに -3 を受けて -1 と出る、など）ので、
 * 足し算はしない。古い形式のイベント（stages が無い）のときだけ、従来どおり段階を足す。
 */
function applyBadgeForBuff(ev) {
  const badges = displayBadges.value[ev.target];
  if (!badges) return;
  if (ev.stages) {
    badges.stages = { def: Number(ev.stages.def ?? 0), atk: Number(ev.stages.atk ?? 0) };
    badges.buffs = { ...(ev.buffs ?? {}) };
    return;
  }
  if (ev.stat) {
    const current = badges.stages[ev.stat] ?? 0;
    badges.stages[ev.stat] = Math.max(-3, Math.min(3, current + Number(ev.applied ?? ev.delta ?? 0)));
  } else if (ev.buff) {
    badges.buffs[ev.buff] = 3;
  }
}

function setBadgeFlag(unitId, key, value) {
  const badges = displayBadges.value[unitId];
  if (badges) badges[key] = value;
}

function syncDisplayHp(force = false) {
  for (const unit of units.value) {
    if (force || displayHp.value[unit.id] === undefined) {
      displayHp.value[unit.id] = unit.hp;
    }
  }
}

/** 状態の「逃げた敵」を表示に反映する（イベント再生が終わったあと・再読み込み時に使う） */
function syncFledDisplay() {
  fledIds.value = units.value.filter((u) => u.fled).map((u) => u.id);
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

/** 使い魔の支援バナー1枚の表示時間（ms）。CSS の familiarBannerPop(1.2s) に合わせる */
const FAMILIAR_BANNER_MS = 1200;

/** 敵アイコンが前に出る動きの長さ（ms） */
const ENEMY_LUNGE_MS = 280;

/**
 * 魔法の告知イベントか（フラッシュ・効果音・間を出す対象）。
 * 「〇〇は△△を唱えた！」だけでなく、特技（演奏・調合など。「〇〇の△△！」）も同じ演出にするため、
 * 文章の「唱え」ではなく、魔法の告知に付く spell_effect / spell_element で見分ける。
 * （詠唱の途中の「詠唱し始めた！」は、どちらも付かないので、演出は出ない）
 */
function isSpellAnnounce(ev) {
  if (ev?.type !== 'announce') return false;
  return Boolean(ev.spell_effect || ev.spell_element || ev.text?.includes('唱え'));
}

async function playEvents(events) {
  for (let i = 0; i < events.length; i += 1) {
    const ev = events[i];
    if (ev.type === 'turn_start') {
      visualCurrentActor.value = ev.actor;
      playSe('attack_before');
      // 敵はアイコンが前に出る（どの敵の行動か分かるように）。物理攻撃は動き終えてから告知を出すが、
      // 魔法の詠唱は動きを待たず、PC と同じタイミングで告知をすぐ出す（動きと同時に進める。
      // 動いてから遅れて「〇〇を唱えた！」が出るとテンポが悪い）
      if (String(ev.actor).startsWith('enemy')) {
        const actor = ev.actor;
        const clearLunge = () => {
          if (activeEnemyLunge.value === actor) activeEnemyLunge.value = null;
        };
        activeEnemyLunge.value = actor;
        if (isSpellAnnounce(events[i + 1])) {
          setTimeout(clearLunge, ENEMY_LUNGE_MS);
        } else {
          await delay(ENEMY_LUNGE_MS);
          clearLunge();
        }
      }
      await delay(120);
    }
    if (ev.type === 'announce') {
      // 敵の通常攻撃以外の技・魔法（「〇〇は△△を唱えた！」「〇〇の△△！」）は、文字を長く残し、効果が出るまでも少し長くする
      const enemySkill = ev.skill === true;
      const extraDelay = enemySkill ? ENEMY_SKILL_EFFECT_EXTRA_DELAY_MS : 0;
      showActionBanner(ev.text, enemySkill);
      const isSpell = isSpellAnnounce(ev);
      if (isSpell) {
        showFlash(flashKindForSpellAnnounce(ev));
        // 裂け目のボス戦だけ、敵の詠唱は専用の音（PC の詠唱や、ほかの戦闘の敵は今までどおり magic）
        playSe(isRiftBattle.value && String(ev.actor).startsWith('enemy') ? 'enemy_magic' : 'magic');
        await delay(SPELL_CAST_ANNOUNCE_DELAY_MS + extraDelay);
      } else {
        await delay(360 + extraDelay);
      }
    }
    if (ev.type === 'miss') {
      // 外れたときは、ダメージの数字と同じように、狙われたユニットの位置に白い「miss」を出す
      showFloatingDamage(ev.target, 0, true);
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
      // 戦闘不能になった（表示 HP が 0 になった）ユニットは、バフ・デバフのバッジがすべて消える（サーバーの state も同じ）
      if ((displayHp.value[ev.target] ?? 1) <= 0 && displayBadges.value[ev.target]) {
        displayBadges.value[ev.target] = emptyBadges();
      }
      shakeTarget.value = null;
      if (ev.instant_death) {
        showActionBanner('即死！');
        await delay(500);
      }
      await delay(120);
    }
    if (ev.type === 'shield_break') {
      setBadgeFlag(ev.target, 'damage_shield', false);
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
      applyBadgeForBuff(ev);
      // 防御・攻撃は delta（段階。マイナスなら下がる）で、回避など delta の無い効果は常にバフ扱い
      const isDebuff = Number(ev.delta ?? 0) < 0;
      showFloatingSupport(ev.target, isDebuff ? '▼' : '▲', isDebuff ? 'debuff' : 'buff');
      // PC にデバフが入ったときは、成功の音ではなくデバフの音を鳴らす
      playSe(isDebuff && !String(ev.target).startsWith('enemy') ? 'debahu' : 'success');
      await showSupportEffect(ev.target, isDebuff ? 'debuff' : 'buff');
      await delay(120);
    }
    if (ev.type === 'buff_expired') {
      // ラウンドの終わりに、持続が切れた。そのユニットのバッジを、いまの状態（切れずに残っている分）にする。演出は無し（バッジが消えるだけ）
      applyBadgeForBuff(ev);
    }
    if (ev.type === 'mp_recovered') {
      // 魔力集中: MP が回復した（直前の damage が、引き換えに減った HP）
      showFloatingSupport(ev.target, `MP +${ev.value}`, 'mp');
      playSe('heal');
      await showSupportEffect(ev.target, 'buff');
      await delay(120);
    }
    if (ev.type === 'shield_applied') {
      setBadgeFlag(ev.target, 'damage_shield', true);
      showFloatingSupport(ev.target, '盾', 'shield');
      playSe('success');
      await showSupportEffect(ev.target, 'shield');
      await delay(120);
    }
    if (ev.type === 'familiar_support') {
      // 「使い魔たちの支援行動！」。SE を鳴らしながら顔つきバナーを出す
      playSe('tukaima');
      showFamiliarBanner(ev.text, ev.portrait);
      await delay(FAMILIAR_BANNER_MS);
    }
    if (ev.type === 'familiar_action') {
      // 発動した行動のメッセージ（このあと、効果の damage / heal などが続く）
      showFamiliarBanner(ev.text, ev.portrait);
      await delay(FAMILIAR_BANNER_MS);
    }
    if (ev.type === 'life_ward_applied') {
      setBadgeFlag(ev.target, 'life_ward', true);
      showFloatingSupport(ev.target, '加護', 'shield');
      playSe('success');
      await showSupportEffect(ev.target, 'shield');
      await delay(120);
    }
    if (ev.type === 'life_ward_triggered') {
      setBadgeFlag(ev.target, 'life_ward', false);
      showActionBanner(`${unitName(ev.target)}は命の加護で踏みとどまった！`);
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
    if (ev.type === 'spell_failed') {
      // 即死魔法（クリーナーのデリートなど）が、その相手に効かなかった。
      // 対象が複数でも、何が起きたか分かるように、狙われた相手の位置に白い「miss」を、1 人ずつ順番に出す（回避と同じ見せ方）
      showFloatingDamage(ev.target, 0, true);
      playSe('miss');
      await delay(280);
      await delay(120);
    }
    if (ev.type === 'revive_failed') {
      showActionBanner('効果がなかった…');
      await delay(280);
    }
    if (ev.type === 'enemy_fled') {
      if (!fledIds.value.includes(ev.actor)) {
        fledIds.value = [...fledIds.value, ev.actor];
      }
      await delay(500);
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
    if (ev.type === 'spells_learned') {
      // 「覚えた」の行を先に並べ、そのあとに「忘れた」の行（強化版に置き換わった魔法）を並べる
      const characters = ev.characters ?? [];
      learnedSpellMessages.value = [
        ...characters.map((c) => `${c.name}は新しい魔法を覚えた！`),
        ...characters.flatMap((c) => (c.forgotten ?? []).map((s) => `${c.name}は${s.label}を忘れた…`)),
      ];
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
      // 裂け目のボスに勝ったときだけ、BGMを止めない（そのままエンドロールまで流す）。負けたときは今までどおり
      if (!isRiftVictory.value) {
        fadeOutBgm();
      }
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
  showCredits.value = false;
  dungeonCleared.value = false;
  goldGained.value = 0;
  fledIds.value = [];
  resetResultPages();
  try {
    let startedNew = false;
    let playPartyEntrance = false;
    if (props.id) {
      if (battle.battleId !== props.id) {
        await battle.load(props.id);
        syncFledDisplay();
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
    // 再生するイベントがあるのは新しい戦闘（バッジなしから、再生に合わせて付いていく）。なければ途中からなので state に合わせる
    if (battle.pendingEvents.length > 0) {
      resetDisplayBadges();
    } else {
      syncDisplayBadges();
    }
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
    syncDisplayBadges();
    syncFledDisplay();
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
    syncDisplayBadges();
    syncFledDisplay();
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
  // どの魔法も、選んだら、まず説明の画面（TargetOverlay）を出す。自分に使う魔法（魔力集中）も、すぐには唱えず、
  // ほかの全体魔法と同じく、この画面で「唱える」を押したら発動する（唱えられないときは「唱える」を押せず、理由が出る）
  pendingAction.value = { action: 'spell', spell_id: spell.id, spell };
  targetMode.value = spell.target_type;
}

function cancelTarget() {
  targetMode.value = null;
  pendingAction.value = null;
}

async function onSelectTarget(targetId) {
  if (!pendingAction.value || pendingUnusableReason.value) return;
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
  if (!pendingAction.value || pendingUnusableReason.value) return;
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
  fledIds.value = [];
  resetResultPages();
  busy.value = true;
  try {
    await battle.startDemo();
    router.replace({ name: 'battle', params: { id: battle.battleId } });
    syncDisplayHp(true);
    resetDisplayBadges();
    await tryPlayBgm({ restart: true });
    await runPartyEntrance();
    const events = battle.dequeueEvents();
    if (events.length > 0) {
      rewindDisplayHpForEvents(events);
    }
    await playEvents(events);
    syncDisplayHp(true);
    syncDisplayBadges();
    syncFledDisplay();
  } catch (e) {
    battle.error = e.message;
    partyPhase.value = 'ready';
  } finally {
    busy.value = false;
  }
}

function leaveBattle() {
  const wasDungeon = isDungeonBattle.value;
  const wasRift = isRiftBattle.value;
  const status = state.value?.status;
  const cleared = dungeonCleared.value;

  showResult.value = false;
  showCredits.value = false;
  victoryRewards.value = [];
  dungeonCleared.value = false;
  goldGained.value = 0;
  resetResultPages();

  const destination = (() => {
    // 裂け目のボスを倒したあと（エンドロールのあと）。探索から入った戦闘は待機画面へ、テスト戦闘はテスト戦闘の一覧へ
    if (wasRift && status === 'victory') {
      return wasDungeon ? '/hub' : '/test_battles';
    }
    if (cleared || (status === 'defeat' && wasDungeon)) {
      return '/hub';
    }
    if ((status === 'victory' || status === 'fled') && wasDungeon) {
      return '/dungeon';
    }
    return '/';
  })();

  // 戦闘の state は、移動が終わってから空にする。先に空にすると、ルートが切り替わる前に BGM が戦闘のものから
  // 別のもの（通常戦の BGM）に一瞬変わってしまう（エンドロールの最後に、BGM が途切れて鳴り直さないように）
  player.fetchNavigation().finally(() => {
    router.push(destination).finally(() => {
      battle.battleId = null;
      battle.state = null;
      battle.pendingEvents = [];
    });
  });
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
      :familiar-banner="familiarBanner"
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
        :units="displayUnits"
        :display-hp="displayHp"
        :buff-icons="buffIcons"
        :party-phase="partyPhase"
        :current-actor="displayCurrentActor"
        :target-mode="fieldTargetMode"
        :spell-effect="spellEffect"
        :shake-target="shakeTarget"
        :support-effect-target="supportEffectTarget"
        :active-enemy-lunge="activeEnemyLunge"
        :floor1-boss-sprite="floor1BossSprite"
        :boss-battle="isBossBattle"
        :fled-ids="fledIds"
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
      :execute-disabled="!!pendingUnusableReason"
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

    <EndingCredits v-if="showCredits" @finished="onCreditsFinished" />

    <div v-if="showResult" class="result-modal">
      <h2>{{ resultTitle }}</h2>
      <ul v-if="showLearnedStep" class="reward-list">
        <li v-for="(line, i) in learnedSpellMessages" :key="i">{{ line }}</li>
      </ul>
      <ul v-else-if="victoryRewards.length || resultGold > 0" class="reward-list">
        <li v-if="resultGold > 0">ゴールドを {{ resultGold }} 獲得した</li>
        <li v-for="(line, i) in victoryRewards" :key="i">{{ line }}</li>
      </ul>
      <button v-if="showNextButton" type="button" class="btn-primary" @click="onResultNext">
        次へ
      </button>
      <template v-else>
        <button type="button" class="btn-primary" @click="onResultPrimary">
          {{ resultPrimaryLabel }}
        </button>
        <button v-if="!isDungeonBattle" type="button" class="btn-ghost" @click="router.push('/')">
          タイトルへ
        </button>
      </template>
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
