<script setup>
import { computed, ref } from 'vue';
import PartyRow from '../battle/PartyRow.vue';
import UnitStatsModal from '../battle/UnitStatsModal.vue';

const props = defineProps({
  units: { type: Array, required: true },
});

const statsUnitId = ref(null);

const statsUnit = computed(() => {
  if (!statsUnitId.value) {
    return null;
  }
  const unit = props.units.find((u) => u.id === statsUnitId.value);
  if (!unit) {
    return null;
  }
  return { ...unit, side: 'ally' };
});

function onShowStats(unit) {
  statsUnitId.value = unit.id;
}

function closeStatsModal() {
  statsUnitId.value = null;
}
</script>

<template>
  <footer class="hub-party-footer">
    <div class="party-dock">
      <PartyRow
        class="hub-party-row"
        :units="units"
        :can-open-commands="false"
        @show-stats="onShowStats"
      />
    </div>
    <UnitStatsModal
      :visible="!!statsUnit"
      :unit="statsUnit"
      @close="closeStatsModal"
    />
  </footer>
</template>

<style scoped>
.hub-party-footer {
  flex: 0 0 auto;
  width: 100%;
  max-height: var(--hub-party-band-height);
  z-index: 5;
}

.party-dock {
  box-sizing: border-box;
  max-height: var(--hub-party-band-height);
  background: var(--battle-bg-bottom);
  border-top: 2px solid rgba(157, 124, 255, 0.25);
  padding: 0.2rem 0.15rem 0.3rem;
  display: flex;
  align-items: stretch;
  justify-content: center;
  min-height: 0;
  overflow: hidden;
}

.party-dock :deep(.hub-party-row.party-row) {
  width: 100%;
  height: calc(var(--hub-party-band-height) - 0.55rem);
  max-height: none;
  gap: 0.2rem;
  padding: 0 0.1rem;
}

.party-dock :deep(.party-slot) {
  min-height: 0;
  height: 100%;
  padding: 0.12rem 0.1rem 0.15rem;
  gap: 0.12rem;
}

.party-dock :deep(.unit-sprite-frame.party) {
  flex: 1 1 auto;
  height: auto;
  min-height: 0;
  max-height: 58%;
  border-radius: 4px 4px 2px 2px;
}

.party-dock :deep(.unit-sprite-frame.party .unit-sprite-bust) {
  height: 260%;
  transform: scale(0.88);
}

.party-dock :deep(.unit-meta) {
  flex: 0 0 auto;
}

.party-dock :deep(.unit-bars) {
  gap: 0.12rem;
}

.party-dock :deep(.stat-head .label),
.party-dock :deep(.stat-head .value) {
  font-size: 0.48rem;
}

.party-dock :deep(.bar-track) {
  height: 6px;
  border-radius: 3px;
}

.party-dock :deep(.name) {
  margin-top: 0.06rem;
  font-size: 0.52rem;
}
</style>
