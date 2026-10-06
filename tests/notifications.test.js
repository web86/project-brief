import { test } from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'
import { createPushDevice, pushSupport } from '../src/services/push.js'
import {
  createNotificationSettings,
  notificationEvents,
} from '../src/services/notificationSettings.js'
function browser(permission = 'default') {
  const calls = []
  let subscription = null
  const reg = {
    pushManager: {
      getSubscription: async () => subscription,
      subscribe: async () => {
        calls.push('subscribe')
        subscription = {
          endpoint: 'https://fcm.googleapis.com/send/device',
          toJSON: () => ({
            endpoint: subscription.endpoint,
            keys: { p256dh: 'key', auth: 'auth' },
          }),
          unsubscribe: async () => {
            calls.push('unsubscribe')
            subscription = null
            return true
          },
        }
        return subscription
      },
    },
  }
  const env = {
    isSecureContext: true,
    crypto: globalThis.crypto,
    PushManager: {},
    Notification: {
      permission,
      requestPermission: async () => {
        calls.push('permission')
        env.Notification.permission = 'granted'
        return 'granted'
      },
    },
    navigator: {
      serviceWorker: {
        getRegistration: async () => reg,
        register: async () => {
          calls.push('register')
          return reg
        },
        ready: Promise.resolve(reg),
      },
    },
  }
  const request = async (path, options) => {
    calls.push({ path, ...options })
    return path.includes('/status')
      ? { available: true, subscribed: !!subscription, publicKey: 'YQ' }
      : { subscribed: true }
  }
  return { env, request, calls }
}
test('unsupported and insecure browsers skip API and permission calls', async () => {
  assert.equal(pushSupport({}), false)
  const { env, request, calls } = browser()
  env.isSecureContext = false
  const device = createPushDevice({ env, request, prefix: '/api/client/push' })
  assert.deepEqual(await device.load(), { state: 'unsupported' })
  assert.deepEqual(await device.enable(), { state: 'unsupported' })
  assert.equal(calls.length, 0)
})
test('mount/load never prompts; only explicit enable creates subscription and disable removes this device', async () => {
  const { env, request, calls } = browser()
  const device = createPushDevice({ env, request, prefix: '/api/admin/push' })
  assert.deepEqual(await device.load(), { state: 'default' })
  assert.equal(calls.includes('permission'), false)
  assert.equal(calls.includes('register'), false)
  assert.deepEqual(await device.enable(), { state: 'enabled' })
  assert.equal(calls.filter((call) => call === 'permission').length, 1)
  const post = calls.find((call) => call.method === 'POST')
  assert.equal(post.path, '/api/admin/push/subscriptions')
  assert.equal(post.body.endpoint, 'https://fcm.googleapis.com/send/device')
  assert.deepEqual(await device.load(), { state: 'enabled' })
  assert.deepEqual(await device.disable(), { state: 'default' })
  assert.equal(calls.find((call) => call.method === 'DELETE').body.endpoint, post.body.endpoint)
  assert.equal(await device.endpoint(), null)
})
test('denied permission shows blocked state and does not prompt again', async () => {
  const { env, request, calls } = browser('denied')
  const device = createPushDevice({ env, request, prefix: '/api/client/push' })
  assert.deepEqual(await device.load(), { state: 'denied' })
  assert.deepEqual(await device.enable(), { state: 'denied' })
  assert.equal(calls.includes('permission'), false)
})
test('dismissed permission and unconfigured server never create subscriptions', async () => {
  const { env, request, calls } = browser()
  const device = createPushDevice({ env, request, prefix: '/api/client/push' })
  await device.load()
  env.Notification.requestPermission = async () => 'default'
  assert.deepEqual(await device.enable(), { state: 'default' })
  assert.equal(calls.includes('subscribe'), false)
  const unavailable = createPushDevice({
    env,
    request: async () => ({ available: false }),
    prefix: '/api/client/push',
  })
  assert.deepEqual(await unavailable.load(), { state: 'unconfigured' })
  assert.deepEqual(await unavailable.enable(), { state: 'unconfigured' })
})
test('failed subscription persistence rolls back only newly created browser subscription', async () => {
  const { env, request, calls } = browser()
  const device = createPushDevice({
    env,
    request: (path, options) =>
      options?.method === 'POST' ? Promise.reject(new Error('offline')) : request(path, options),
    prefix: '/api/client/push',
  })
  await device.load()
  await assert.rejects(device.enable(), /offline/)
  assert.equal(calls.includes('unsubscribe'), true)
  assert.equal(await device.endpoint(), null)
})
test('project preference client loads and saves channels and test delivery errors propagate', async () => {
  const calls = []
  const events = Object.fromEntries(
    notificationEvents.map((event) => [event, { email: true, push: false }]),
  )
  const client = createNotificationSettings(async (path, options) => {
    calls.push({ path, ...options })
    if (path.endsWith('/test') && options.body.channel === 'push')
      throw Object.assign(new Error(), { code: 'device_not_subscribed' })
    return { events, notificationEmail: '' }
  }, 'project-uuid')
  const settings = await client.load()
  assert.equal(settings.events['admin.task_done'].push, false)
  await client.save(settings)
  assert.deepEqual(calls[1].body, { events, notificationEmail: null })
  await client.test('email')
  assert.deepEqual(calls[2].body, { channel: 'email' })
  await assert.rejects(
    client.test('push', 'this-device'),
    (error) => error.code === 'device_not_subscribed',
  )
  assert.equal(calls[3].body.endpoint, 'this-device')
})
test('worker shows safe payload and clicking focuses a matching tab without caching or opening foreign URLs', async () => {
  const listeners = {},
    notifications = [],
    opened = [],
    focused = []
  const self = {
    location: { origin: 'https://brief.example.test' },
    addEventListener: (name, handler) => {
      listeners[name] = handler
    },
    registration: { showNotification: async (...args) => notifications.push(args) },
    clients: {
      matchAll: async () => [
        {
          url: 'https://brief.example.test/project/11111111-1111-1111-1111-111111111111',
          navigate: async (url) => focused.push(url),
          focus: async () => focused.push('focus'),
        },
      ],
      openWindow: async (url) => opened.push(url),
    },
  }
  vm.runInNewContext(await readFile(new URL('../public/sw.js', import.meta.url), 'utf8'), {
    self,
    URL,
  })
  const url =
    'https://brief.example.test/project/11111111-1111-1111-1111-111111111111/task/22222222-2222-2222-2222-222222222222'
  let work
  listeners.push({
    data: { json: () => ({ title: 'Ready', body: 'Task', url }) },
    waitUntil: (promise) => {
      work = promise
    },
  })
  await work
  assert.equal(notifications[0][1].data.url, url)
  assert.equal(listeners.fetch, undefined)
  listeners.notificationclick({
    notification: { close() {}, data: { url } },
    waitUntil: (promise) => {
      work = promise
    },
  })
  await work
  assert.deepEqual(focused, [url, 'focus'])
  assert.equal(opened.length, 0)
  listeners.push({
    data: { json: () => ({ title: 'Unsafe', url: 'https://evil.test/' }) },
    waitUntil: (promise) => {
      work = promise
    },
  })
  await work
  assert.equal(notifications[1][1].data.url, 'https://brief.example.test/')
})

test('an unresolved browser permission request times out instead of leaving the control busy forever', async (context) => {
  const { env, request } = browser()
  const device = createPushDevice({ env, request, prefix: '/api/admin/push' })
  await device.load()
  env.Notification.requestPermission = () => new Promise(() => {})
  context.mock.timers.enable({ apis: ['setTimeout'] })
  const operation = device.enable()
  context.mock.timers.tick(30000)
  await assert.rejects(operation, (error) => error.code === 'browser_timeout')
})
