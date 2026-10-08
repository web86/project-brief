import { readonly, ref } from 'vue'

export function createToastStore({ now = Date.now } = {}) {
  const items = ref([])
  const timers = new Map()
  let sequence = 0
  function pause(id) {
    const item = items.value.find((item) => item.id === id)
    if (!item || !timers.has(id)) return
    clearTimeout(timers.get(id))
    timers.delete(id)
    item.remaining = Math.max(0, item.remaining - (now() - item.started))
  }
  function dismiss(id) {
    const item = items.value.find((item) => item.id === id)
    if (!item) return
    clearTimeout(timers.get(id))
    timers.delete(id)
    items.value = items.value.filter((item) => item.id !== id)
    item.onDismiss?.()
  }
  function resume(id) {
    const item = items.value.find((item) => item.id === id)
    if (!item || timers.has(id) || item.busy) return
    item.started = now()
    const timer = setTimeout(() => dismiss(id), item.remaining)
    timer.unref?.()
    timers.set(id, timer)
  }
  function show(type, message, options = {}) {
    const id = ++sequence
    if (items.value.length >= 5) dismiss(items.value[0].id)
    items.value.push({
      ...options,
      id,
      type,
      message,
      remaining: options.duration ?? (type === 'error' ? 8000 : 5000),
      busy: false,
    })
    resume(id)
    return id
  }
  async function act(id) {
    const item = items.value.find((item) => item.id === id)
    if (!item?.action || item.busy) return
    pause(id)
    item.busy = true
    try {
      // Invoke synchronously so browser permission requests retain the user's gesture.
      await item.action()
      dismiss(id)
    } finally {
      item.busy = false
      resume(id)
    }
  }
  function clear() {
    for (const item of [...items.value]) dismiss(item.id)
  }
  return {
    items: readonly(items),
    success: (message, options) => show('success', message, options),
    error: (message, options) => show('error', message, options),
    info: (message, options) => show('info', message, options),
    warning: (message, options) => show('warning', message, options),
    dismiss,
    pause,
    resume,
    act,
    clear,
  }
}

export const toast = createToastStore()
