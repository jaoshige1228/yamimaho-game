<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AudioControls from '../battle/AudioControls.vue';
import { useAppAudio } from '../../composables/useAppAudio';

const route = useRoute();
const {
  settings: audioSettings,
  setBgmEnabled,
  setBgmVolume,
  setSeVolume,
  playSe,
  unlock,
} = useAppAudio();

const title = computed(() => route.meta?.title ?? 'やみまほ');

function onAudioInteraction() {
  unlock();
}

// 効果音の音量を決めるときに、実際の音で確かめられるようにする
function onSeVolumeCommit() {
  unlock();
  playSe('damage');
}
</script>

<template>
  <header class="app-header" @click="onAudioInteraction">
    <div class="app-header-inner">
      <h1 class="app-header-title">{{ title }}</h1>

      <div class="app-header-end">
        <AudioControls
          :bgm-enabled="audioSettings.bgmEnabled"
          :bgm-volume="audioSettings.bgmVolume"
          :se-volume="audioSettings.seVolume"
          @update:bgm-enabled="setBgmEnabled"
          @update:bgm-volume="setBgmVolume"
          @update:se-volume="setSeVolume"
          @commit:se-volume="onSeVolumeCommit"
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
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 0.25rem;
  width: min(430px, 100vw);
  margin: 0 auto;
  min-height: 52px;
  padding: 0.35rem 0.5rem;
  padding-top: calc(0.35rem + env(safe-area-inset-top));
  box-sizing: border-box;
}
.app-header-end {
  display: flex;
  align-items: center;
  justify-content: flex-end;
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
