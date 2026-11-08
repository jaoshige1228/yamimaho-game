<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { usePlayerStore } from '../stores/player';
import '../styles/hub-screen.css';
import { publicAssetUrl } from '../utils/publicAssetUrl.js';

const familiarsSrc = publicAssetUrl('/assets/familiar/familiars.webp');

const router = useRouter();
const player = usePlayerStore();
const loading = ref(true);
const feeding = ref(false);
const error = ref('');
const notice = ref('');
const leveledUp = ref(false);
const status = ref(null);

const level = computed(() => status.value?.level ?? 0);
const maxLevel = computed(() => status.value?.max_level ?? 9);
const feedCost = computed(() => status.value?.feed_cost ?? 100);
const maxReached = computed(() => status.value?.max_reached === true);
const canFeed = computed(() => !!status.value && !maxReached.value && player.gold >= feedCost.value);

function applyStatus(data) {
  status.value = data;
  if (data?.gold != null) player.setGold(data.gold);
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    applyStatus(await api('/familiar', { method: 'GET' }));
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function feed() {
  feeding.value = true;
  error.value = '';
  notice.value = '';
  leveledUp.value = false;
  try {
    const data = await api('/familiar/feed', { method: 'POST' });
    applyStatus(data);
    notice.value = data.message ?? '';
    leveledUp.value = data.leveled_up === true;
  } catch (e) {
    error.value = e.message;
  } finally {
    feeding.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="hub-screen familiar-screen">
    <header class="sub-screen-header">
      <button type="button" class="back-btn" @click="router.push({ name: 'hub' })">＜ 戻る</button>
      <span class="gold-label">{{ player.gold }} G</span>
    </header>

    <div class="familiar-body">
      <p v-if="loading" class="hub-screen-muted">読み込み中…</p>
      <p v-else-if="error && !status" class="hub-screen-error">{{ error }}</p>

      <template v-else-if="status">
        <img class="familiars-image" :src="familiarsSrc" alt="使い魔たち" decoding="async" />

        <p class="familiar-intro">
          <span>使い魔たちに魔石をあげることでレベルアップしていきます。</span>
          <span>レベルアップすると、戦闘時の支援行動が強力になります</span>
        </p>

        <section class="status-panel">
          <p class="level-line">
            <span class="level-label">使い魔 Lv</span>
            <strong class="level-now">{{ level }}</strong>
            <span class="level-max">/ {{ maxLevel }}</span>
          </p>
          <p class="stones-line">魔石を与えた数：<strong>{{ status.stones_total }}</strong> 個</p>
        </section>

        <section class="feed-panel">
          <p v-if="notice" class="feed-notice" :class="{ levelup: leveledUp }">{{ notice }}</p>
          <p v-if="error" class="hub-screen-error">{{ error }}</p>
          <button
            type="button"
            class="feed-btn"
            :disabled="feeding || !canFeed"
            @click="feed"
          >
            魔石を与える（{{ feedCost }}G）
          </button>
          <p v-if="maxReached" class="feed-hint">使い魔はこれ以上強くならない。</p>
          <p v-else-if="player.gold < feedCost" class="feed-hint">ゴールドが足りない。</p>
        </section>

        <ol class="ability-list" aria-label="使い魔の能力">
          <li
            v-for="ability in status.abilities"
            :key="ability.level"
            class="ability-row"
            :class="{ unlocked: ability.unlocked, locked: !ability.unlocked }"
          >
            <span class="ability-level">Lv{{ ability.level }}</span>
            <span class="ability-text">{{ ability.unlocked ? ability.description : '？？？' }}</span>
          </li>
        </ol>
      </template>
    </div>
  </div>
</template>

<style scoped>
.sub-screen-header {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.5rem 1rem 0.25rem;
}
.back-btn {
  border: none;
  background: transparent;
  color: #f8f4ff;
  font-size: 1.05rem;
  font-weight: 800;
  cursor: pointer;
  min-height: 44px;
  padding: 0 0.25rem;
}
.gold-label {
  font-size: 0.85rem;
  color: #ffd98a;
  font-weight: 700;
}

.familiar-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  padding: 0 1rem 0.75rem;
}
.familiars-image {
  flex-shrink: 0;
  width: auto;
  max-width: min(68%, 270px);
  max-height: 27dvh;
  height: auto;
  object-fit: contain;
  filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.45));
}

.familiar-intro {
  margin: 0;
  width: 100%;
  text-align: center;
  font-size: 0.74rem;
  line-height: 1.7;
  color: #cbbcf0;
}
.familiar-intro span {
  display: block;
  text-wrap: balance;
}

.status-panel {
  width: 100%;
  text-align: center;
}
.level-line {
  margin: 0;
  display: flex;
  justify-content: center;
  align-items: baseline;
  gap: 0.35rem;
}
.level-label {
  font-size: 0.85rem;
  color: #c8b0ff;
  font-weight: 700;
}
.level-now {
  font-size: 1.9rem;
  line-height: 1;
  color: #fff;
}
.level-max {
  font-size: 0.9rem;
  color: #a898c8;
}
.stones-line {
  margin: 0.25rem 0 0;
  font-size: 0.8rem;
  color: #d4c4ff;
}
.stones-line strong {
  color: #ffd98a;
  font-size: 0.95rem;
}

.ability-list {
  width: 100%;
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.28rem;
}
.ability-row {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  padding: 0.4rem 0.65rem;
  border-radius: 8px;
  font-size: 0.78rem;
  line-height: 1.35;
}
.ability-row.unlocked {
  background: rgba(120, 84, 200, 0.28);
  border: 1px solid rgba(200, 176, 255, 0.5);
  color: #fff;
}
.ability-row.locked {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(157, 124, 255, 0.14);
  color: #6f6290;
}
.ability-level {
  flex-shrink: 0;
  min-width: 2.4rem;
  font-weight: 800;
  font-size: 0.74rem;
}
.ability-row.unlocked .ability-level {
  color: #ffd98a;
}

.feed-panel {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  padding-top: 0.25rem;
}
.feed-notice {
  margin: 0;
  font-size: 0.8rem;
  color: #ffd98a;
  text-align: center;
}
.feed-notice.levelup {
  font-size: 0.95rem;
  font-weight: 800;
  color: #fff0b0;
}
.feed-btn {
  width: min(100%, 280px);
  min-height: 46px;
  border: 2px solid rgba(255, 255, 255, 0.85);
  border-radius: 999px;
  background: rgba(80, 48, 140, 0.85);
  color: #fff;
  font-size: 0.95rem;
  font-weight: 800;
  cursor: pointer;
  touch-action: manipulation;
}
.feed-btn:disabled {
  opacity: 0.45;
  cursor: default;
}
.feed-hint {
  margin: 0;
  font-size: 0.72rem;
  color: #a898c8;
}
</style>
