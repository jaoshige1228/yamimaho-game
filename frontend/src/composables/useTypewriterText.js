import { onUnmounted, ref, toValue, watch } from 'vue';

function prefersReducedMotion() {
  return typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function isCoarsePointer() {
  return typeof window !== 'undefined'
    && window.matchMedia('(pointer: coarse)').matches;
}

/** @returns {{ tickMs: number, charsPerTick: number }} */
function resolveTiming(options) {
  if (prefersReducedMotion()) {
    return { tickMs: 0, charsPerTick: Number.MAX_SAFE_INTEGER };
  }
  if (options.tickMs != null && options.charsPerTick != null) {
    return { tickMs: options.tickMs, charsPerTick: options.charsPerTick };
  }
  if (isCoarsePointer()) {
    return { tickMs: 32, charsPerTick: 3 };
  }
  return { tickMs: 28, charsPerTick: 2 };
}

/**
 * 低頻度タイマーで文字送り（モバイル Safari 向けに rAF 連打を避ける）。
 * @param {import('vue').MaybeRefOrGetter<string>} source
 * @param {{ tickMs?: number, charsPerTick?: number }} [options]
 */
export function useTypewriterText(source, options = {}) {
  const displayed = ref('');
  const isComplete = ref(true);
  /** @type {ReturnType<typeof setTimeout> | null} */
  let timerId = null;
  let fullText = '';

  function cancel() {
    if (timerId !== null) {
      clearTimeout(timerId);
      timerId = null;
    }
  }

  function finish() {
    cancel();
    displayed.value = fullText;
    isComplete.value = true;
  }

  function start(text) {
    cancel();
    fullText = text ?? '';
    if (fullText.length === 0) {
      displayed.value = '';
      isComplete.value = true;
      return;
    }

    const { tickMs, charsPerTick } = resolveTiming(options);
    if (tickMs === 0 || charsPerTick >= fullText.length) {
      finish();
      return;
    }

    displayed.value = '';
    isComplete.value = false;
    let index = 0;

    const tick = () => {
      index = Math.min(fullText.length, index + charsPerTick);
      displayed.value = fullText.slice(0, index);

      if (index < fullText.length) {
        timerId = setTimeout(tick, tickMs);
      } else {
        timerId = null;
        isComplete.value = true;
      }
    };

    timerId = setTimeout(tick, tickMs);
  }

  function skip() {
    if (!isComplete.value) {
      finish();
    }
  }

  watch(
    () => toValue(source),
    (next) => start(next ?? ''),
    { immediate: true },
  );

  onUnmounted(cancel);

  return { displayed, isComplete, skip };
}
