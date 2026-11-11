<script setup>
/**
 * ホーム画面のメニュー用の線画アイコン（色は currentColor）。絵文字は端末ごとに見た目が違うので使わない。
 */
defineProps({
  name: {
    type: String,
    required: true,
    validator: (v) => ['dungeon', 'shop', 'items', 'familiar', 'back'].includes(v),
  },
});
</script>

<template>
  <svg
    class="hub-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <!-- ダンジョン: 石のアーチと下り階段 -->
    <template v-if="name === 'dungeon'">
      <path d="M4 21V11a8 8 0 0 1 16 0v10" />
      <path d="M2.5 21h19" />
      <path d="M9 21v-3h3v-3h3v-3" />
    </template>
    <!-- 武具屋: 盾 -->
    <template v-else-if="name === 'shop'">
      <path d="M12 3 5 6v5.5c0 4.6 3 8.2 7 9.5 4-1.3 7-4.9 7-9.5V6z" />
      <path d="M12 8v8" />
      <path d="M8.5 11.5h7" />
    </template>
    <!-- 持ち物: 袋 -->
    <template v-else-if="name === 'items'">
      <path d="M9 7V6a3 3 0 0 1 6 0v1" />
      <path d="M6 7h12l1 13H5z" />
      <path d="M9.5 12.5a2.5 2.5 0 0 0 5 0" />
    </template>
    <!-- 使い魔: 肉球 -->
    <template v-else-if="name === 'familiar'">
      <circle cx="6.5" cy="11" r="1.8" />
      <circle cx="10" cy="6.8" r="1.8" />
      <circle cx="14" cy="6.8" r="1.8" />
      <circle cx="17.5" cy="11" r="1.8" />
      <path d="M12 12.5c-3.2 0-5.5 3-5.5 5.2 0 1.8 1.4 2.3 2.6 2.3 1 0 1.8-.5 2.9-.5s1.9.5 2.9.5c1.200 0 2.6-.5 2.6-2.3 0-2.200-2.3-5.2-5.5-5.2z" />
    </template>
    <!-- 戻る -->
    <template v-else-if="name === 'back'">
      <path d="M15 5l-7 7 7 7" />
    </template>
  </svg>
</template>

<style scoped>
.hub-icon {
  display: block;
  width: 1em;
  height: 1em;
  flex-shrink: 0;
}
</style>
