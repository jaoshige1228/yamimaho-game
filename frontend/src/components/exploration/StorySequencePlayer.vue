<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useAppAudio } from '../../composables/useAppAudio.js';
import StoryCharacterSprite from './StoryCharacterSprite.vue';
import StoryTextWindow from './StoryTextWindow.vue';
import '../../styles/story-window.css';

const { unlock, playSe } = useAppAudio();

const props = defineProps({
  lines: { type: Array, required: true },
  party: { type: Array, default: () => [] },
});

const emit = defineEmits(['complete', 'sync-party', 'screen-fade']);

const index = ref(0);
const inputLocked = ref(false);
const textWindowRef = ref(null);
/** @type {ReturnType<typeof setTimeout> | null} */
let fadeTimer = null;

const currentLine = computed(() => props.lines[index.value] ?? null);

const currentMode = computed(() => {
  const type = currentLine.value?.type;
  if (type === 'dialogue') return 'dialogue';
  return 'narration';
});

const characterInfo = computed(() => {
  const line = currentLine.value;
  if (line?.speaker_name && line?.speaker_sprite) {
    return {
      name: line.speaker_name,
      sprite: line.speaker_sprite,
    };
  }

  const code = line?.character;
  if (!code) {
    return { name: 'サフィリア', sprite: 'PC1' };
  }
  const member = props.party.find(
    (u) => u.slot_id === code || u.id === code,
  );
  return {
    name: member?.name ?? 'サフィリア',
    sprite: member?.sprite ?? 'PC1',
  };
});

function clearFadeTimer() {
  if (fadeTimer !== null) {
    clearTimeout(fadeTimer);
    fadeTimer = null;
  }
}

function advanceLine() {
  if (index.value >= props.lines.length - 1) {
    emit('complete');
    return;
  }
  index.value += 1;
}

function runScreenFade(ms) {
  clearFadeTimer();
  emit('screen-fade', ms);
  inputLocked.value = true;
  fadeTimer = setTimeout(() => {
    inputLocked.value = false;
    fadeTimer = null;
    advanceLine();
  }, ms);
}

/**
 * 成功・失敗が判明する行（ダイスロールのログ `dice` が付いた行）が表示されたときに、成功・失敗の効果音を鳴らす。
 * 鉄砲水のように 1 つの場面で何人も続けて判定するときも、それぞれの結果の行で 1 回ずつ鳴る。
 */
function playResultSe(lineIndex) {
  const result = props.lines[lineIndex]?.dice?.result;
  if (result === 'success') {
    playSe('success');
  } else if (result === 'fail') {
    playSe('miss');
  }
}

/**
 * 行に `sfx`（SE の名前。dungeon_event_nodes.csv の sfx 列）が付いていれば、その行を表示したときに鳴らす
 * （「HP回復薬とMP回復薬を受け取った！」の成功音など）。暗転（screen_fade）は音ではないので除く。
 * 知らない名前（効果音のないもの）は、playSe が何もしない。
 */
function playLineSe(lineIndex) {
  const sfx = props.lines[lineIndex]?.sfx;
  if (typeof sfx === 'string' && sfx !== '' && sfx !== 'screen_fade') {
    playSe(sfx);
  }
}

watch(
  () => props.lines,
  () => {
    clearFadeTimer();
    inputLocked.value = false;
    const wasFirstLine = index.value === 0;
    index.value = 0;
    // index が変わらない（0 のまま）ときは、下の index の監視が動かないので、ここで鳴らす
    if (wasFirstLine) {
      playResultSe(0);
      playLineSe(0);
    }
  },
);

function emitSyncPartyIfNeeded(lineIndex) {
  const line = props.lines[lineIndex];
  if (line?.sync_party) {
    emit('sync-party', Array.isArray(line.party) ? line.party : null);
  }
}

watch(index, (lineIndex) => {
  emitSyncPartyIfNeeded(lineIndex);
  playResultSe(lineIndex);
  playLineSe(lineIndex);
  const line = props.lines[lineIndex];
  const fadeMs = Number(line?.screen_fade_ms);
  if (fadeMs > 0) {
    runScreenFade(fadeMs);
  }
}, { immediate: true });

onUnmounted(() => {
  clearFadeTimer();
});

function onTap() {
  if (inputLocked.value) return;
  unlock();
  const windowApi = textWindowRef.value;
  if (windowApi && !windowApi.isComplete) {
    windowApi.skip();
    return;
  }
  advanceLine();
}
</script>

<template>
  <button
    type="button"
    class="story-sequence"
    aria-label="次へ"
    @click="onTap"
  >
    <div class="story-sequence__viewport">
      <div class="story-sequence__scene">
        <div v-if="currentMode === 'dialogue' && currentLine" class="story-dialogue-stage">
          <StoryCharacterSprite
            variant="dialogue"
            :sprite="characterInfo.sprite"
            :alt="characterInfo.name"
          />
          <StoryTextWindow
            ref="textWindowRef"
            mode="dialogue"
            :text="currentLine.text"
            :character-name="characterInfo.name"
            :dice="currentLine.dice ?? null"
          />
        </div>
        <StoryTextWindow
          v-else-if="currentLine"
          ref="textWindowRef"
          mode="narration"
          :text="currentLine.text"
          :dice="currentLine.dice ?? null"
        />
      </div>
    </div>
  </button>
</template>
