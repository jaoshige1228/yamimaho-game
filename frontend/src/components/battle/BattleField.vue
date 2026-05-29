<script setup>
import EnemyRow from "./EnemyRow.vue";
import PartyRow from "./PartyRow.vue";

defineProps({
  units: { type: Array, required: true },
  currentActor: { type: String, default: null },
  targetMode: { type: String, default: null },
  shakeTarget: { type: String, default: null },
  activeEnemyLunge: { type: String, default: null },
  canOpenCommands: { type: Boolean, default: false },
});

const emit = defineEmits(["select-target", "open-commands", "show-stats"]);
</script>

<template>
  <div class="battle-field">
    <section class="field-top">
      <div class="sky-glow" />
      <EnemyRow
        :units="units.filter((u) => u.side === 'enemy')"
        :target-mode="targetMode"
        :shake-target="shakeTarget"
        :active-lunge="activeEnemyLunge"
        @select="emit('select-target', $event)"
        @show-stats="emit('show-stats', $event)"
      />
    </section>
    <section class="field-bottom">
      <PartyRow
        :units="units.filter((u) => u.side === 'ally')"
        :current-actor="currentActor"
        :target-mode="targetMode"
        :shake-target="shakeTarget"
        :can-open-commands="canOpenCommands"
        @select="emit('select-target', $event)"
        @open-commands="emit('open-commands', $event)"
        @show-stats="emit('show-stats', $event)"
      />
    </section>
  </div>
</template>

<style scoped>
.battle-field {
  position: relative;
  width: 100%;
  height: min(800px, 100%);
  max-height: 800px;
  overflow: hidden;
  flex-shrink: 0;
}
.field-top {
  position: relative;
  height: 38%;
  min-height: 0;
  background: var(--battle-bg-top);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: 0.35rem 0.25rem 0.5rem;
  overflow: hidden;
}
.sky-glow {
  position: absolute;
  inset: 0;
  background: radial-gradient(
    ellipse 80% 50% at 50% 20%,
    rgba(157, 124, 255, 0.25),
    transparent
  );
  pointer-events: none;
}
.field-bottom {
  height: 62%;
  min-height: 0;
  background: var(--battle-bg-bottom);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.3rem 0.2rem;
  border-top: 2px solid rgba(157, 124, 255, 0.25);
}
</style>
