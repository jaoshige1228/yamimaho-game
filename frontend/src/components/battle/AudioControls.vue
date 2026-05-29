<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  bgmEnabled: { type: Boolean, default: false },
  bgmVolume: { type: Number, default: 0.25 },
});

const emit = defineEmits(['update:bgm-enabled', 'update:bgm-volume']);

const expanded = ref(false);
const toggleRef = ref(null);
const panelRef = ref(null);
const panelStyle = ref({});

function toggleBgm() {
  emit('update:bgm-enabled', !props.bgmEnabled);
}

function onVolumeInput(event) {
  emit('update:bgm-volume', Number(event.target.value));
}

function updatePanelPosition() {
  const toggle = toggleRef.value;
  const panel = panelRef.value;
  if (!toggle || !panel) return;

  const rect = toggle.getBoundingClientRect();
  const panelRect = panel.getBoundingClientRect();
  const gap = 6;
  const margin = 8;

  let top = rect.bottom + gap;
  if (top + panelRect.height + margin > window.innerHeight) {
    top = rect.top - panelRect.height - gap;
  }

  let left = rect.right - panelRect.width;
  left = Math.max(margin, Math.min(left, window.innerWidth - panelRect.width - margin));

  top = Math.max(margin, Math.min(top, window.innerHeight - panelRect.height - margin));

  panelStyle.value = {
    top: `${top}px`,
    left: `${left}px`,
  };
}

async function togglePanel() {
  expanded.value = !expanded.value;
  if (!expanded.value) return;

  await nextTick();
  updatePanelPosition();
}

function onViewportChange() {
  if (expanded.value) {
    updatePanelPosition();
  }
}

watch(expanded, (open) => {
  if (open) {
    window.addEventListener('resize', onViewportChange);
    window.addEventListener('scroll', onViewportChange, true);
  } else {
    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', onViewportChange);
  window.removeEventListener('scroll', onViewportChange, true);
});
</script>

<template>
  <div class="audio-controls">
    <button
      ref="toggleRef"
      type="button"
      class="toggle-btn"
      :aria-expanded="expanded"
      aria-label="BGMの設定"
      @click.stop="togglePanel"
    >
      {{ bgmEnabled ? '♪' : '♪×' }}
    </button>
    <Teleport to="body">
      <div v-if="expanded" class="audio-panel-backdrop" @click="expanded = false" />
      <div v-if="expanded" ref="panelRef" class="audio-panel" :style="panelStyle">
        <label class="row">
          <span>BGM</span>
          <button type="button" class="switch" :class="{ off: !bgmEnabled }" @click.stop="toggleBgm">
            {{ bgmEnabled ? 'オン' : 'オフ' }}
          </button>
        </label>
        <label class="row volume">
          <span>音量</span>
          <input
            type="range"
            min="0"
            max="1"
            step="0.05"
            :value="bgmVolume"
            :disabled="!bgmEnabled"
            @input="onVolumeInput"
            @click.stop
          />
        </label>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.audio-controls {
  position: relative;
  z-index: 12;
}
.toggle-btn {
  min-width: 44px;
  min-height: 44px;
  padding: 0.35rem 0.5rem;
  border: 1px solid rgba(157, 124, 255, 0.5);
  border-radius: 8px;
  background: rgba(40, 28, 70, 0.85);
  color: var(--text);
  font-size: 0.85rem;
  cursor: pointer;
  touch-action: manipulation;
}
</style>

<style>
.audio-panel-backdrop {
  position: fixed;
  inset: 0;
  z-index: 90;
  background: transparent;
}
.audio-panel {
  position: fixed;
  z-index: 91;
  width: min(180px, calc(100vw - 16px));
  padding: 0.65rem 0.75rem;
  background: rgba(12, 8, 24, 0.96);
  border: 1px solid rgba(157, 124, 255, 0.4);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
}
.audio-panel .row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.78rem;
  color: #f8f4ff;
}
.audio-panel .row.volume {
  margin-top: 0.55rem;
}
.audio-panel .row span {
  flex-shrink: 0;
}
.audio-panel .switch {
  min-height: 32px;
  padding: 0.2rem 0.65rem;
  border: 1px solid rgba(157, 124, 255, 0.5);
  border-radius: 6px;
  background: rgba(124, 92, 255, 0.35);
  color: #f8f4ff;
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
}
.audio-panel .switch.off {
  background: rgba(60, 60, 60, 0.6);
  border-color: #666;
  color: #aaa;
}
.audio-panel input[type='range'] {
  flex: 1;
  min-width: 0;
  accent-color: #7c5cff;
}
.audio-panel input[type='range']:disabled {
  opacity: 0.4;
}
</style>
