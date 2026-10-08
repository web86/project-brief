export function pushSupport(env) {
  return !!(
    env?.isSecureContext &&
    env.navigator?.serviceWorker &&
    env.PushManager &&
    env.Notification
  )
}
export function decodePublicKey(key) {
  const text = atob(
    key
      .replace(/-/g, '+')
      .replace(/_/g, '/')
      .padEnd(Math.ceil(key.length / 4) * 4, '='),
  )
  return Uint8Array.from(text, (character) => character.charCodeAt(0))
}
function browserOperation(promise, timeoutMs = 30000) {
  let timer
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timer = setTimeout(
        () => reject(Object.assign(new Error('browser_timeout'), { code: 'browser_timeout' })),
        timeoutMs,
      )
    }),
  ]).finally(() => clearTimeout(timer))
}
export function createPushDevice({ env = globalThis, request, prefix }) {
  let config = null
  let registration = null
  let subscription = null
  let revision = 0
  let disposed = false
  const optOutKey = 'projectbrief.pushDisabled'
  function optedOut() {
    try {
      return env.localStorage?.getItem(optOutKey) === 'true'
    } catch {
      return false
    }
  }
  function setOptOut(value) {
    try {
      env.localStorage?.setItem(optOutKey, String(value))
    } catch {}
  }
  async function register() {
    if (!env.isSecureContext || !env.navigator?.serviceWorker) return
    registration = await browserOperation(
      env.navigator.serviceWorker.register('/sw.js', { scope: '/' }),
    )
    registration = await browserOperation(env.navigator.serviceWorker.ready)
  }
  async function current() {
    registration = await browserOperation(env.navigator.serviceWorker.getRegistration('/'))
    subscription = registration?.pushManager
      ? (await browserOperation(registration.pushManager.getSubscription())) || null
      : null
    return subscription
  }
  async function load() {
    const currentRevision = ++revision
    if (!pushSupport(env)) return { state: 'unsupported' }
    const local = await current()
    const hash = local
      ? [
          ...new Uint8Array(
            await env.crypto.subtle.digest('SHA-256', new TextEncoder().encode(local.endpoint)),
          ),
        ]
          .map((value) => value.toString(16).padStart(2, '0'))
          .join('')
      : null
    const result = await request(`${prefix}/status${hash ? '?endpointHash=' + hash : ''}`)
    if (disposed || currentRevision !== revision) return null
    config = result
    if (env.Notification.permission === 'denied') return { state: 'denied' }
    if (local && result.subscribed && env.Notification.permission === 'granted')
      return { state: 'enabled' }
    if (!result.available) return { state: 'unconfigured' }
    if (optedOut()) return { state: 'disabled' }
    return { state: 'default' }
  }
  async function enable({ automatic = false } = {}) {
    if (disposed) return null
    if (!pushSupport(env)) return { state: 'unsupported' }
    if (!config?.available) return { state: 'unconfigured' }
    if (env.Notification.permission === 'denied') return { state: 'denied' }
    // Default permission is requested only by explicit enable; automatic enrollment requires granted.
    const permission =
      env.Notification.permission === 'granted'
        ? 'granted'
        : await browserOperation(env.Notification.requestPermission())
    if (permission !== 'granted') return { state: permission === 'denied' ? 'denied' : 'default' }
    if (disposed) return null
    if (!registration) await register()
    subscription = await browserOperation(registration.pushManager.getSubscription())
    if (disposed) return null
    const expired = subscription?.expirationTime && subscription.expirationTime <= Date.now()
    if (expired && automatic && !config.subscribed) return { state: 'ownership' }
    if (subscription && (expired || (!config.subscribed && !automatic))) {
      // Renew expired owned subscriptions; only explicit enable may replace an unknown binding.
      const removed = await subscription.unsubscribe()
      if (!removed)
        throw Object.assign(new Error('unsubscribe_failed'), { code: 'unsubscribe_failed' })
      subscription = null
    }
    if (disposed) return null
    let created = false
    if (!subscription) {
      subscription = await browserOperation(
        registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: decodePublicKey(config.publicKey),
        }),
      )
      created = true
    }
    try {
      if (disposed) {
        if (created) await subscription.unsubscribe().catch(() => {})
        return null
      }
      await request(`${prefix}/subscriptions`, {
        method: 'POST',
        body: { ...subscription.toJSON(), contentEncoding: 'aes128gcm' },
      })
    } catch (error) {
      if (created) await subscription.unsubscribe().catch(() => {})
      if (automatic && error.code === 'device_owned_elsewhere') return { state: 'ownership' }
      throw error
    }
    config.subscribed = true
    setOptOut(false)
    return { state: 'enabled' }
  }
  async function enroll() {
    await register()
    if (disposed) return null
    const result = await load()
    if (!result || ['unsupported', 'unconfigured', 'denied'].includes(result.state)) return result
    if (optedOut()) return { state: 'disabled' }
    if (env.Notification.permission === 'granted') return enable({ automatic: true })
    return result
  }
  async function disable() {
    await current()
    if (subscription) {
      await request(`${prefix}/subscriptions`, {
        method: 'DELETE',
        body: { endpoint: subscription.endpoint },
      })
      const removed = await subscription.unsubscribe()
      if (!removed)
        throw Object.assign(new Error('unsubscribe_failed'), { code: 'unsubscribe_failed' })
    }
    subscription = null
    if (config) config.subscribed = false
    setOptOut(true)
    return { state: config?.available ? 'disabled' : 'unconfigured' }
  }
  return {
    load,
    enroll,
    enable,
    disable,
    dispose: () => {
      disposed = true
      revision++
    },
    endpoint: async () => (await current())?.endpoint || null,
  }
}
