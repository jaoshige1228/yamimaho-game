<script setup>
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

const catSrc = publicAssetUrl('/assets/characters/advice_cat.webp');

// セリフの一覧。1 つのセリフ = 文の配列、1 つの文 = 文節の配列（つなげると元の文になる）。
// 改行は、文（句点）の終わりで必ず入れる。1 文が吹き出しの幅に収まらないときは、文節の切れ目（読点のあとなど）でだけ折り返す
// （単語の途中では切らない。折り返した行の長さは、なるべくそろえる）。
const ADVICE_LINES = [
  [
    ['ダンジョン内での', '能力判定には、', '現在HPの', '倍率が', '掛かるんだ。'],
    ['つまり', 'HPが', '減ってるほど', '成功率が', '下がるぞ。'],
  ],
  [
    ['撤退すれば', '所持金が', '少し減るけど、', 'HPとMPは', '全回復する。'],
    ['しんどくなったら', '撤退も', 'アリだな。'],
  ],
  [
    ['みんなの', 'デフォルトの', '回避率は', '5%だ。'],
    ['敵の通常攻撃や', '魔法が', '外れたんだとしたら、', 'クリティカルが', '出たってことだな。'],
  ],
  [
    ['敵から', '逃げるのは', '100%', '成功するぞ。'],
    ['ただ、', 'ボス戦では', '逃げられないから', '要注意だ。'],
  ],
  [['……zzz']],
  [['層を突破すると', '武具屋の', 'ラインナップが', '更新されるぞ。']],
  [
    ['防御や攻撃の', 'バフ/デバフは', '最大3段階まで', '重ねがけ可能だ。'],
    ['回避バフだけは', '重ねがけ', 'できないぞ。'],
  ],
  [['こぶしとキックは', '筋力、', '魔法は', '魔力によって', '威力が', '決まるぞ。']],
  [
    ['素早さは', '行動順にだけ', '影響する。'],
    ['回避とかには', '特に', '関係がないな。'],
  ],
  [['体力、', '知力、', '精神力は', '戦闘では', '特に', '影響しないぞ。']],
  [['ブラウザバックは', '結構バグるから、', 'あんまり', 'しないでくれ。']],
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
    <p class="advice-cat__speech">
      <span v-for="(sentence, i) in line" :key="i" class="advice-cat__sentence">
        <template v-for="(phrase, j) in sentence" :key="j">{{ phrase }}<wbr v-if="j < sentence.length - 1" /></template>
      </span>
    </p>
  </div>
</template>

<style scoped>
.advice-cat {
  display: flex;
  align-items: stretch;
  gap: 0.45rem;
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
  padding: 0.5rem 0.55rem;
  /* 文（.advice-cat__sentence）を縦に並べる。文のあいだで必ず改行する */
  display: flex;
  flex-direction: column;
  justify-content: center;
  border: 1px solid rgba(200, 176, 255, 0.25);
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.5);
  color: #f8f4ff;
  /* 狭い画面でも読みやすいように、画面幅に合わせて少し小さくする。フォントは指定しない（ページの既定のフォント）。
     1 文がなるべく 1 行（長い文は 2 行）に収まるよう、余白と文字の大きさは、これ以上大きくしない */
  font-size: clamp(0.72rem, 3.2vw, 0.78rem);
  line-height: 1.5;
}
.advice-cat__sentence {
  display: block;
  /* 文節の切れ目（<wbr>）でだけ折り返す。言葉の途中で切れないようにして、折り返した行の長さをそろえる */
  word-break: keep-all;
  overflow-wrap: anywhere;
  text-wrap: balance;
}
</style>
