<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { ENDING_CREDITS } from '../../utils/endingCredits.js';

/**
 * エンドロール。暗い画面の下から文字が上へ流れていき、すべて流れきったら 'finished' を出す（呼んだ側が待機画面へ戻る）。
 * 流れる速さは、画面の高さや文字の量が変わっても同じ（距離 ÷ 速さで、かかる時間を決める）。
 */
const emit = defineEmits(['finished']);

/** 流れる速さ（画面上の px / 秒）。 */
const SPEED_PX_PER_SEC = 70;
/** 流れ始める前に、暗い画面だけを見せる時間（ms） */
const START_DELAY_MS = 700;
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
/*
 * 見た目は、デザインの画像（2026-11-14）に合わせている。長さは、文字の大きさ（em）に対する比で決めている:
 * 見出しは太字、そのほかは細い字。見出しとその下の行の間は 1.5 行ぶん。見出しと見出しの間（前のまとまりの最後の行から次の見出しまで）は 9.9 行ぶんで、大きく空く。
 * キャストは、役の名前（右揃え）と演じた人（左揃え）の境目が画面の真ん中で、見出しとの間は 1.9 行ぶん。
 */
.credits {
  /* 画面いっぱい（上の「戦闘」のバーや、ほかの画面の飾りも隠す。窓が広いときも、背景は全面に広がる） */
  position: fixed;
  inset: 0;
  z-index: 1000;
  overflow: hidden;
  background: #030620;
  color: #c8cced;
  font-family: 'Hiragino Sans', system-ui, sans-serif;
  font-weight: 400;
  /* 役の名前（いちばん長い「サフィリア=フォン=ラグナブルー」）が、左の列（文字の幅 430px の半分）に 1 行で収まる大きさ。
     狭い画面では画面の幅に合わせて小さくし、広い窓でも 13.3px より大きくしない（文字の幅が 430px までなので） */
  font-size: clamp(9px, 3.1vw, 13.3px);
  line-height: 1.31;
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
/* 見出しの前は、大きく空ける（前の最後の行の中心から、見出しの中心まで 9.9 行ぶん） */
.credits-section + .credits-section {
  margin-top: 8.57em;
}
.credits-heading {
  margin: 0 0 0.18em;
  font-size: 1em;
  font-weight: 700;
  text-align: center;
}
.credits-line {
  margin: 0;
  text-align: center;
}
/* 見出しとキャストの間は 1.9 行ぶん（上下の余白は、大きいほうだけが効く。見出しの下の 0.18em ではなく、この 0.59em） */
.credits-cast {
  margin-top: 0.59em;
}
/* まとまりが複数あるときは、間を 1 行空ける */
.credits-group + .credits-group {
  margin-top: 1.31em;
}
/* 役の名前は右揃え、演じた人は左揃え。2 つの列の境目は、画面の真ん中 */
.credits-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
}
.credits-name {
  padding-right: 0.19em;
  text-align: right;
}
.credits-actor {
  padding-left: 1.33em;
  text-align: left;
}
</style>
