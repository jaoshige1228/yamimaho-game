<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import ScenePanel from '../components/exploration/ScenePanel.vue';
import HubIcon from '../components/hub/HubIcon.vue';
import HubPartyFooter from '../components/hub/HubPartyFooter.vue';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';
import '../styles/exploration.css';
import { publicAssetUrl } from '../utils/publicAssetUrl.js';

const hubBgSrc = publicAssetUrl('/assets/bg/home.webp');

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const error = ref('');
const party = ref([]);

async function loadHubData() {
  loading.value = true;
  error.value = '';
  try {
    const partyData = await api('/player/party', { method: 'GET' });
    party.value = partyData.characters ?? [];
    if (partyData.gold != null) player.setGold(partyData.gold);
    if (partyData.items != null) player.setItems(partyData.items);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

onMounted(() => loadHubData());
</script>

<template>
  <div class="hub-screen">
    <div class="exploration-body">
      <ScenePanel :image-src="hubBgSrc" :loading="loading">
        <div v-if="!loading" class="hub-shade" aria-hidden="true" />

        <div v-if="!loading" class="hub-layout">
          <div class="hub-top">
            <button
              type="button"
              class="hub-title-back"
              aria-label="タイトルへ戻る"
              @click="router.push({ name: 'home' })"
            >
              <HubIcon name="back" class="hub-title-back__icon" />
              <span class="hub-title-back__label">タイトルへ</span>
            </button>
          </div>

          <nav class="hub-menu" aria-label="ホームのメニュー">
            <button type="button" class="hub-dive" @click="router.push({ name: 'dungeon-enter' })">
              <HubIcon name="dungeon" class="hub-dive__icon" />
              <span class="hub-dive__label">ダンジョンに潜る</span>
            </button>

            <div class="hub-tiles">
              <button
                type="button"
                class="hub-tile"
                aria-label="武具屋へ"
                @click="router.push({ name: 'shop' })"
              >
                <HubIcon name="shop" class="hub-tile__icon" />
                <span class="hub-tile__label">武具屋</span>
              </button>
              <button
                type="button"
                class="hub-tile"
                aria-label="持ち物を見る"
                @click="router.push({ name: 'items' })"
              >
                <HubIcon name="items" class="hub-tile__icon" />
                <span class="hub-tile__label">持ち物</span>
              </button>
              <button
                type="button"
                class="hub-tile"
                aria-label="使い魔の様子を見る"
                @click="router.push({ name: 'familiar' })"
              >
                <HubIcon name="familiar" class="hub-tile__icon" />
                <span class="hub-tile__label">使い魔</span>
              </button>
            </div>
          </nav>
        </div>

        <p v-if="error" class="scene-error">{{ error }}</p>
      </ScenePanel>
    </div>

    <HubPartyFooter v-if="party.length" :units="party" />
  </div>
</template>

<style scoped>
/* 文字とボタンが背景の絵に埋もれないよう、上と下をうっすら暗くする */
.hub-shade {
  position: absolute;
  inset: 0;
  z-index: 1;
  pointer-events: none;
  background:
    linear-gradient(to bottom, rgba(10, 5, 24, 0.62) 0%, rgba(10, 5, 24, 0) 34%),
    linear-gradient(to top, rgba(10, 5, 24, 0.82) 0%, rgba(10, 5, 24, 0) 52%);
}

.hub-layout {
  position: absolute;
  inset: 0;
  z-index: 3;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 0.5rem 0.75rem 0.9rem;
  /* 空いている所のタップは背景に素通しにし、ボタンだけを押せるようにする */
  pointer-events: none;
}

/* ───────── 上: タイトルに戻るボタン ───────── */
.hub-top {
  display: flex;
  justify-content: flex-start;
}

.hub-title-back {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  min-height: 36px;
  padding: 0.25rem 0.8rem 0.25rem 0.5rem;
  border: 1px solid rgba(255, 255, 255, 0.5);
  border-radius: 999px;
  background: rgba(12, 8, 24, 0.7);
  color: #f8f4ff;
  font-size: 0.75rem;
  font-weight: 700;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  cursor: pointer;
  touch-action: manipulation;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
  pointer-events: auto;
}
.hub-title-back__icon {
  font-size: 1.1rem;
}
.hub-title-back:hover {
  background: rgba(40, 24, 72, 0.88);
}


/* ───────── 下: メニュー ───────── */
.hub-menu {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.hub-dive {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  width: 100%;
  min-height: 62px;
  padding: 0.6rem 1.25rem;
  border: 2px solid #ffe08a;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(120, 70, 210, 0.95), rgba(64, 28, 130, 0.95));
  color: #fff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  cursor: pointer;
  touch-action: manipulation;
  box-shadow:
    0 0 18px rgba(150, 100, 255, 0.55),
    0 6px 16px rgba(0, 0, 0, 0.5),
    inset 0 1px 0 rgba(255, 255, 255, 0.35);
  pointer-events: auto;
}
.hub-dive::after {
  content: '';
  position: absolute;
  top: 0;
  bottom: 0;
  left: -40%;
  width: 28%;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.28), transparent);
  transform: skewX(-18deg);
  animation: hubDiveShine 4.2s ease-in-out infinite;
  pointer-events: none;
}
.hub-dive:active {
  transform: translateY(1px);
}
.hub-dive__icon {
  font-size: 1.9rem;
  color: #ffe08a;
}
.hub-dive__label {
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-shadow: 0 2px 6px rgba(0, 0, 0, 0.55);
}

.hub-tiles {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
}
.hub-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;
  min-height: 74px;
  padding: 0.5rem 0.25rem;
  border: 1px solid rgba(200, 176, 255, 0.5);
  border-radius: 14px;
  background: linear-gradient(to bottom, rgba(36, 20, 68, 0.88), rgba(16, 8, 34, 0.9));
  color: #f8f4ff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  cursor: pointer;
  touch-action: manipulation;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.12);
  pointer-events: auto;
}
.hub-tile:hover {
  background: linear-gradient(to bottom, rgba(54, 32, 98, 0.92), rgba(26, 14, 52, 0.92));
}
.hub-tile:active {
  transform: translateY(1px);
}
.hub-tile__icon {
  font-size: 1.85rem;
  color: #ffd98a;
}
.hub-tile__label {
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.05em;
}

@keyframes hubDiveShine {
  0%,
  60% {
    left: -40%;
  }
  100% {
    left: 130%;
  }
}
@media (prefers-reduced-motion: reduce) {
  .hub-dive::after {
    animation: none;
    display: none;
  }
}

/* 画面が低い端末では、メニューを少し小さくする */
@media (max-height: 700px) {
  .hub-dive {
    min-height: 54px;
  }
  .hub-tile {
    min-height: 64px;
  }
}
</style>
