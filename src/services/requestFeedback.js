import { onScopeDispose, ref } from 'vue'
import { t } from '../i18n/index.js'
import { toast } from './toast.js'

export function useRequestFeedback() {
  const busy = ref(false)
  const error = ref(null)
  const retrySeconds = ref(0)
  let active, timer, retryAt

  function tick() {
    retrySeconds.value = Math.max(0, Math.ceil((retryAt - Date.now()) / 1000))
    if (!retrySeconds.value) clearInterval(timer)
  }

  function failed(cause) {
    error.value = cause
    if (cause.status !== 429) return
    retryAt = cause.retryAt ?? Date.now() + 60000
    clearInterval(timer)
    tick()
    if (retrySeconds.value) timer = setInterval(tick, 1000)
    toast.warning(() => t('subscriptionUx.rateLimited'))
  }

  function cancel() {
    active?.abort()
    active = null
    busy.value = false
    error.value = null
  }

  async function run(operation) {
    if (busy.value || retrySeconds.value) return
    const controller = new AbortController()
    active = controller
    error.value = null
    busy.value = true
    try {
      const result = await operation(controller.signal)
      if (active === controller) return result
    } catch (cause) {
      if (active === controller && !controller.signal.aborted) failed(cause)
    } finally {
      if (active === controller) {
        active = null
        busy.value = false
      }
    }
  }
  onScopeDispose(() => {
    cancel()
    clearInterval(timer)
  })
  return { busy, error, retrySeconds, run, cancel, failed }
}
