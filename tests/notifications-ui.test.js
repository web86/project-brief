import { beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import {
  usePersonalNotificationsStore,
  telegramDismissalKey,
} from '../src/stores/personalNotifications.js'
let values
beforeEach(() => {
  setActivePinia(createPinia())
  values = new Map()
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => values.get(key),
      setItem: (key, value) => values.set(key, value),
    },
  })
})
const settings = (enabled) => ({ preferences: { telegram: enabled, email: true, push: true } })
test('Telegram prompt waits for both authoritative reads and excludes unavailable, anonymous and ready accounts', async () => {
  const state = usePersonalNotificationsStore()
  const request = async () => {
    throw new Error('must not request anonymously')
  }
  assert.equal(await state.loadSettings(false, request), null)
  assert.equal(await state.loadTelegram(false, request), null)
  state.setIdentity('client', 'one')
  assert.equal(state.needsTelegram, false)
  state.adoptSettings(settings(true))
  assert.equal(state.needsTelegram, false)
  for (const [available, connected, enabled, expected] of [
    [false, false, true, false],
    [false, true, false, false],
    [true, false, true, true],
    [true, true, false, true],
    [true, true, true, false],
  ]) {
    state.adoptSettings(settings(enabled))
    await state.loadTelegram(true, async () => ({ available, connected }))
    assert.equal(state.needsTelegram, expected)
  }
  await assert.rejects(
    state.loadTelegram(true, async () => {
      throw Error('offline')
    }),
  )
  assert.equal(state.telegram, null)
  assert.equal(state.needsTelegram, false)
})
test('dismissal is persistent, separated by persona and ID, and only stores a boolean', async () => {
  const state = usePersonalNotificationsStore()
  const ready = async (persona, id) => {
    state.setIdentity(persona, id)
    state.adoptSettings(settings(false))
    await state.loadTelegram(false, async () => ({ available: true, connected: false }))
  }
  await ready('client', 'one')
  assert.equal(state.needsTelegram, true)
  state.dismiss()
  assert.equal(state.needsTelegram, false)
  state.adoptSettings(settings(true))
  state.adoptSettings(settings(false))
  assert.equal(state.needsTelegram, false)
  await ready('client', 'two')
  assert.equal(state.needsTelegram, true)
  await ready('admin', 'one')
  assert.equal(state.needsTelegram, true)
  state.setIdentity(null, null)
  await ready('client', 'one')
  assert.equal(state.needsTelegram, false)
  assert.deepEqual([...values], [[telegramDismissalKey('client', 'one'), 'true']])
})
test('simultaneous banner and settings reads deduplicate, cache, and reject late previous-user responses', async () => {
  const state = usePersonalNotificationsStore()
  state.setIdentity('client', 'first')
  const resolvers = [],
    calls = []
  const request = (path) => {
    calls.push(path)
    return new Promise((resolve) => resolvers.push(resolve))
  }
  const oldSettings = state.loadSettings(false, request),
    oldTelegram = state.loadTelegram(false, request)
  const duplicate = state.loadSettings(false, request),
    duplicateTelegram = state.loadTelegram(false, request)
  assert.equal(calls.length, 2)
  state.setIdentity('client', 'second')
  state.adoptSettings(settings(false))
  await state.loadTelegram(false, async () => ({ available: true, connected: false }))
  resolvers[0](settings(true))
  resolvers[1]({ available: true, connected: true })
  assert.deepEqual(await Promise.all([oldSettings, oldTelegram, duplicate, duplicateTelegram]), [
    null,
    null,
    null,
    null,
  ])
  assert.equal(state.settings.preferences.telegram, false)
  assert.equal(state.telegram.connected, false)
  await state.loadSettings(false, request)
  await state.loadTelegram(false, request)
  assert.equal(calls.length, 2)
})
test('an older pending read cannot overwrite a confirmed save or router bootstrap', async () => {
  const state = usePersonalNotificationsStore()
  state.setIdentity('admin', 'opaque-account-id')
  let resolve
  const reading = state.loadSettings(
    false,
    () =>
      new Promise((done) => {
        resolve = done
      }),
  )
  state.adoptSettings(settings(false))
  resolve(settings(true))
  await reading
  assert.equal(state.settings.preferences.telegram, false)
})
test('blocked localStorage preserves in-memory dismissal and identity isolation', () => {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    get() {
      throw Error('blocked')
    },
  })
  const state = usePersonalNotificationsStore()
  state.setIdentity('admin', 'a')
  state.dismiss()
  assert.equal(state.dismissed, true)
  state.setIdentity('admin', 'b')
  assert.equal(state.dismissed, false)
})

const deferred = () => {
  let resolve, reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
test('personal autosave serializes rapid channels, deduplicates and uses the confirmed server response', async () => {
  const state = usePersonalNotificationsStore()
  state.setIdentity('client', 'anna')
  state.adoptSettings(settings(true))
  const gate = deferred(),
    calls = []
  const request = async (path, options) => {
    calls.push({ path, ...options })
    if (calls.length === 1) await gate.promise
    return { email: 'server@example.test', preferences: { ...options.body.preferences } }
  }
  const first = state.savePreference('email', false, request)
  const duplicate = state.savePreference('email', false, request)
  const second = state.savePreference('push', false, request)
  const third = state.savePreference('telegram', false, request)
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(calls.length, 1)
  assert.deepEqual(state.savingPreferences, { email: true, push: true, telegram: true })
  assert.equal(state.settings.preferences.email, true, 'unconfirmed settings remain unchanged')
  gate.resolve()
  await Promise.all([first, duplicate, second, third])
  assert.equal(calls.length, 3)
  assert.ok(
    calls.every((call) => call.path === '/api/client/notifications' && call.method === 'PATCH'),
  )
  assert.deepEqual(calls[1].body.preferences, { email: false, push: false, telegram: true })
  assert.deepEqual(calls[2].body.preferences, { email: false, push: false, telegram: false })
  assert.deepEqual(state.settings.preferences, { email: false, push: false, telegram: false })
  assert.equal(state.settings.email, 'server@example.test')
  assert.deepEqual(state.savingPreferences, {})
  assert.equal(await state.savePreference('telegram', false, request), false)
  assert.equal(calls.length, 3)
})
test('failed preference restores confirmed value and does not drop a queued channel', async () => {
  const state = usePersonalNotificationsStore()
  state.setIdentity('client', 'anna')
  state.adoptSettings(settings(true))
  const calls = []
  const request = async (path, options) => {
    calls.push(options.body.preferences)
    if (calls.length === 1) throw Error('offline')
    return { preferences: options.body.preferences }
  }
  const email = state.savePreference('email', false, request)
  const push = state.savePreference('push', false, request)
  await assert.rejects(email, /offline/)
  await push
  assert.deepEqual(state.settings.preferences, { email: true, push: false, telegram: true })
  assert.deepEqual(state.savingPreferences, {})
})
test('autosave and queued writes cannot leak across identity changes, including switching back', async () => {
  const state = usePersonalNotificationsStore()
  state.setIdentity('client', 'a')
  state.adoptSettings(settings(true))
  const gate = deferred(),
    calls = []
  const request = async (path, options) => {
    calls.push(path)
    await gate.promise
    return { preferences: options.body.preferences }
  }
  const email = state.savePreference('email', false, request),
    push = state.savePreference('push', false, request)
  await new Promise((resolve) => setImmediate(resolve))
  state.setIdentity('client', 'b')
  state.adoptSettings(settings(false))
  state.setIdentity('client', 'a')
  state.adoptSettings(settings(true))
  gate.resolve()
  assert.deepEqual(await Promise.all([email, push]), [false, false])
  assert.equal(calls.length, 1)
  assert.deepEqual(state.settings, settings(true))
  assert.deepEqual(state.savingPreferences, {})
})
