<script setup>
import { publicAssetUrl } from '../../utils/publicAssetUrl.js';

defineProps({
  flashClass: { type: String, default: '' },
  floatingDamages: { type: Array, default: () => [] },
  floatingSupports: { type: Array, default: () => [] },
  actionBanners: { type: Array, default: () => [] },
  /** 使い魔の支援のバナー { id, text, portrait }（顔つき）。なければ null */
  familiarBanner: { type: Object, default: null },
});

function portraitSrc(portrait) {
  return publicAssetUrl(`/assets/characters/${portrait}.webp`);
}
</script>

<template>
  <div class="effect-layer">
    <div v-if="flashClass" class="flash" :class="flashClass" />
    <div
      v-for="b in actionBanners"
      :key="b.id"
      class="action-banner"
      :class="{ 'is-long': b.long }"
    >
      {{ b.text }}
    </div>
    <div v-if="familiarBanner" :key="familiarBanner.id" class="familiar-banner">
      <img
        v-if="familiarBanner.portrait"
        class="familiar-portrait"
        :src="portraitSrc(familiarBanner.portrait)"
        alt=""
        decoding="async"
      />
      <div class="familiar-banner-text">{{ familiarBanner.text }}</div>
    </div>
    <div
      v-for="d in floatingDamages"
      :key="d.id"
      class="float-damage"
      :class="{ 'is-ally': d.ally, 'is-miss': d.miss }"
      :style="{ left: d.x + '%', top: d.y + '%' }"
    >
      {{ d.value }}
    </div>
    <div
      v-for="s in floatingSupports"
      :key="s.id"
      class="float-support"
      :class="`kind-${s.kind}`"
      :style="{ left: s.x + '%', top: s.y + '%' }"
    >
      {{ s.value }}
    </div>
  </div>
</template>

<style scoped>
.effect-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 15;
}
.flash {
  position: absolute;
  inset: 0;
  animation-duration: 0.45s;
  animation-fill-mode: forwards;
}
.flash.flash-water {
  animation-name: flashWater;
}
.flash.flash-fire {
  animation-name: flashFire;
}
.flash.flash-wood {
  animation-name: flashWood;
}
.flash.flash-earth {
  animation-name: flashEarth;
}
.flash.flash-dark {
  animation-name: flashDark;
}
.flash.flash-hit {
  animation-name: flashHit;
}
.flash.flash-heal {
  animation-name: flashHeal;
}
.flash.flash-buff {
  animation-name: flashBuff;
}
.flash.flash-debuff {
  animation-name: flashDebuff;
}
.flash.flash-shield {
  animation-name: flashShield;
}
.flash.flash-revive {
  animation-name: flashRevive;
}
.action-banner {
  position: absolute;
  left: 50%;
  top: 38%;
  transform: translateX(-50%);
  z-index: 16;
  padding: 0.5rem 1.25rem;
  border-radius: 1.4rem;
  background: rgba(0, 0, 0, 0.72);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: #fff;
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  /* 短い告知は 1 行のまま。長い告知（悪魔級殲滅魔法の詠唱など）は、画面の幅に収まるよう折り返す */
  width: max-content;
  max-width: calc(100% - 1.5rem);
  box-sizing: border-box;
  white-space: normal;
  text-align: center;
  line-height: 1.35;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
  animation: bannerPop 1.1s ease forwards;
}
/* 敵の通常攻撃以外の技・魔法の告知は、1.1 秒 + 0.7 秒 = 1.8 秒残す（出る・消える速さは同じで、表示したままの時間だけ延ばす） */
.action-banner.is-long {
  animation: bannerPopLong 1.8s ease forwards;
}
/* 使い魔の支援: 戦場の上のほうに、左端に使い魔の顔が少しはみ出す幅広のバナー */
.familiar-banner {
  position: absolute;
  left: 4%;
  right: 4%;
  top: 15%;
  z-index: 17;
  display: flex;
  align-items: center;
  animation: familiarBannerPop 1.2s ease forwards;
}
.familiar-portrait {
  flex-shrink: 0;
  z-index: 1;
  width: clamp(64px, 19vw, 92px);
  height: clamp(64px, 19vw, 92px);
  object-fit: cover;
  object-position: center top;
  border-radius: 10px;
  margin-right: -0.9rem;
  filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.65));
}
.familiar-banner-text {
  flex: 1;
  min-width: 0;
  padding: 0.7rem 0.9rem 0.7rem 1.4rem;
  border-radius: 6px;
  background: rgba(18, 16, 30, 0.9);
  color: #fff;
  font-size: clamp(0.92rem, 4.5vw, 1.35rem);
  font-weight: 800;
  line-height: 1.3;
  letter-spacing: 0.03em;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
}
@keyframes familiarBannerPop {
  0% {
    opacity: 0;
    transform: translateX(-18px);
  }
  14% {
    opacity: 1;
    transform: translateX(0);
  }
  82% {
    opacity: 1;
    transform: translateX(0);
  }
  100% {
    opacity: 0;
    transform: translateX(6px);
  }
}
/* ダメージの数字。出てすぐ薄くなり始めると見づらいので、しばらく濃いまま保ってから消える */
.float-damage {
  position: absolute;
  transform: translate(-50%, -50%);
  font-size: 1.5rem;
  font-weight: 800;
  color: #fff;
  text-shadow: 0 2px 8px #000, 0 0 12px #ff4466;
  animation: floatDamageHold 1.25s ease-out forwards;
}
/* 味方が受けたダメージ: 大きく・太く・赤い字に濃い縁取り。明るい立ち絵や暗い枠の上でも読める */
.float-damage.is-ally {
  font-size: 2.1rem;
  font-weight: 900;
  letter-spacing: 0.02em;
  color: #ff4d5e;
  text-shadow:
    -2px -2px 0 #1a0408, 2px -2px 0 #1a0408, -2px 2px 0 #1a0408, 2px 2px 0 #1a0408,
    0 -2px 0 #1a0408, 0 2px 0 #1a0408, -2px 0 0 #1a0408, 2px 0 0 #1a0408,
    0 3px 10px rgba(0, 0, 0, 0.9),
    0 0 18px rgba(255, 60, 80, 0.85);
}
/* miss（攻撃が外れた）: ダメージの数字と同じ動きで、白い字で出す。赤い光は付けず、暗い縁取りで明るい背景でも読めるようにする */
.float-damage.is-miss {
  color: #fff;
  letter-spacing: 0.04em;
  text-shadow:
    -1px -1px 0 #12121c, 1px -1px 0 #12121c, -1px 1px 0 #12121c, 1px 1px 0 #12121c,
    0 2px 8px #000, 0 0 10px rgba(255, 255, 255, 0.45);
}
.float-damage.is-ally.is-miss {
  color: #fff;
  text-shadow:
    -2px -2px 0 #12121c, 2px -2px 0 #12121c, -2px 2px 0 #12121c, 2px 2px 0 #12121c,
    0 -2px 0 #12121c, 0 2px 0 #12121c, -2px 0 0 #12121c, 2px 0 0 #12121c,
    0 3px 10px rgba(0, 0, 0, 0.9),
    0 0 14px rgba(255, 255, 255, 0.45);
}
.float-support {
  position: absolute;
  transform: translate(-50%, -50%);
  font-size: 1.35rem;
  font-weight: 800;
  animation: floatDamage 0.9s ease forwards;
}
.float-support.kind-heal {
  color: #b8ffd8;
  text-shadow: 0 2px 8px #000, 0 0 14px #3ecf7a;
}
.float-support.kind-buff {
  color: #e8dcff;
  text-shadow: 0 2px 8px #000, 0 0 14px #9d7cff;
}
.float-support.kind-debuff {
  color: #ffd4a8;
  text-shadow: 0 2px 8px #000, 0 0 14px #c87830;
}
.float-support.kind-shield {
  color: #d4e8ff;
  text-shadow: 0 2px 8px #000, 0 0 14px #5ca8ff;
}
.float-support.kind-revive {
  color: #fff8c8;
  text-shadow: 0 2px 8px #000, 0 0 14px #e8c840;
}
</style>
