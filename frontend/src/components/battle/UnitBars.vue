<script setup>
import { computed } from 'vue';

const props = defineProps({
  hp: { type: Number, required: true },
  maxHp: { type: Number, required: true },
  mp: { type: Number, default: null },
  maxMp: { type: Number, default: null },
});

const hpPercent = computed(() =>
  props.maxHp ? Math.max(0, (props.hp / props.maxHp) * 100) : 0,
);

const mpPercent = computed(() =>
  props.maxMp ? Math.max(0, ((props.mp ?? 0) / props.maxMp) * 100) : 0,
);

const showMp = computed(() => props.maxMp != null && props.maxMp > 0);
</script>

<template>
  <div class="unit-bars">
    <div class="stat hp">
      <div class="stat-head">
        <span class="label">HP</span>
        <span class="value">{{ hp }}/{{ maxHp }}</span>
      </div>
      <div class="bar-track"><span class="fill" :style="{ width: hpPercent + '%' }" /></div>
    </div>
    <div v-if="showMp" class="stat mp">
      <div class="stat-head">
        <span class="label">MP</span>
        <span class="value">{{ mp }}/{{ maxMp }}</span>
      </div>
      <div class="bar-track"><span class="fill" :style="{ width: mpPercent + '%' }" /></div>
    </div>
  </div>
</template>

<style scoped>
.unit-bars {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  width: 100%;
}
.stat {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.stat-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.25rem;
}
.label {
  font-size: 0.58rem;
  font-weight: 700;
  line-height: 1.2;
}
.stat.hp .label {
  color: #ff8fa3;
}
.stat.mp .label {
  color: #7ec8ff;
}
.value {
  font-size: 0.58rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  color: rgba(245, 240, 255, 0.92);
}
.bar-track {
  width: 100%;
  height: 10px;
  background: rgba(255, 255, 255, 0.12);
  border-radius: 5px;
  overflow: hidden;
}
.fill {
  display: block;
  height: 100%;
  border-radius: 5px;
  transition: width 0.35s ease;
}
.stat.hp .fill {
  background: var(--hp);
}
.stat.mp .fill {
  background: var(--mp);
}
</style>
