import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createLiveSync, SYNC_INTERVAL } from '../src/services/liveSync.js'
import { normalizeCompanyWebsite, validCompanyWebsite } from '../src/services/branding.js'
import { readFile } from 'node:fs/promises'

function fixture({
  shared = new Map(),
  channels = [],
  gate,
  fail = false,
  response,
  onRefresh,
  onNotify,
  clock = { time: 1000000 },
} = {}) {
  let sequence = 0
  const timers = new Map(),
    listeners = new Map(),
    sw = new Map(),
    calls = [],
    updates = []
  const env = {
    setTimeout(callback, delay) {
      const id = ++sequence
      timers.set(id, { callback, at: clock.time + delay })
      return id
    },
    clearTimeout(id) {
      timers.delete(id)
    },
    localStorage: {
      getItem: (key) => shared.get(key),
      setItem: (key, value) => shared.set(key, value),
    },
    document: {
      visibilityState: 'visible',
      addEventListener: (name, fn) => listeners.set(name, fn),
      removeEventListener: (name) => listeners.delete(name),
    },
    navigator: {
      serviceWorker: {
        addEventListener: (name, fn) => sw.set(name, fn),
        removeEventListener: (name) => sw.delete(name),
      },
    },
    BroadcastChannel: class {
      constructor(name) {
        this.name = name
        channels.push(this)
      }
      postMessage(data) {
        for (const c of channels) if (c !== this && c.name === this.name) c.onmessage?.({ data })
      }
      close() {
        channels.splice(channels.indexOf(this), 1)
      }
    },
  }
  const sync = createLiveSync({
    notify: onNotify,
    env,
    now: () => clock.time,
    request: async (path) => {
      calls.push(path)
      if (fail) throw Error('offline')
      if (gate) await gate
      return response
        ? response(calls.length)
        : { cursor: 'next', changed: true, reset: false, projects: ['project'] }
    },
    refresh: async (result) => {
      updates.push(result)
      await onRefresh?.(result)
    },
  })
  const start = (key = 'actor') => sync.start({ key, cursor: 'seed', url: '/api/admin/sync' })
  const flush = async () => {
    for (let i = 0; i < 20; i++) await Promise.resolve()
  }
  async function advance(milliseconds) {
    clock.time += milliseconds
    for (const [id, timer] of [...timers])
      if (timer.at <= clock.time && timers.has(id)) {
        timers.delete(id)
        timer.callback()
      }
    await flush()
  }
  async function visible(value) {
    env.document.visibilityState = value ? 'visible' : 'hidden'
    await listeners.get('visibilitychange')?.()
    await flush()
  }
  const push = (id) => sw.get('message')?.({ data: { type: 'projectbrief-sync', id } })
  return { sync, start, advance, visible, push, calls, updates, timers, flush, env }
}

test('first opening uses bootstrap only; exactly three lightweight checks over 15 idle minutes', async () => {
  const f = fixture()
  f.start()
  assert.equal(f.calls.length, 0)
  await f.advance(SYNC_INTERVAL - 1)
  assert.equal(f.calls.length, 0)
  await f.advance(1)
  await f.advance(SYNC_INTERVAL)
  await f.advance(SYNC_INTERVAL)
  assert.equal(f.calls.length, 3)
  assert.ok(f.calls.every((path) => path.startsWith('/api/admin/sync?cursor=')))
  f.sync.stop()
  assert.equal(f.timers.size, 0)
})
test('hidden tabs stop all periodic checks and resume only after the full shared interval', async () => {
  const f = fixture()
  f.start()
  await f.advance(SYNC_INTERVAL / 3)
  await f.visible(false)
  await f.advance(SYNC_INTERVAL / 3)
  assert.equal(f.calls.length, 0)
  await f.visible(true)
  assert.equal(f.calls.length, 0)
  await f.visible(false)
  await f.advance(SYNC_INTERVAL / 3)
  await f.visible(true)
  assert.equal(f.calls.length, 1)
  f.sync.stop()
})
test('network errors wait another full interval, including visibility toggles', async () => {
  const f = fixture({ fail: true })
  f.start()
  await f.advance(SYNC_INTERVAL)
  assert.equal(f.calls.length, 1)
  for (let i = 0; i < 3; i++) {
    await f.visible(false)
    await f.visible(true)
  }
  await f.advance(SYNC_INTERVAL - 1)
  assert.equal(f.calls.length, 1)
  await f.advance(1)
  assert.equal(f.calls.length, 2)
  assert.equal(f.updates.length, 0)
  f.sync.stop()
})
test('push bursts coalesce, duplicates are ignored and checks never run in parallel', async () => {
  let resolve
  const gate = new Promise((done) => {
    resolve = done
  })
  const f = fixture({ gate })
  f.start()
  f.push('one')
  f.push('one')
  f.push('two')
  await f.advance(250)
  assert.equal(f.calls.length, 1)
  f.sync.check({ pushId: 'two' })
  assert.equal(f.calls.length, 1)
  resolve()
  await f.flush()
  f.push('two')
  await f.advance(250)
  assert.equal(f.calls.length, 1)
  f.sync.stop()
})
test('a push arriving during an in-flight check is drained after the current response', async () => {
  let resolve
  const gate = new Promise((done) => {
    resolve = done
  })
  const f = fixture({ gate })
  f.start()
  f.push('first')
  await f.advance(250)
  f.push('newer')
  await f.advance(250)
  assert.equal(f.calls.length, 1)
  resolve()
  await f.flush()
  await f.advance(250)
  assert.equal(f.calls.length, 2)
  f.sync.stop()
})
test('multiple same-user tabs share a poll and apply one result to both; identities stay isolated', async () => {
  const shared = new Map(),
    channels = [],
    clock = { time: 1000000 }
  const a = fixture({ shared, channels, clock }),
    b = fixture({ shared, channels, clock }),
    foreign = fixture({ shared, channels, clock })
  a.start()
  b.start()
  foreign.start('different-workspace')
  await a.advance(SYNC_INTERVAL)
  await b.advance(0)
  assert.equal(a.calls.length + b.calls.length, 1)
  assert.equal(a.updates.length, 1)
  assert.equal(b.updates.length, 1)
  assert.equal(foreign.updates.length, 0)
  a.sync.stop()
  b.sync.stop()
  foreign.sync.stop()
})
test('late responses cannot apply to a switched account or a stopped coordinator', async () => {
  let resolve
  const gate = new Promise((done) => {
    resolve = done
  })
  const f = fixture({ gate })
  f.start()
  f.push('one')
  await f.advance(250)
  f.start('other')
  resolve()
  await f.flush()
  assert.equal(f.updates.length, 0)
  f.sync.stop()
})
test('hidden followers defer UI refresh until visible and receive no extra initial requests', async () => {
  const shared = new Map(),
    channels = [],
    clock = { time: 1000000 },
    a = fixture({ shared, channels, clock }),
    b = fixture({ shared, channels, clock })
  a.start()
  b.start()
  await b.visible(false)
  await a.advance(SYNC_INTERVAL)
  assert.equal(b.updates.length, 0)
  await b.visible(true)
  assert.equal(b.updates.length, 1)
  assert.equal(b.calls.length, 0)
  a.sync.stop()
  b.sync.stop()
})
test('company website frontend matches the same accepted and rejected corpus as Laravel', async () => {
  const cases = JSON.parse(
    await readFile(new URL('./fixtures/company-websites.json', import.meta.url), 'utf8'),
  )
  for (const row of cases) {
    assert.equal(normalizeCompanyWebsite(row.input), row.normalized, row.input)
    assert.equal(validCompanyWebsite(row.input), row.valid, row.input)
  }
})

test('corrupt or future coordination clocks cannot create a tight timer loop or suppress checking', async () => {
  for (const value of ['Infinity', 'invalid', '999999999999999']) {
    const f = fixture({ shared: new Map([['projectbrief.sync-clock:actor', value]]) })
    f.start()
    await f.advance(SYNC_INTERVAL)
    assert.equal(f.calls.length, 1)
    f.sync.stop()
  }
})

test('hidden tabs retain all changed projects even when a later check has no changes', async () => {
  const shared = new Map(),
    channels = [],
    clock = { time: 1000000 }
  const a = fixture({
    shared,
    channels,
    clock,
    response: (n) => ({
      cursor: 'cursor-' + n,
      changed: n < 3,
      reset: false,
      projects: n < 3 ? ['project-' + n] : [],
    }),
  })
  const b = fixture({ shared, channels, clock })
  a.start()
  b.start()
  await b.visible(false)
  await a.advance(SYNC_INTERVAL)
  await a.advance(SYNC_INTERVAL)
  await a.advance(SYNC_INTERVAL)
  assert.equal(b.updates.length, 0)
  await b.visible(true)
  assert.equal(b.updates.length, 1)
  assert.deepEqual(b.updates[0].projects, ['project-1', 'project-2'])
  a.sync.stop()
  b.sync.stop()
})

test('UI refreshes serialize and drain a newer event arriving while the previous refresh is pending', async () => {
  const shared = new Map(),
    channels = [],
    clock = { time: 1000000 }
  let release,
    count = 0,
    active = 0,
    maximum = 0
  const gate = new Promise((done) => {
    release = done
  })
  const a = fixture({
    shared,
    channels,
    clock,
    response: (n) => ({
      cursor: 'cursor-' + n,
      changed: true,
      reset: false,
      projects: ['project-' + n],
    }),
  })
  const b = fixture({
    shared,
    channels,
    clock,
    onRefresh: async () => {
      active++
      maximum = Math.max(maximum, active)
      if (++count === 1) await gate
      active--
    },
  })
  a.start()
  b.start()
  await a.advance(SYNC_INTERVAL)
  await a.advance(SYNC_INTERVAL)
  assert.equal(b.updates.length, 1)
  release()
  await b.flush()
  assert.equal(b.updates.length, 2)
  assert.equal(maximum, 1)
  assert.deepEqual(b.updates[1].projects, ['project-2'])
  a.sync.stop()
  b.sync.stop()
})

test('a busy cross-tab lock waits a full interval instead of spinning when storage is unavailable', async () => {
  const f = fixture()
  let lockAttempts = 0
  f.env.localStorage.getItem = () => {
    throw Error('blocked storage')
  }
  f.env.navigator.locks = {
    request: async (key, options, callback) => {
      lockAttempts++
      return callback(null)
    },
  }
  f.start()
  await f.advance(SYNC_INTERVAL)
  assert.equal(lockAttempts, 1)
  await f.advance(1)
  assert.equal(lockAttempts, 1)
  assert.equal(f.calls.length, 0)
  f.env.navigator.locks.request = async (key, options, callback) => callback({ name: key })
  await f.advance(SYNC_INTERVAL)
  assert.equal(f.calls.length, 1)
  f.sync.stop()
})

test('only the polling leader shows notifications and private previews are never broadcast', async () => {
  const shared = new Map(),
    channels = [],
    clock = { time: 1000000 },
    notices = []
  const a = fixture({
    shared,
    channels,
    clock,
    onNotify: (items) => notices.push(items),
    response: () => ({
      cursor: 'next',
      changed: false,
      projects: [],
      notifications: [{ id: 'one', preview: 'PRIVATE PREVIEW' }],
    }),
  })
  const b = fixture({
    shared,
    channels,
    clock,
    onNotify: () => assert.fail('Follower must not repeat a banner'),
  })
  a.start()
  b.start()
  const messages = [],
    original = channels[0].postMessage.bind(channels[0])
  channels[0].postMessage = (data) => {
    messages.push(data)
    original(data)
  }
  await a.advance(SYNC_INTERVAL)
  await b.advance(0)
  assert.equal(notices.length, 1)
  assert.equal(a.calls.length + b.calls.length, 1)
  assert.equal(JSON.stringify(messages).includes('PRIVATE PREVIEW'), false)
  a.sync.stop()
  b.sync.stop()
})
test('repeated network failures back off without visibility toggles bypassing the delay', async () => {
  const f = fixture({ fail: true })
  f.start()
  await f.advance(SYNC_INTERVAL)
  await f.advance(SYNC_INTERVAL)
  assert.equal(f.calls.length, 2)
  await f.visible(false)
  await f.visible(true)
  await f.advance(SYNC_INTERVAL)
  assert.equal(f.calls.length, 2)
  f.push('offline-push')
  await f.advance(250)
  assert.equal(f.calls.length, 2)
  await f.advance(SYNC_INTERVAL - 250)
  assert.equal(f.calls.length, 3)
  f.sync.stop()
})
