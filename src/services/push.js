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
  async function current() {
    registration = await env.navigator.serviceWorker.getRegistration('/')
    subscription = (await registration?.pushManager.getSubscription()) || null
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
    if (currentRevision !== revision) return null
    config = result
    if (env.Notification.permission === 'denied') return { state: 'denied' }
    if (local && result.subscribed && env.Notification.permission === 'granted')
      return { state: 'enabled' }
    if (!result.available) return { state: 'unconfigured' }
    return { state: 'default' }
  }
  async function enable() {
    if (!pushSupport(env)) return { state: 'unsupported' }
    if (!config?.available) return { state: 'unconfigured' }
    if (env.Notification.permission === 'denied') return { state: 'denied' }
    // This method is called only from a click: request permission before any asynchronous work.
    const permission =
      env.Notification.permission === 'granted'
        ? 'granted'
        : await browserOperation(env.Notification.requestPermission())
    if (permission !== 'granted') return { state: permission === 'denied' ? 'denied' : 'default' }
    registration = await browserOperation(
      env.navigator.serviceWorker.register('/sw.js', { scope: '/' }),
    )
    registration = await browserOperation(env.navigator.serviceWorker.ready)
    subscription = await registration.pushManager.getSubscription()
    if (subscription && !config.subscribed) {
      // Browser state may belong to an earlier sign-in. Explicitly enabling creates a new device binding.
      const removed = await subscription.unsubscribe()
      if (!removed)
        throw Object.assign(new Error('unsubscribe_failed'), { code: 'unsubscribe_failed' })
      subscription = null
    }
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
      await request(`${prefix}/subscriptions`, {
        method: 'POST',
        body: { ...subscription.toJSON(), contentEncoding: 'aes128gcm' },
      })
    } catch (error) {
      if (created) await subscription.unsubscribe().catch(() => {})
      throw error
    }
    config.subscribed = true
    return { state: 'enabled' }
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
    return { state: config?.available ? 'default' : 'unconfigured' }
  }
  return { load, enable, disable, endpoint: async () => (await current())?.endpoint || null }
}
