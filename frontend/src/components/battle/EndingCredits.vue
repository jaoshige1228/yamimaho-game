<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { ENDING_CREDITS } from '../../utils/endingCredits.js';

/**
 * エンドロール。暗い画面の下から文字が上へ流れていき、すべて流れきったら 'finished' を出す（呼んだ側が待機画面へ戻る）。
 * 流れる速さは、画面の高さや文字の量が変わっても同じ（距離 ÷ 速さで、かかる時間を決める）。
 */
const emit = defineEmits(['finished']);

/** 流れる速さ（画面上の px / 秒）。 */
const SPEED_PX_PER_SEC = 100;
/** 流れ始める前に、暗い画面だけを見せる時間（ms） */
const START_DELAY_MS = 800;
/** 最後の文字が上に消えてから、待機画面へ戻るまでの間（ms） */
const END_HOLD_MS = 500;

const root = ref(null);
const scroller = ref(null);

let animation = null;
let endTimer = null;

onMounted(async () => {
  await nextTick();
  // フォントが読み込まれて文字の大きさが変わる前に高さを測らないように
  try {
    await document.fonts?.ready;
  } catch {
    // フォントの状態が取れなくても、そのまま流す
  }
  start();
});

function start() {
  const viewport = root.value?.clientHeight ?? 0;
  const content = scroller.value?.offsetHeight ?? 0;
  if (!viewport || !content || typeof scroller.value?.animate !== 'function') {
    // アニメーションが使えないときは、文字を見せる間だけ待って戻る
    endTimer = setTimeout(() => emit('finished'), 8000);
    return;
  }

  // 最初は画面の下の外から、最後は文字がすべて画面の上の外へ出るまで
  const distance = viewport + content;
  const duration = (distance / SPEED_PX_PER_SEC) * 1000;
  animation = scroller.value.animate(
    [
      { transform: `translateY(${viewport}px)` },
      { transform: `translateY(${-content}px)` },
    ],
    { duration, delay: START_DELAY_MS, easing: 'linear', fill: 'both' },
  );
  animation.onfinish = () => {
    endTimer = setTimeout(() => emit('finished'), END_HOLD_MS);
  };
}

onBeforeUnmount(() => {
  animation?.cancel();
  if (endTimer) clearTimeout(endTimer);
});
</script>

<template>
  <div ref="root" class="credits" role="presentation" @click.stop>
    <div ref="scroller" class="credits-scroll">
      <section v-for="(section, i) in ENDING_CREDITS" :key="i" class="credits-section">
        <h2 class="credits-heading">{{ section.heading }}</h2>
        <p v-for="(line, j) in section.lines ?? []" :key="`l${j}`" class="credits-line">{{ line }}</p>
        <div v-if="section.groups" class="credits-cast">
          <div v-for="(group, g) in section.groups" :key="g" class="credits-group">
            <div v-for="([name, actor], r) in group" :key="r" class="credits-row">
              <span class="credits-name">{{ name }}</span>
              <span class="credits-actor">{{ actor }}</span>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.credits {
  /* 画面いっぱい（上の「戦闘」のバーや、ほかの画面の飾りも隠す。窓が広いときも、背景は全面に広がる） */
  position: fixed;
  inset: 0;
  z-index: 1000;
  overflow: hidden;
  background: #020621;
  color: #c8cdeb;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  font-weight: 700;
  /* 役の名前（いちばん長い「サフィリア=フォン=ラグナブルー」）が、左の列（文字の幅 430px の 56%）に 1 行で収まる大きさ。
     狭い画面では画面の幅に合わせて小さくし、広い窓でも 14.2px より大きくしない（文字の幅が 430px までなので） */
  font-size: clamp(11px, 3.3vw, 14.2px);
  line-height: 1.5;
  user-select: none;
}
.credits-scroll {
  position: absolute;
  top: 0;
  /* 広い窓でも、文字の幅は戦闘画面と同じ（最大 430px）で、真ん中に並ぶ */
  left: 0;
  right: 0;
  width: min(430px, 100%);
  margin: 0 auto;
  /* 最初は画面の下の外にいる（アニメーションが始まる前に、一瞬でも見えないように） */
  transform: translateY(100vh);
  will-change: transform;
}
/* 見出しの前は 3 行ぶんくらい空ける */
.credits-section + .credits-section {
  margin-top: 4em;
}
.credits-heading {
  margin: 0;
  font-size: 1em;
  font-weight: inherit;
  text-align: center;
}
.credits-line {
  margin: 0;
  text-align: center;
}
/* 見出しとキャストの間は 1 行空ける */
.credits-cast {
  margin-top: 1.5em;
}
/* まとまりの間も 1 行空ける */
.credits-group + .credits-group {
  margin-top: 1.5em;
}
/* 役の名前は右揃え、演じた人は左揃え。2 つの列の境目は、画面の真ん中よりやや右 */
.credits-row {
  display: grid;
  grid-template-columns: 56% 1fr;
}
.credits-name {
  padding-right: 0.75em;
  text-align: right;
}
.credits-actor {
  padding-left: 0.75em;
  text-align: left;
}
</style>
