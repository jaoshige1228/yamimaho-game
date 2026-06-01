<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AudioControls from '../battle/AudioControls.vue';
import { useAppAudio } from '../../composables/useAppAudio';

const route = useRoute();
const router = useRouter();
const {
  settings: audioSettings,
  setBgmEnabled,
  setBgmVolume,
  unlock,
} = useAppAudio();

const title = computed(() => route.meta?.title ?? 'やみまほ');

const showBack = computed(() => {
  if (route.name === 'battle' && route.query.boss === '1') {
    return false;
  }
  if (route.meta?.showBack === false) {
    return false;
  }
  if (route.meta?.showBack === true) {
    return true;
  }
  return route.name !== 'home';
});

function goBack() {
  if (window.history.length > 1) {
    router.back();
    return;
  }
  router.push('/');
}

function onAudioInteraction() {
  unlock();
}
</script>

<template>
  <header class="app-header" @click="onAudioInteraction">
    <div class="app-header-inner">
      <div class="app-header-start">
        <button
          v-if="showBack"
          type="button"
          class="app-header-back"
          aria-label="戻る"
          @click="goBack"
        >
          ←
        </button>
      </div>

      <h1 class="app-header-title">{{ title }}</h1>

      <div class="app-header-end">
        <AudioControls
          :bgm-enabled="audioSettings.bgmEnabled"
          :bgm-volume="audioSettings.bgmVolume"
          @update:bgm-enabled="setBgmEnabled"
          @update:bgm-volume="setBgmVolume"
        />
      </div>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  flex-shrink: 0;
  width: 100%;
  background: rgba(12, 8, 24, 0.98);
  border-bottom: 1px solid rgba(157, 124, 255, 0.25);
  z-index: 20;
}
.app-header-inner {
  display: grid;
  grid-template-columns: 52px 1fr 52px;
  align-items: center;
  gap: 0.25rem;
  width: min(430px, 100vw);
  margin: 0 auto;
  min-height: 52px;
  padding: 0.35rem 0.5rem;
  padding-top: calc(0.35rem + env(safe-area-inset-top));
  box-sizing: border-box;
}
.app-header-start,
.app-header-end {
  display: flex;
  align-items: center;
  justify-content: center;
}
.app-header-end {
  justify-content: flex-end;
}
.app-header-back {
  border: none;
  background: transparent;
  color: #f8f4ff;
  font-size: 1.25rem;
  cursor: pointer;
  min-width: 44px;
  min-height: 44px;
}
.app-header-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  text-align: center;
  color: #f8f4ff;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
