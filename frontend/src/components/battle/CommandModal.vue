<script setup>
import { computed } from 'vue';
import { useAppAudio } from '../../composables/useAppAudio.js';

const props = defineProps({
  visible: { type: Boolean, default: false },
  commands: { type: Object, default: null },
  actorName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'punch', 'kick', 'spell']);

const { playSe, unlock } = useAppAudio();

/**
 * 魔法・特技の大分け（サーバーが spell.category で渡す。spell_masters.csv の category 列）。
 * 「こぶし」「キック」はどのラベルにも入れず、いつも一番上に出す。
 */
const CATEGORY_LABELS = { attack: '攻撃', heal: '回復', support: '補助' };
const CATEGORY_ORDER = ['attack', 'heal', 'support'];

/** ラベルごとの魔法の並び（魔法が 1 つもないラベルは出さない。並びは、ラベルの中では今までの並び順のまま） */
const spellGroups = computed(() => {
  const spells = props.commands?.spells ?? [];
  const categoryOf = (spell) => (CATEGORY_ORDER.includes(spell.category) ? spell.category : 'support');

  return CATEGORY_ORDER.map((category) => ({
    category,
    label: CATEGORY_LABELS[category],
    spells: spells.filter((spell) => categoryOf(spell) === category),
  })).filter((group) => group.spells.length > 0);
});

/**
 * 今は唱えられない魔法（MP が足りない・回復する相手がいない・蘇生する相手がいない・魔力集中の条件を満たさない、など）。
 * 押せなくはしない。戦闘中は、魔法を選ばないと、どんな魔法なのか（説明）が分からないため、いつでも選べる。
 * 唱えられないときは、選んだあとの画面に理由が出て、対象を選ぶ・唱えることだけができない。ここでは、薄く表示して知らせるだけ。
 */
function isSpellUnusable(spell) {
  return !spell.affordable || spell.usable === false;
}

function onChoose(event, payload) {
  unlock();
  playSe('cursor');
  emit(event, payload);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible && commands" class="command-modal-root" @click.self="emit('close')">
      <div class="command-modal" role="dialog" aria-modal="true" :aria-label="`${actorName}のコマンド`">
        <header class="modal-header">
          <h3>{{ actorName }} のコマンド</h3>
          <button type="button" class="close" aria-label="やめる" @click="emit('close')">×</button>
        </header>
        <div class="row basic">
          <button type="button" class="cmd" @click="onChoose('punch')">こぶし</button>
          <button type="button" class="cmd" @click="onChoose('kick')">キック</button>
        </div>
        <div class="spell-groups">
          <section
            v-for="group in spellGroups"
            :key="group.category"
            class="spell-group"
            :data-category="group.category"
          >
            <h4 class="group-label">{{ group.label }}</h4>
            <div class="spells">
              <button
                v-for="spell in group.spells"
                :key="spell.id"
                type="button"
                class="cmd spell"
                :class="{ unusable: isSpellUnusable(spell) }"
                @click="onChoose('spell', spell)"
              >
                <span class="label">{{ spell.label }}</span>
                <span class="mp">MP {{ spell.mp_cost }}</span>
              </button>
            </div>
          </section>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.command-modal-root {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgba(0, 0, 0, 0.55);
  padding: 1rem;
  padding-bottom: calc(1rem + env(safe-area-inset-bottom));
}
.command-modal {
  box-sizing: border-box;
  width: min(430px, 100%);
  /* 魔法が多いキャラ（サフィリアは 10 個）でも、背の低い画面で見切れない。タイトルと「こぶし」「キック」は動かさず、
     魔法の一覧（.spell-groups）だけが、収まらないときに中でスクロールする */
  max-height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--panel);
  border-radius: 16px;
  padding: 0.85rem;
  backdrop-filter: blur(10px);
  box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.45);
}
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.65rem;
}
.modal-header h3 {
  margin: 0;
  font-size: 0.95rem;
  color: var(--text);
}
.close {
  border: none;
  background: transparent;
  color: #aaa;
  font-size: 1.5rem;
  line-height: 1;
  min-width: 44px;
  min-height: 44px;
  cursor: pointer;
}
.row.basic {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}
.spell-groups {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.spell-group + .spell-group {
  margin-top: 0.15rem;
}
/* 「攻撃」「回復」「補助」の軽いラベル。小さく・薄く、横に細い線を引く */
.group-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0.45rem 0.1rem 0.3rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: #a898c8;
}
.group-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(168, 152, 200, 0.28);
}
.spells {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}
.cmd {
  min-height: 48px;
  border: 1px solid rgba(157, 124, 255, 0.4);
  border-radius: 10px;
  background: rgba(40, 28, 70, 0.9);
  color: var(--text);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}
.cmd:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
/* 今は唱えられない魔法。押せる（説明を見られる）が、薄く出して知らせる */
.cmd.spell.unusable {
  opacity: 0.55;
}
.cmd.spell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 0.5rem;
}
.mp {
  font-size: 0.7rem;
  color: var(--mp);
  font-weight: 500;
}
</style>
