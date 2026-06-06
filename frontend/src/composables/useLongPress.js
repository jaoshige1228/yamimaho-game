/**
 * @param {(payload: unknown) => void} onLongPress
 * @param {{ delay?: number }} [options]
 */
export function useLongPress(onLongPress, options = {}) {
  const delay = options.delay ?? 200;

  /** @type {ReturnType<typeof setTimeout> | null} */
  let timer = null;
  let pressed = false;
  let longPressActivated = false;

  function clearTimer() {
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
  }

  /** @param {PointerEvent} event */
  function onPressStart(event, payload) {
    if (event.pointerType === 'mouse' && event.button !== 0) {
      return;
    }

    pressed = true;
    longPressActivated = false;
    clearTimer();

    timer = setTimeout(() => {
      if (!pressed) {
        return;
      }
      longPressActivated = true;
      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        navigator.vibrate(12);
      }
      onLongPress(payload);
    }, delay);
  }

  function onPressEnd() {
    pressed = false;
    clearTimer();
  }

  function shouldSuppressClick() {
    if (longPressActivated) {
      longPressActivated = false;
      return true;
    }
    return false;
  }

  return {
    onPressStart,
    onPressEnd,
    onPressCancel: onPressEnd,
    shouldSuppressClick,
  };
}
