<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import '../styles/hub-screen.css';

const router = useRouter();
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const equipment = ref(null);
const selectedSlot = ref('pc1');

const selectedCharacter = computed(
  () => equipment.value?.characters?.find((c) => c.slot_id === selectedSlot.value) ?? null,
);

async function loadEquipment() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('/player/equipment', { method: 'GET' });
    equipment.value = data;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function equipWeapon(code) {
  await equip({ weapon_code: code });
}

async function equipArmor(code) {
  await equip({ armor_code: code });
}

async function equip(payload) {
  saving.value = true;
  error.value = '';
  try {
    const data = await api('/player/equipment/equip', {
      method: 'POST',
      body: JSON.stringify({
        slot_id: selectedSlot.value,
        ...payload,
      }),
    });
    equipment.value = data.equipment;
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
}

function isEquipped(kind, code) {
  const c = selectedCharacter.value;
  if (!c) return false;
  return kind === 'weapon'
    ? c.equipped_weapon?.code === code
    : c.equipped_armor?.code === code;
}

onMounted(loadEquipment);
</script>

<template>
  <div class="hub-screen equip-screen">
    <header class="sub-screen-header">
      <button type="button" class="hub-screen-back" @click="router.push({ name: 'hub' })">←</button>
      <h1>装備を整える</h1>
    </header>

    <p v-if="loading" class="hub-screen-muted">読み込み中…</p>
    <p v-else-if="error" class="hub-screen-error">{{ error }}</p>

    <template v-else-if="equipment">
      <div class="slot-tabs">
        <button
          v-for="c in equipment.characters"
          :key="c.slot_id"
          type="button"
          class="slot-tab"
          :class="{ active: selectedSlot === c.slot_id }"
          @click="selectedSlot = c.slot_id"
        >
          {{ c.name }}
        </button>
      </div>

      <template v-if="selectedCharacter">
        <section class="equip-section">
          <h2>武器</h2>
          <div class="equip-grid">
            <button
              v-for="item in selectedCharacter.owned_weapons"
              :key="item.code"
              type="button"
              class="equip-card"
              :class="{ active: isEquipped('weapon', item.code) }"
              :disabled="saving"
              @click="equipWeapon(item.code)"
            >
              <span class="name">{{ item.name }}</span>
              <span class="bonus">魔力 +{{ item.mag_bonus }}</span>
            </button>
          </div>
        </section>

        <section class="equip-section">
          <h2>防具</h2>
          <div class="equip-grid">
            <button
              v-for="item in selectedCharacter.owned_armors"
              :key="item.code"
              type="button"
              class="equip-card"
              :class="{ active: isEquipped('armor', item.code) }"
              :disabled="saving"
              @click="equipArmor(item.code)"
            >
              <span class="name">{{ item.name }}</span>
              <span class="bonus">防御 +{{ item.def_bonus }}</span>
            </button>
          </div>
        </section>

        <p class="equip-hint">
          未所持の装備は
          <button type="button" class="link-btn" @click="router.push({ name: 'shop' })">武具屋</button>
          で購入できます。
        </p>
      </template>
    </template>
  </div>
</template>

<style scoped>
.equip-screen {
  overflow-y: auto;
  padding-bottom: calc(72px + env(safe-area-inset-bottom));
}
.sub-screen-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem 1rem 0.5rem;
  padding-top: calc(1rem + env(safe-area-inset-top));
}
.sub-screen-header h1 {
  margin: 0;
  font-size: 1.1rem;
}
.slot-tabs {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.35rem;
  padding: 0.5rem 1rem;
}
.slot-tab {
  border: 1px solid rgba(157, 124, 255, 0.25);
  background: rgba(255, 255, 255, 0.04);
  color: #d4c4ff;
  border-radius: 8px;
  padding: 0.4rem 0.2rem;
  font-size: 0.62rem;
  cursor: pointer;
}
.slot-tab.active {
  border-color: rgba(200, 176, 255, 0.7);
  background: rgba(80, 48, 140, 0.55);
  color: #fff;
}
.equip-section {
  padding: 0.5rem 1rem;
}
.equip-section h2 {
  margin: 0 0 0.5rem;
  font-size: 0.85rem;
  color: #c8b0ff;
}
.equip-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
}
.equip-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  padding: 0.65rem 0.7rem;
  border-radius: 10px;
  border: 1px solid rgba(157, 124, 255, 0.2);
  background: rgba(255, 255, 255, 0.04);
  color: #f8f4ff;
  cursor: pointer;
  text-align: left;
}
.equip-card.active {
  border-color: rgba(255, 217, 138, 0.75);
  background: rgba(120, 88, 24, 0.35);
}
.equip-card .name {
  font-size: 0.78rem;
  font-weight: 700;
}
.equip-card .bonus {
  font-size: 0.65rem;
  color: #ffd98a;
}
.equip-hint {
  padding: 0.75rem 1rem 1rem;
  font-size: 0.72rem;
  color: #a898c8;
}
.link-btn {
  border: none;
  background: none;
  color: #c8b0ff;
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
}
</style>
