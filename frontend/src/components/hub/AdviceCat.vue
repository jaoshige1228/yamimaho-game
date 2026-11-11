<script setup>
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

const catSrc = publicAssetUrl('/assets/characters/advice_cat.webp');

const ADVICE_LINES = [
  'HPやMPがきつくなってきたら、潔く撤退するのも一つの手だ。',
  '能力判定には現在HPの倍率が掛かるんだ。\nつまりHPが低いほど成功率が下がるぞ。',
];

// 画面に入ったとき（このコンポーネントが作られたとき）に 1 回だけ抽選する。
// タップでは変わらない。画面に入り直すたびに、ランダムで選び直される。
const line = ADVICE_LINES[Math.floor(Math.random() * ADVICE_LINES.length)];
</script>

<template>
  <div class="advice-cat">
    <div class="advice-cat__icon" role="img" aria-label="アドバイス猫">
      <img :src="catSrc" alt="" draggable="false" />
    </div>
    <p class="advice-cat__speech">{{ line }}</p>
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
  display: flex;
  align-items: center;
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
</style>
