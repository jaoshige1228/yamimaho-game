<script setup>
import EnemyRow from "./EnemyRow.vue";
import PartyRow from "./PartyRow.vue";

defineProps({
  units: { type: Array, required: true },
  displayHp: { type: Object, default: () => ({}) },
  buffIcons: { type: Object, default: () => ({}) },
  partyPhase: {
    type: String,
    default: 'ready',
    validator: (v) => ['hidden', 'entering', 'ready'].includes(v),
  },
  currentActor: { type: String, default: null },
  targetMode: { type: String, default: null },
  spellEffect: { type: String, default: null },
  shakeTarget: { type: String, default: null },
  supportEffectTarget: { type: Object, default: null },
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
        :display-hp="displayHp"
        :buff-icons="buffIcons"
        :target-mode="targetMode"
        :shake-target="shakeTarget"
        :support-effect-target="supportEffectTarget"
        :active-lunge="activeEnemyLunge"
        @select="emit('select-target', $event)"
        @show-stats="emit('show-stats', $event)"
      />
    </section>
    <section class="field-bottom">
      <div
        v-if="partyPhase !== 'hidden'"
        class="party-entrance-wrap"
        :class="{ entering: partyPhase === 'entering' }"
      >
        <PartyRow
          :units="units.filter((u) => u.side === 'ally')"
          :display-hp="displayHp"
          :buff-icons="buffIcons"
          :current-actor="currentActor"
          :target-mode="targetMode"
          :spell-effect="spellEffect"
        :shake-target="shakeTarget"
        :support-effect-target="supportEffectTarget"
        :can-open-commands="canOpenCommands"
        @select="emit('select-target', $event)"
          @open-commands="emit('open-commands', $event)"
          @show-stats="emit('show-stats', $event)"
        />
      </div>
    </section>
  </div>
</template>

<style scoped>
.battle-field {
  position: relative;
  width: 100%;
  height: 100%;
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
  padding: 0.35rem 0.25rem 0.75rem;
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
  align-items: stretch;
  justify-content: center;
  padding: 0.25rem 0.2rem calc(0.45rem + env(safe-area-inset-bottom, 0px));
  border-top: 2px solid rgba(157, 124, 255, 0.25);
  overflow: hidden;
}
.party-entrance-wrap {
  width: 100%;
  height: 100%;
  min-height: 0;
  display: flex;
  align-items: stretch;
}
.party-entrance-wrap :deep(.party-row) {
  width: 100%;
  height: 100%;
  max-height: 100%;
  min-height: 0;
  gap: 0.25rem;
}
.party-entrance-wrap :deep(.party-slot) {
  height: 100%;
  min-height: 0;
  padding-bottom: 0.2rem;
}
.party-entrance-wrap :deep(.unit-sprite-frame.party) {
  flex: 1 1 auto;
  height: auto;
  min-height: 0;
  max-height: 72%;
}
.party-entrance-wrap :deep(.unit-meta) {
  flex: 0 0 auto;
}
.party-entrance-wrap :deep(.name) {
  line-height: 1.35;
  padding-bottom: 1px;
}
.party-entrance-wrap.entering {
  animation: partyDeploy 0.45s ease-out both;
}
</style>
