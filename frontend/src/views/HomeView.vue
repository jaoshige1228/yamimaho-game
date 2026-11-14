<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useAppAudio } from '../composables/useAppAudio';
import { useBattleStore } from '../stores/battle';

/** タイトル画面で流す BGM（useBattleAudio.js の BGM_TRACKS のキー。useRouteBgm.js の 'home' ルートと同じ） */
const TITLE_BGM_TRACK = 'last_boss';

const router = useRouter();
const battle = useBattleStore();
const { settings, unlock, unlockAndPlay, isBgmPaused } = useAppAudio();
const resetting = ref(false);
const leaving = ref(false);
const message = ref('');
const error = ref('');

/**
 * 画面のどこかをタップしたとき、BGM が止まっていたら（ブラウザは、タップされるまで音を鳴らさせない）、タイトルの BGM を鳴らす。
 * 「ゲームを始める」を押したときは、待機画面の BGM に切り替わるので、鳴らさない。
 */
function onUserGesture() {
  if (leaving.value) return;
  if (settings.value.bgmEnabled && isBgmPaused()) {
    unlockAndPlay(TITLE_BGM_TRACK);
  } else {
    unlock();
  }
}

function startGame() {
  leaving.value = true;
  unlock();
  router.push('/hub');
}

async function resetData() {
  if (!window.confirm('このブラウザのゲームデータを消去します。よろしいですか？')) {
    return;
  }

  resetting.value = true;
  error.value = '';
  message.value = '';
  try {
    await api('/reset', { method: 'POST' });
    battle.battleId = null;
    battle.state = null;
    battle.pendingEvents = [];
    message.value = 'データを消去しました';
  } catch (e) {
    error.value = e.message;
  } finally {
    resetting.value = false;
  }
}
</script>

<template>
  <div class="home" @click="onUserGesture">
    <h1 class="title">Yamimaho another</h1>
    <div class="menu">
      <button type="button" class="btn-start" :disabled="resetting" @click="startGame">ゲームを始める</button>
      <button type="button" class="btn-reset" :disabled="resetting" @click="resetData">
        {{ resetting ? '消去中…' : 'データをリセットする' }}
      </button>
    </div>
    <p v-if="message" class="message">{{ message }}</p>
    <p v-if="error" class="error">{{ error }}</p>
  </div>
</template>

<style scoped>
.home {
  width: min(430px, 100vw);
  height: 100%;
  box-sizing: border-box;
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3.5rem;
  text-align: center;
  color: #f8f4ff;
}
.title {
  margin: 0;
  font-size: 2.1rem;
  font-weight: 700;
  letter-spacing: 0.06em;
}
.menu {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.9rem;
}
.btn-start,
.btn-reset {
  display: block;
  width: 100%;
  max-width: 280px;
  min-height: 48px;
  border-radius: 999px;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
}
.btn-start {
  padding: 0.85rem 2rem;
  border: none;
  background: linear-gradient(135deg, #7c5cff, #c45cff);
  color: #fff;
  box-shadow: 0 8px 24px rgba(124, 92, 255, 0.45);
}
.btn-reset {
  padding: 0.7rem 1.5rem;
  border: 1px solid rgba(255, 120, 120, 0.45);
  background: rgba(40, 12, 20, 0.6);
  color: #ffb0b0;
  font-size: 0.9rem;
  font-weight: 600;
}
.btn-start:disabled,
.btn-reset:disabled {
  opacity: 0.55;
}
.message {
  margin: -2rem 0 0;
  color: #b8f0d0;
  font-size: 0.85rem;
}
.error {
  margin: -2rem 0 0;
  color: #ff8a8a;
  font-size: 0.85rem;
}
</style>
