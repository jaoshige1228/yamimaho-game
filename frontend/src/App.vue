<script setup>
import { computed, provide } from 'vue';
import { useRoute } from 'vue-router';
import AppFooter from './components/layout/AppFooter.vue';
import AppHeader from './components/layout/AppHeader.vue';
import GameHudBar from './components/layout/GameHudBar.vue';
import { APP_AUDIO_KEY, createAppAudio } from './composables/useAppAudio';
import { useRouteBgm } from './composables/useRouteBgm';

const route = useRoute();
useRouteBgm();
// 戦闘中とタイトル画面には、下のメニュー（ホーム・ステータス）を出さない
const showFooter = computed(() => route.name !== 'battle' && route.name !== 'home');
const showGameHud = computed(() => route.name === 'hub' || route.name === 'dungeon');

provide(APP_AUDIO_KEY, createAppAudio());
</script>

<template>
  <div class="app-shell">
    <AppHeader />
    <GameHudBar v-if="showGameHud" />
    <main class="app-content">
      <RouterView />
    </main>
    <AppFooter v-if="showFooter" />
  </div>
</template>

<style>
/* 文字の書体は、アプリ全体でゴシック体（サンセリフ）にする。書体を指定していない文字は、ブラウザの標準の書体（明朝体）で出てしまうので、
   いちばん外側（html）で指定して、中の全部に引き継ぐ。ボタン・入力欄はブラウザの標準の書体で出るので、引き継ぐ指定も入れる */
:root {
  --font-sans: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Yu Gothic', 'Meiryo', system-ui, sans-serif;
}
html,
body,
#app,
button,
input,
textarea,
select {
  font-family: var(--font-sans);
}
html,
body,
#app {
  margin: 0;
  height: 100%;
  overflow: hidden;
  background: #0f0a1a;
}
.app-shell {
  height: 100dvh;
  max-height: 100dvh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  background: #0f0a1a;
}
.app-content {
  flex: 1;
  min-height: 0;
  width: 100%;
  display: flex;
  justify-content: center;
  overflow: hidden;
}

/* 探索画面のパーティ帯・ストーリー配置の共通寸法 */
:root {
  --hub-party-band-height: clamp(140px, 33dvh, 240px);
  --app-chrome-top: calc(3.25rem + env(safe-area-inset-top, 0px) + 2.35rem);
  --app-chrome-bottom: calc(3.75rem + env(safe-area-inset-bottom, 0px));
}
</style>
