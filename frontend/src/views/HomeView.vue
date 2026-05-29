<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useAppAudio } from '../composables/useAppAudio';
import { useBattleStore } from '../stores/battle';

const router = useRouter();
const battle = useBattleStore();
const { unlock } = useAppAudio();
const loading = ref(false);
const loadingKappa2 = ref(false);
const resetting = ref(false);
const error = ref('');

async function startDemo() {
  unlock();
  loading.value = true;
  error.value = '';
  try {
    await battle.startDemo();
    router.push({ name: 'battle', params: { id: battle.battleId } });
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function startDemoKappa2() {
  unlock();
  loadingKappa2.value = true;
  error.value = '';
  try {
    await battle.startDemo('kappa2');
    router.push({ name: 'battle', params: { id: battle.battleId } });
  } catch (e) {
    error.value = e.message;
  } finally {
    loadingKappa2.value = false;
  }
}

async function resetProgress() {
  if (!window.confirm('戦闘の記録とキャラの成長をすべて消します。よろしいですか？')) {
    return;
  }

  resetting.value = true;
  error.value = '';
  try {
    await api('/reset', { method: 'POST' });
    battle.battleId = null;
    battle.state = null;
    battle.pendingEvents = [];
  } catch (e) {
    error.value = e.message;
  } finally {
    resetting.value = false;
  }
}
</script>

<template>
  <div class="home">
    <h1>やみまほ</h1>
    <p class="subtitle">魔法学園 RPG — 戦闘デモ</p>
    <button type="button" class="btn-primary" :disabled="loading || loadingKappa2 || resetting" @click="startDemo">
      {{ loading ? '少々お待ちを…' : '戦闘を始める' }}
    </button>
    <button
      type="button"
      class="btn-hub"
      :disabled="loading || loadingKappa2 || resetting"
      @click="router.push('/hub')"
    >
      待機画面へ
    </button>
    <button
      type="button"
      class="btn-secondary"
      :disabled="loading || loadingKappa2 || resetting"
      @click="startDemoKappa2"
    >
      {{ loadingKappa2 ? '少々お待ちを…' : 'カッパLV2戦' }}
    </button>
    <button
      type="button"
      class="btn-reset"
      :disabled="loading || loadingKappa2 || resetting"
      @click="resetProgress"
    >
      {{ resetting ? 'リセット中…' : 'リセット' }}
    </button>
    <p class="reset-hint">成長・戦闘記録をすべて消して最初からやり直します</p>
    <p v-if="error" class="error">{{ error }}</p>
  </div>
</template>

<style scoped>
.home {
  width: min(430px, 100vw);
  height: 100%;
  box-sizing: border-box;
  padding: 2rem 1.5rem 1rem;
  overflow-y: auto;
  text-align: center;
  color: #f8f4ff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
}
h1 {
  font-size: 2rem;
  margin: 0 0 0.5rem;
  letter-spacing: 0.2em;
}
.subtitle {
  color: #b8a8d8;
  margin-bottom: 2.5rem;
}
.btn-primary {
  display: block;
  width: 100%;
  max-width: 280px;
  margin: 0 auto;
  min-height: 48px;
  padding: 0.85rem 2rem;
  border: none;
  border-radius: 999px;
  background: linear-gradient(135deg, #7c5cff, #c45cff);
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(124, 92, 255, 0.45);
}
.btn-primary:disabled {
  opacity: 0.6;
}
.btn-secondary {
  display: block;
  width: 100%;
  max-width: 280px;
  margin: 0.75rem auto 0;
  min-height: 48px;
  padding: 0.85rem 2rem;
  border: 1px solid rgba(157, 124, 255, 0.55);
  border-radius: 999px;
  background: rgba(40, 28, 70, 0.75);
  color: #e8dcff;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
}
.btn-secondary:disabled {
  opacity: 0.6;
}
.btn-hub {
  display: block;
  width: 100%;
  max-width: 280px;
  margin: 0.75rem auto 0;
  min-height: 48px;
  padding: 0.85rem 2rem;
  border: 1px solid rgba(157, 124, 255, 0.55);
  border-radius: 999px;
  background: rgba(28, 48, 40, 0.75);
  color: #b8f0d0;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
}
.btn-hub:disabled {
  opacity: 0.6;
}
.btn-reset {
  display: block;
  width: 100%;
  max-width: 280px;
  margin: 1rem auto 0;
  min-height: 44px;
  padding: 0.65rem 1.5rem;
  border: 1px solid rgba(255, 120, 120, 0.45);
  border-radius: 999px;
  background: rgba(40, 12, 20, 0.6);
  color: #ffb0b0;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
}
.btn-reset:disabled {
  opacity: 0.55;
}
.reset-hint {
  margin: 0.65rem auto 0;
  max-width: 280px;
  font-size: 0.72rem;
  line-height: 1.45;
  color: #8a7aa8;
}
.error {
  margin-top: 1rem;
  color: #ff8a8a;
}
</style>
