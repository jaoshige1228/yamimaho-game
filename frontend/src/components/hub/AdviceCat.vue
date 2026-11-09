<script setup>
import { ref } from 'vue';
import { useTypewriterText } from '../../composables/useTypewriterText.js';
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

const catSrc = publicAssetUrl('/assets/characters/advice_cat.webp');

const ADVICE_LINES = [
  'HPやMPがきつくなってきたら、潔く撤退するのも一つの手だ。',
  '能力判定には現在HPの倍率が掛かるんだ。\nつまりHPが低いほど成功率が下がるぞ。',
];

const line = ref('');
const { displayed, isComplete, skip } = useTypewriterText(line);

/** 表示中のセリフとは必ず別のセリフを選ぶ（セリフが1つしかないときは同じものを返す）。 */
function pickLine() {
  const others = ADVICE_LINES.filter((l) => l !== line.value);
  const pool = others.length > 0 ? others : ADVICE_LINES;
  return pool[Math.floor(Math.random() * pool.length)];
}

function tellAdvice() {
  // 文字送り中のタップは、ダンジョンのテキストと同じく全文表示にとどめる
  if (line.value && !isComplete.value) {
    skip();
    return;
  }
  line.value = pickLine();
}
</script>

<template>
  <div class="advice-cat">
    <button type="button" class="advice-cat__icon" aria-label="アドバイス猫" @click="tellAdvice">
      <img :src="catSrc" alt="" draggable="false" />
    </button>
    <p v-if="line" class="advice-cat__speech">
      <!-- 全文ぶんの高さを先に確保し、文字送り中に表示位置がずれないようにする -->
      <span class="advice-cat__speech-ghost" aria-hidden="true">{{ line }}</span>
      <span class="advice-cat__speech-text">{{ displayed }}</span>
    </p>
  </div>
</template>

<style scoped>
.advice-cat {
  display: flex;
  align-items: stretch;
  gap: 0.55rem;
  width: 100%;
}
.advice-cat__icon {
  flex: 0 0 auto;
  width: 72px;
  height: 72px;
  padding: 0;
  overflow: hidden;
  border: 2px solid #fff;
  border-radius: 50%;
  background: #000;
  cursor: pointer;
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
}
.advice-cat__icon img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  user-select: none;
  -webkit-user-drag: none;
}
.advice-cat__speech {
  flex: 1;
  min-width: 0;
  margin: 0;
  padding: 0.5rem 0.75rem;
  display: grid;
  align-content: center;
  border: 1px solid rgba(200, 176, 255, 0.25);
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.5);
  color: #f8f4ff;
  /* 狭い画面でも、1 文が 1 行に収まりやすいように、画面幅に合わせて少し小さくする */
  font-size: clamp(0.72rem, 3.25vw, 0.8rem);
  line-height: 1.5;
  font-family: 'Hiragino Mincho ProN', 'Yu Mincho', serif;
  white-space: pre-line;
  text-wrap: pretty;
}
.advice-cat__speech-ghost,
.advice-cat__speech-text {
  grid-area: 1 / 1;
}
.advice-cat__speech-ghost {
  visibility: hidden;
}
</style>
