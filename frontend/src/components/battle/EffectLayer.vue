<script setup>
defineProps({
  flashClass: { type: String, default: '' },
  floatingDamages: { type: Array, default: () => [] },
  actionBanners: { type: Array, default: () => [] },
});
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
    <div
      v-for="d in floatingDamages"
      :key="d.id"
      class="float-damage"
      :style="{ left: d.x + '%', top: d.y + '%' }"
    >
      {{ d.value }}
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
.flash.flash-hit {
  animation-name: flashHit;
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
.float-damage {
  position: absolute;
  transform: translate(-50%, -50%);
  font-size: 1.5rem;
  font-weight: 800;
  color: #fff;
  text-shadow: 0 2px 8px #000, 0 0 12px #ff4466;
  animation: floatDamage 0.9s ease forwards;
}
</style>
