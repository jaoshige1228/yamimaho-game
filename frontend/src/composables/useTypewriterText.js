import { onUnmounted, ref, toValue, watch } from 'vue';

/** 1文字あたりの表示間隔（ms）。体感で読める RPG 寄りの速度 */
const DEFAULT_MS_PER_CHAR = 5;

/**
 * requestAnimationFrame ベースの文字送り（経過時間で文字数を進める）。
 * @param {import('vue').MaybeRefOrGetter<string>} source
 * @param {{ msPerChar?: number }} [options]
 */
export function useTypewriterText(source, options = {}) {
  const msPerChar = options.msPerChar ?? DEFAULT_MS_PER_CHAR;
  const displayed = ref('');
  const isComplete = ref(true);
  let rafId = 0;
  let fullText = '';

  function cancel() {
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = 0;
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

    displayed.value = '';
    isComplete.value = false;
    let index = 0;
    let lastTimestamp = 0;

    const step = (timestamp) => {
      if (!lastTimestamp) {
        lastTimestamp = timestamp;
      }

      const elapsed = timestamp - lastTimestamp;
      const charsToReveal = Math.floor(elapsed / msPerChar);
      if (charsToReveal > 0) {
        lastTimestamp = timestamp;
        index = Math.min(fullText.length, index + charsToReveal);
        displayed.value = fullText.slice(0, index);
      }

      if (index < fullText.length) {
        rafId = requestAnimationFrame(step);
      } else {
        rafId = 0;
        isComplete.value = true;
      }
    };

    rafId = requestAnimationFrame(step);
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
