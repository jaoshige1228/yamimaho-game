<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api';
import { useAppAudio } from '../../composables/useAppAudio.js';

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

const selectedTarget = computed(
  () => props.party.find((u) => u.slot_id === targetSlotId.value || u.id === targetSlotId.value) ?? null,
);

function itemUsable(item, target) {
  if (!target) return false;
  if (item.effect === 'revive') return !target.alive || target.hp <= 0;
  if (item.effect === 'heal_hp') return target.alive && target.hp < target.max_hp;
  if (item.effect === 'heal_mp') return target.alive && target.mp < target.max_mp;
  return false;
}

function spellUsable(spell, target) {
  if (!target || !spell.affordable) return false;
  if (spell.effect === 'revive_chance') return !target.alive || target.hp <= 0;
  if (spell.effect === 'heal_mag' || spell.effect === 'heal') {
    return target.alive && target.hp < target.max_hp;
  }
  return false;
}

async function loadSpells() {
  const data = await api('/dungeon/heal/options', { method: 'GET' });
  spells.value = data.spells ?? [];
}

watch(
  () => props.visible,
  (open) => {
    if (open) {
      tab.value = 'item';
      targetSlotId.value = props.party[0]?.slot_id ?? props.party[0]?.id ?? '';
      error.value = '';
      loadSpells().catch((e) => {
        error.value = e.message;
      });
    }
  },
);

async function useItem(item) {
  if (!selectedTarget.value) return;
  busy.value = true;
  error.value = '';
  try {
    unlock();
    playSe('cursor');
    const data = await api('/dungeon/heal', {
      method: 'POST',
      body: JSON.stringify({
        mode: 'item',
        item_code: item.code,
        target_slot_id: selectedTarget.value.slot_id ?? selectedTarget.value.id,
      }),
    });
    emit('updated', data);
    playSe('heal');
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

async function useSpell(spell) {
  if (!selectedTarget.value) return;
  busy.value = true;
  error.value = '';
  try {
    unlock();
    playSe('cursor');
    const data = await api('/dungeon/heal', {
      method: 'POST',
      body: JSON.stringify({
        mode: 'spell',
        caster_slot_id: spell.caster_slot_id,
        spell_id: spell.id,
        target_slot_id: selectedTarget.value.slot_id ?? selectedTarget.value.id,
      }),
    });
    emit('updated', data);
    playSe('magic');
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="heal-modal-root" @click.self="emit('close')">
      <div class="heal-modal" role="dialog" aria-modal="true" aria-label="回復">
        <header class="modal-header">
          <h3>回復</h3>
          <button type="button" class="close" aria-label="閉じる" @click="emit('close')">×</button>
        </header>

        <div class="tabs">
          <button type="button" :class="{ active: tab === 'item' }" @click="tab = 'item'">アイテム</button>
          <button type="button" :class="{ active: tab === 'spell' }" @click="tab = 'spell'">魔法</button>
        </div>

        <section class="target-section">
          <p class="section-label">対象</p>
          <div class="target-tabs">
            <button
              v-for="unit in party"
              :key="unit.slot_id ?? unit.id"
              type="button"
              class="target-tab"
              :class="{ active: targetSlotId === (unit.slot_id ?? unit.id) }"
              @click="targetSlotId = unit.slot_id ?? unit.id"
            >
              {{ unit.name }}
              <span class="hp-mini">{{ unit.hp }}/{{ unit.max_hp }}</span>
            </button>
          </div>
        </section>

        <section v-if="tab === 'item'" class="action-section">
          <button
            v-for="item in items"
            :key="item.code"
            type="button"
            class="action-btn"
            :disabled="busy || !itemUsable(item, selectedTarget)"
            @click="useItem(item)"
          >
            <span class="label">{{ item.name }} ×{{ item.quantity }}</span>
            <span class="desc">{{ item.description }}</span>
          </button>
        </section>

        <section v-else class="action-section">
          <button
            v-for="spell in spells"
            :key="`${spell.caster_slot_id}-${spell.id}`"
            type="button"
            class="action-btn"
            :disabled="busy || !spellUsable(spell, selectedTarget)"
            @click="useSpell(spell)"
          >
            <span class="label">{{ spell.caster_name }}：{{ spell.label }}</span>
            <span class="desc">MP {{ spell.mp_cost }}</span>
          </button>
        </section>

        <p v-if="error" class="error">{{ error }}</p>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.heal-modal-root {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgba(0, 0, 0, 0.55);
  padding: 1rem;
  padding-bottom: calc(1rem + env(safe-area-inset-bottom));
}
.heal-modal {
  width: min(430px, 100%);
  max-height: 78vh;
  overflow-y: auto;
  background: var(--panel, rgba(12, 8, 24, 0.98));
  border-radius: 16px;
  padding: 0.85rem;
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
}
.close {
  border: none;
  background: transparent;
  color: #fff;
  font-size: 1.4rem;
  cursor: pointer;
}
.tabs,
.target-tabs {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.35rem;
  margin-bottom: 0.65rem;
}
.tabs button,
.target-tab {
  border: 1px solid rgba(157, 124, 255, 0.25);
  background: rgba(255, 255, 255, 0.04);
  color: #d4c4ff;
  border-radius: 8px;
  padding: 0.45rem 0.35rem;
  font-size: 0.72rem;
  cursor: pointer;
}
.tabs button.active,
.target-tab.active {
  border-color: rgba(200, 176, 255, 0.7);
  background: rgba(80, 48, 140, 0.55);
  color: #fff;
}
.target-tabs {
  grid-template-columns: repeat(4, 1fr);
}
.section-label {
  margin: 0 0 0.35rem;
  font-size: 0.68rem;
  color: #b8a8d8;
}
.hp-mini {
  display: block;
  font-size: 0.55rem;
  color: #a898c8;
}
.action-section {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.action-btn {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.1rem;
  border: 1px solid rgba(157, 124, 255, 0.2);
  background: rgba(255, 255, 255, 0.04);
  color: #f8f4ff;
  border-radius: 10px;
  padding: 0.55rem 0.65rem;
  cursor: pointer;
  text-align: left;
}
.action-btn:disabled {
  opacity: 0.4;
  cursor: default;
}
.action-btn .label {
  font-size: 0.78rem;
  font-weight: 700;
}
.action-btn .desc {
  font-size: 0.62rem;
  color: #a898c8;
}
.error {
  margin: 0.5rem 0 0;
  color: #ff8a8a;
  font-size: 0.75rem;
}
</style>
