export const SYNC_INTERVAL = 60000

// One coordinator per authenticated workspace/project. No private API data is persisted.
export function createLiveSync({
  request,
  refresh,
  notify = () => {},
  env = globalThis,
  now = () => Date.now(),
}) {
  let identity,
    cursor,
    channel,
    timer,
    pending,
    revision = 0,
    lastSuccess = 0,
    lastAttempt = 0,
    failures = 0
  let deferred = null,
    pushTimer,
    queuedPush,
    refreshPending,
    queuedRefresh,
    retryRefreshAt = 0
  const seenPush = new Set()
  const combine = (previous, next) =>
    !previous
      ? next
      : {
          ...previous,
          ...next,
          changed: previous.changed || next.changed,
          reset: previous.reset || next.reset,
          projects: [...new Set([...(previous.projects || []), ...(next.projects || [])])],
          kinds: [...new Set([...(previous.kinds || []), ...(next.kinds || [])])],
        }
  function apply(result) {
    if (!result.changed) return Promise.resolve()
    if (!visible()) {
      deferred = combine(deferred, result)
      return Promise.resolve()
    }
    queuedRefresh = combine(queuedRefresh, result)
    if (refreshPending) return refreshPending
    const current = revision
    const operation = (async () => {
      while (queuedRefresh && current === revision) {
        const batch = queuedRefresh
        queuedRefresh = null
        if (!visible()) {
          deferred = combine(deferred, batch)
          break
        }
        try {
          await refresh(batch)
          if (current === revision) retryRefreshAt = 0
        } catch (error) {
          if (current === revision) {
            deferred = combine(combine(deferred, batch), queuedRefresh || { changed: false })
            queuedRefresh = null
            retryRefreshAt = now() + SYNC_INTERVAL
          }
          throw error
        }
      }
    })().finally(() => {
      if (refreshPending === operation) refreshPending = null
    })
    refreshPending = operation
    return operation
  }
  const visible = () => env.document?.visibilityState !== 'hidden'
  const storageKey = () => 'projectbrief.sync-clock:' + identity.key
  const readClock = () => {
    try {
      const value = Number(env.localStorage?.getItem(storageKey()))
      return Number.isFinite(value) && value >= 0 && value <= now() ? value : 0
    } catch {
      return 0
    }
  }
  const writeClock = (value) => {
    try {
      env.localStorage?.setItem(storageKey(), String(value))
    } catch {}
  }
  const interval = () => SYNC_INTERVAL * Math.min(5, 2 ** Math.max(0, failures - 1))
  function schedule() {
    env.clearTimeout(timer)
    if (!identity || !visible()) return
    timer = env.setTimeout(
      () => check(),
      Math.max(1, Math.max(lastSuccess, lastAttempt, readClock()) + interval() - now()),
    )
  }
  async function receive(event) {
    const data = event.data
    if (!identity || data?.key !== identity.key) return
    if (data.type === 'hello') {
      channel?.postMessage({ type: 'clock', key: identity.key, at: lastSuccess })
    } else if (data.type === 'clock') {
      if (data.at > lastSuccess && data.at <= now()) lastSuccess = data.at
      schedule()
    } else if (data.type === 'result' && data.at >= lastSuccess && data.at <= now()) {
      lastSuccess = data.at
      cursor = data.result.cursor
      await apply(data.result).catch(() => {})
      schedule()
    }
  }
  async function visibility() {
    if (!identity) return
    if (visible() && deferred && now() >= retryRefreshAt) {
      const result = deferred
      deferred = null
      await apply(result).catch(() => {})
    }
    if (visible() && now() - Math.max(lastSuccess, lastAttempt, readClock()) >= interval()) check()
    else schedule()
  }
  function signal(event) {
    if (event.data?.type !== 'projectbrief-sync' || typeof event.data.id !== 'string') return
    queuedPush = event.data.id
    env.clearTimeout(pushTimer)
    pushTimer = env.setTimeout(() => {
      if (pending) return
      const pushId = queuedPush
      queuedPush = null
      if (pushId) check({ pushId })
    }, 250)
  }
  function stop() {
    revision++
    env.clearTimeout(timer)
    env.clearTimeout(pushTimer)
    queuedPush = null
    channel?.close()
    channel = null
    identity = null
    cursor = null
    pending = null
    deferred = null
    queuedRefresh = null
    refreshPending = null
    retryRefreshAt = 0
    seenPush.clear()
    failures = 0
    env.document?.removeEventListener?.('visibilitychange', visibility)
    env.navigator?.serviceWorker?.removeEventListener?.('message', signal)
  }
  function start(next) {
    if (!next?.key || !next?.cursor) {
      stop()
      return
    }
    if (identity?.key === next.key) return
    stop()
    identity = next
    cursor = next.cursor
    lastSuccess = now()
    lastAttempt = 0
    if (env.BroadcastChannel) {
      channel = new env.BroadcastChannel('projectbrief.sync:' + next.key)
      channel.onmessage = receive
      channel.postMessage({ type: 'hello', key: next.key })
    }
    env.document?.addEventListener?.('visibilitychange', visibility)
    env.navigator?.serviceWorker?.addEventListener?.('message', signal)
    schedule()
  }
  function check({ pushId } = {}) {
    if (!identity || pending || !visible()) return pending || Promise.resolve(false)
    if (pushId && seenPush.has(pushId)) return Promise.resolve(false)
    const current = revision,
      scope = identity
    const operation = async () => {
      if (current !== revision) return false
      const clock = Math.max(lastSuccess, lastAttempt, readClock())
      if (failures && now() - lastAttempt < interval()) return false
      if (!pushId && now() - clock < interval()) return false
      if (pushId) {
        // A shared, expiring receipt deduplicates the same SW signal in multiple tabs.
        const key = storageKey() + ':push'
        let receipt
        try {
          receipt = JSON.parse(env.localStorage?.getItem(key) || 'null')
        } catch {}
        if (receipt?.id === pushId && receipt.at <= now() && now() - receipt.at < SYNC_INTERVAL)
          return false
        try {
          env.localStorage?.setItem(key, JSON.stringify({ id: pushId, at: now() }))
        } catch {}
        seenPush.add(pushId)
        if (seenPush.size > 100) seenPush.delete(seenPush.values().next().value)
      }
      lastAttempt = now()
      writeClock(lastAttempt)
      const result = await request(scope.url + '?cursor=' + encodeURIComponent(cursor))
      if (current !== revision) return false
      if (visible() && deferred && now() >= retryRefreshAt) {
        const previous = deferred
        deferred = null
        await apply(combine(previous, result))
      } else await apply(result)
      if (current !== revision) return false
      if (visible()) notify(result.notifications || [], result.additionalNotifications || 0)
      cursor = result.cursor
      failures = 0
      lastSuccess = now()
      writeClock(lastSuccess)
      const { notifications, additionalNotifications, ...sharedResult } = result
      channel?.postMessage({
        type: 'result',
        key: scope.key,
        at: lastSuccess,
        result: sharedResult,
      })
      return true
    }
    const run = env.navigator?.locks
      ? env.navigator.locks.request(
          'projectbrief.sync:' + scope.key,
          { ifAvailable: true },
          (lock) => {
            if (lock) return operation()
            if (current === revision) lastAttempt = now()
            return false
          },
        )
      : operation()
    const promise = Promise.resolve(run)
      .catch(() => {
        if (current === revision) failures++
        return false
      })
      .finally(() => {
        if (current === revision && pending === promise) {
          pending = null
          schedule()
          if (queuedPush) {
            const pushId = queuedPush
            queuedPush = null
            pushTimer = env.setTimeout(() => check({ pushId }), 250)
          }
        }
      })
    pending = promise
    return promise
  }
  return { start, stop, check }
}
