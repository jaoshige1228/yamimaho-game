<script setup>
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

const catSrc = publicAssetUrl('/assets/characters/advice_cat.webp');

// 2 文あるセリフは、文の区切りで改行する（\n。white-space: pre-line）
const ADVICE_LINES = [
  'ダンジョン内での能力判定には、現在HPの倍率が掛かるんだ。\nつまりHPが減ってるほど成功率が下がるぞ。',
  '撤退すれば所持金が少し減るけど、HPとMPは全回復する。\nしんどくなったら撤退もアリだな。',
  'みんなのデフォルトの回避率は5%だ。\n敵の通常攻撃や魔法が外れたんだとしたら、クリティカルが出たってことだな。',
  '敵から逃げるのは100%成功するぞ。\nただ、ボス戦では逃げられないから要注意だ。',
  '……zzz',
  '層を突破すると武具屋のラインナップが更新されるぞ。',
  '防御や攻撃のバフ/デバフは最大3段階まで重ねがけ可能だ。\n回避バフだけは重ねがけできないぞ。',
  'こぶしとキックは筋力、魔法は魔力によって威力が決まるぞ。',
  '素早さは行動順にだけ影響する。\n回避とかには特に関係がないな。',
  '体力、知力、精神力は戦闘では特に影響しないぞ。',
  'ブラウザバックは結構バグるから、あんまりしないでくれ。',
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
  /* 狭い画面でも読みやすいように、画面幅に合わせて少し小さくする。フォントは指定しない（ページの既定のフォント） */
  font-size: clamp(0.72rem, 3.25vw, 0.8rem);
  line-height: 1.5;
  white-space: pre-line;
  text-wrap: pretty;
}
</style>
