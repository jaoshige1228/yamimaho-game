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
  flex-shrink: 0;
  width: 100%;
  z-index: 5;
}
.party-dock {
  background: var(--battle-bg-bottom);
  border-top: 2px solid rgba(157, 124, 255, 0.25);
  padding: 0.35rem 0.2rem calc(0.5rem + env(safe-area-inset-bottom));
  display: flex;
  align-items: flex-end;
  justify-content: center;
  min-height: 0;
}
.party-dock :deep(.party-row) {
  width: 100%;
  max-height: min(480px, 42dvh);
}
</style>
