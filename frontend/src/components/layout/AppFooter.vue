<script setup>
import { useRoute, useRouter } from 'vue-router';

const router = useRouter();
const route = useRoute();

function goHub() {
  if (route.name !== 'hub') {
    router.push({ name: 'hub' });
  }
}

function goStatus() {
  if (route.name !== 'status') {
    router.push({ name: 'status' });
  }
}
</script>

<template>
  <footer class="app-footer">
    <button type="button" class="footer-btn" aria-label="待機画面へ" @click="goHub">
      <span class="icon" aria-hidden="true">⌂</span>
      <span class="label">ホーム</span>
    </button>

    <button type="button" class="footer-btn placeholder" aria-label="未定" disabled>
      <span class="icon" aria-hidden="true">◇</span>
      <span class="label">—</span>
    </button>

    <button type="button" class="footer-btn" aria-label="ステータス" @click="goStatus">
      <span class="icon" aria-hidden="true">☰</span>
      <span class="label">ステータス</span>
    </button>
  </footer>
</template>

<style scoped>
.app-footer {
  flex-shrink: 0;
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  align-items: stretch;
  width: min(430px, 100vw);
  margin: 0 auto;
  min-height: 56px;
  padding-bottom: env(safe-area-inset-bottom);
  background: rgba(8, 4, 18, 0.98);
  border-top: 1px solid rgba(157, 124, 255, 0.3);
  z-index: 10;
}
.footer-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.15rem;
  border: none;
  background: transparent;
  color: #c8b0ff;
  cursor: pointer;
  min-height: 56px;
  padding: 0.35rem 0.25rem;
}
.footer-btn:hover:not(:disabled) {
  background: rgba(157, 124, 255, 0.12);
  color: #f8f4ff;
}
.footer-btn.placeholder {
  opacity: 0.45;
  cursor: default;
}
.icon {
  font-size: 1.25rem;
  line-height: 1;
}
.label {
  font-size: 0.62rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}
</style>
