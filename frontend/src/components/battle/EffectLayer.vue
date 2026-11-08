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
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.72);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: #fff;
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  white-space: nowrap;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
  animation: bannerPop 1.1s ease forwards;
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
.float-damage {
  position: absolute;
  transform: translate(-50%, -50%);
  font-size: 1.5rem;
  font-weight: 800;
  color: #fff;
  text-shadow: 0 2px 8px #000, 0 0 12px #ff4466;
  animation: floatDamage 0.9s ease forwards;
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
