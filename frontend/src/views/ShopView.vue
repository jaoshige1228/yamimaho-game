<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const buying = ref(false);
const error = ref('');
const catalog = ref(null);
const selectedSlot = ref('pc1');

const selectedCharacter = computed(
  () => catalog.value?.characters?.find((c) => c.slot_id === selectedSlot.value) ?? null,
);

async function loadCatalog() {
  loading.value = true;
  error.value = '';
  try {
    catalog.value = await api('/shop/equipment', { method: 'GET' });
    if (catalog.value?.gold != null) player.setGold(catalog.value.gold);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function buy(kind, code) {
  buying.value = true;
  error.value = '';
  try {
    const data = await api('/shop/equipment/buy', {
      method: 'POST',
      body: JSON.stringify({
        slot_id: selectedSlot.value,
        kind,
        code,
      }),
    });
    catalog.value = data.catalog;
    if (data.gold != null) player.setGold(data.gold);
  } catch (e) {
    error.value = e.message;
  } finally {
    buying.value = false;
  }
}

function isOwned(kind, code) {
  const c = selectedCharacter.value;
  if (!c) return false;
  return kind === 'weapon'
    ? c.owned_weapon_codes?.includes(code)
    : c.owned_armor_codes?.includes(code);
}

function canBuy(item) {
  return !isOwned('weapon', item.code) && player.gold >= item.price;
}

onMounted(loadCatalog);
</script>

<template>
  <div class="hub-screen shop-screen">
    <header class="sub-screen-header">
      <button type="button" class="hub-screen-back" @click="router.push({ name: 'hub' })">←</button>
      <h1>武具屋</h1>
      <span class="gold-label">{{ player.gold }} G</span>
    </header>

    <p v-if="loading" class="hub-screen-muted">読み込み中…</p>
    <p v-else-if="error" class="hub-screen-error">{{ error }}</p>

    <template v-else-if="catalog">
      <div class="slot-tabs">
        <button
          v-for="c in catalog.characters"
          :key="c.slot_id"
          type="button"
          class="slot-tab"
          :class="{ active: selectedSlot === c.slot_id }"
          @click="selectedSlot = c.slot_id"
        >
          {{ c.name }}
        </button>
      </div>

      <section class="shop-section">
        <h2>武器</h2>
        <div v-for="item in catalog.weapons" :key="item.code" class="shop-row">
          <div class="shop-row-main">
            <strong>{{ item.name }}</strong>
            <span class="shop-effect">魔力 +{{ item.mag_bonus }}</span>
            <span v-if="item.description" class="shop-desc">{{ item.description }}</span>
          </div>
          <div class="shop-row-action">
            <span class="price">{{ item.price }} G</span>
            <button
              v-if="isOwned('weapon', item.code)"
              type="button"
              class="shop-btn owned"
              disabled
            >
              所持済
            </button>
            <button
              v-else
              type="button"
              class="shop-btn"
              :disabled="buying || !canBuy(item)"
              @click="buy('weapon', item.code)"
            >
              購入
            </button>
          </div>
        </div>
      </section>

      <section class="shop-section">
        <h2>防具</h2>
        <div v-for="item in catalog.armors" :key="item.code" class="shop-row">
          <div class="shop-row-main">
            <strong>{{ item.name }}</strong>
            <span class="shop-effect">防御 +{{ item.def_bonus }}</span>
            <span v-if="item.description" class="shop-desc">{{ item.description }}</span>
          </div>
          <div class="shop-row-action">
            <span class="price">{{ item.price }} G</span>
            <button
              v-if="isOwned('armor', item.code)"
              type="button"
              class="shop-btn owned"
              disabled
            >
              所持済
            </button>
            <button
              v-else
              type="button"
              class="shop-btn"
              :disabled="buying || player.gold < item.price"
              @click="buy('armor', item.code)"
            >
              購入
            </button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.shop-screen {
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
  flex: 1;
  font-size: 1.1rem;
}
.gold-label {
  font-size: 0.85rem;
  color: #ffd98a;
  font-weight: 700;
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
.shop-section {
  padding: 0.5rem 1rem 1rem;
}
.shop-section h2 {
  margin: 0 0 0.5rem;
  font-size: 0.85rem;
  color: #c8b0ff;
}
.shop-row {
  display: flex;
  gap: 0.75rem;
  justify-content: space-between;
  align-items: flex-start;
  padding: 0.65rem 0.75rem;
  margin-bottom: 0.45rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(157, 124, 255, 0.18);
}
.shop-row-main {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}
.shop-row-main strong {
  font-size: 0.88rem;
}
.shop-effect {
  font-size: 0.72rem;
  color: #ffd98a;
}
.shop-desc {
  font-size: 0.65rem;
  color: #a898c8;
  line-height: 1.35;
}
.shop-row-action {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.35rem;
  flex-shrink: 0;
}
.price {
  font-size: 0.75rem;
  color: #ffd98a;
  font-weight: 700;
}
.shop-btn {
  border: none;
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.72rem;
  font-weight: 700;
  background: linear-gradient(180deg, #7b5cff, #5a3fd4);
  color: #fff;
  cursor: pointer;
}
.shop-btn:disabled {
  opacity: 0.45;
  cursor: default;
}
.shop-btn.owned {
  background: rgba(255, 255, 255, 0.08);
  color: #b8a8d8;
}
</style>
