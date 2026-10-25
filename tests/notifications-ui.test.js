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
