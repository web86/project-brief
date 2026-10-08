import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { inflateSync } from 'node:zlib'
import { createSSRApp, createRenderer, nextTick, ssrContextKey } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { createServer } from 'vite'
import { createToastStore } from '../src/services/toast.js'
import { createPushEnrollment } from '../src/services/pushEnrollment.js'
import { createPushDevice } from '../src/services/push.js'
let server,
  routerFactory,
  useProjectStore,
  entryView,
  notificationSettings,
  viewport,
  api,
  toast,
  i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  routerFactory = (await server.ssrLoadModule('/src/router/index.js')).createProjectRouter
  useProjectStore = (await server.ssrLoadModule('/src/stores/project.js')).useProjectStore
  entryView = (await server.ssrLoadModule('/src/views/AppEntryView.vue')).default
  notificationSettings = (
    await server.ssrLoadModule('/src/components/ProjectNotificationSettings.vue')
  ).default
  viewport = (await server.ssrLoadModule('/src/components/ToastViewport.vue')).default
  api = (await server.ssrLoadModule('/src/api/client.js')).api
  toast = (await server.ssrLoadModule('/src/services/toast.js')).toast
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => {
  toast?.clear()
  await server?.close()
})

for (const [persona, destination] of [
  ['admin', '/admin'],
  ['client', '/project/11111111-1111-4111-8111-111111111111'],
  ['guest', '/app'],
]) {
  test(`/app chooses the server ${persona} persona using one bootstrap request`, async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useProjectStore(pinia)
    store.admin = { name: 'stale local persona' }
    store.currentClient = { name: 'stale local client' }
    const loads = [],
      requests = []
    store.loadApiProject = async (...args) => loads.push(args)
    const router = routerFactory({
      history: createMemoryHistory(),
      apiMode: true,
      request: async (path) => {
        requests.push(path)
        if (path === '/api/admin/me') return { user: { name: 'Admin' } }
        assert.equal(path, '/api/session')
        return persona === 'guest'
          ? { authenticated: false }
          : {
              authenticated: true,
              type: persona,
              project: { id: '11111111-1111-4111-8111-111111111111' },
            }
      },
    })
    await router.push('/app')
    assert.equal(router.currentRoute.value.path, destination)
    assert.equal(requests.filter((path) => path === '/api/session').length, 1)
    assert.equal(requests.includes('/api/client/me'), false)
    if (persona === 'client')
      assert.deepEqual(loads, [['11111111-1111-4111-8111-111111111111', false]])
    if (persona === 'guest') {
      assert.equal(store.admin, null)
      assert.equal(store.currentClient, null)
      for (const language of ['en', 'ru']) {
        i18n.global.locale.value = language
        const html = await renderToString(createSSRApp(entryView).use(pinia).use(router).use(i18n))
        assert.ok(
          html.includes(
            language === 'en'
              ? 'Project access was not found on this device.'
              : 'Доступ к проекту на этом устройстве не найден.',
          ),
        )
        assert.ok(html.includes('href="/admin/login"'))
        assert.equal(html.includes('type="password"'), false)
      }
    }
  })
}
test('failed bootstrap stays on neutral entry with a retry, without pretending to be a guest', async () => {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useProjectStore(pinia)
  const router = routerFactory({
    history: createMemoryHistory(),
    apiMode: true,
    request: async () => {
      throw new Error('offline')
    },
  })
  await router.push('/app')
  i18n.global.locale.value = 'en'
  assert.equal(router.currentRoute.value.path, '/app')
  assert.equal(store.apiError.message, 'offline')
  const html = await renderToString(createSSRApp(entryView).use(pinia).use(router).use(i18n))
  assert.ok(html.includes('Try again'))
  assert.equal(html.includes('Project access was not found'), false)
})

test('toast queue supports every severity, bounded queuing, manual dismissal and unmount cleanup', () => {
  const queue = createToastStore()
  for (const type of ['success', 'error', 'info', 'warning']) queue[type](type)
  assert.deepEqual(
    queue.items.value.map((item) => item.type),
    ['success', 'error', 'info', 'warning'],
  )
  queue.dismiss(queue.items.value[1].id)
  assert.deepEqual(
    queue.items.value.map((item) => item.type),
    ['success', 'info', 'warning'],
  )
  for (let i = 0; i < 5; i++) queue.info('extra')
  assert.equal(queue.items.value.length, 5)
  queue.clear()
  assert.equal(queue.items.value.length, 0)
})
test('toast auto-dismiss pauses on interaction, resumes remaining time and clears timers safely', (context) => {
  context.mock.timers.enable({ apis: ['setTimeout', 'Date'] })
  const queue = createToastStore({ now: () => Date.now() })
  const id = queue.info('message', { duration: 5000 })
  context.mock.timers.tick(2000)
  queue.pause(id)
  context.mock.timers.tick(10000)
  assert.equal(queue.items.value.length, 1)
  queue.resume(id)
  context.mock.timers.tick(2999)
  assert.equal(queue.items.value.length, 1)
  context.mock.timers.tick(1)
  assert.equal(queue.items.value.length, 0)
  queue.error('unmounted')
  queue.clear()
  context.mock.timers.tick(10000)
  assert.equal(queue.items.value.length, 0)
})
test('actual global toast markup has status/alert roles, translated close labels and live localization', async () => {
  toast.clear()
  for (const type of ['success', 'error', 'info', 'warning'])
    toast[type](() => i18n.global.t('notifications.saved'))
  i18n.global.locale.value = 'en'
  let html = await renderToString(createSSRApp(viewport).use(i18n))
  assert.equal((html.match(/role="status"/g) || []).length, 2)
  assert.equal((html.match(/role="alert"/g) || []).length, 2)
  assert.ok(html.includes('Dismiss notification'))
  i18n.global.locale.value = 'ru'
  html = await renderToString(createSSRApp(viewport).use(i18n))
  assert.ok(html.includes('Настройки уведомлений сохранены.'))
  assert.ok(html.includes('Закрыть уведомление'))
  toast.clear()
})

function pushBrowser(permission) {
  const calls = []
  let subscription = null
  const registration = {
    pushManager: {
      getSubscription: async () => subscription,
      subscribe: async () => {
        calls.push('subscribe')
        subscription = {
          endpoint: 'https://fcm.googleapis.com/send/device',
          toJSON: () => ({ endpoint: subscription.endpoint, keys: {} }),
          unsubscribe: async () => true,
        }
        return subscription
      },
    },
  }
  const env = {
    isSecureContext: true,
    PushManager: {},
    crypto: globalThis.crypto,
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
        register: async () => registration,
        getRegistration: async () => registration,
        ready: Promise.resolve(registration),
      },
    },
  }
  const device = createPushDevice({
    env,
    prefix: '/api/client/push',
    request: async (path, options) => {
      calls.push(path)
      return options?.method === 'POST'
        ? {}
        : { available: true, subscribed: false, publicKey: 'YQ' }
    },
  })
  return { device, calls }
}
test('default permission presents an informational toast and only its user action requests permission', async () => {
  const { device, calls } = pushBrowser('default'),
    queue = createToastStore(),
    values = new Map()
  const storage = {
    getItem: (key) => values.get(key),
    setItem: (key, value) => values.set(key, value),
  }
  const enrollment = createPushEnrollment({ device, toast: queue, storage, update() {} })
  await enrollment.start()
  assert.equal(queue.items.value.length, 1)
  assert.equal(queue.items.value[0].type, 'info')
  assert.equal(calls.includes('permission'), false)
  const action = queue.act(queue.items.value[0].id)
  assert.equal(
    calls.includes('permission'),
    true,
    'requestPermission must run during the action gesture',
  )
  await action
  assert.ok(calls.includes('/api/client/push/subscriptions'))
  assert.equal(queue.items.value[0].type, 'success')
  enrollment.dispose()
  queue.clear()
})
test('dismissed suggestions stay dismissed across persona mounts in the same browsing session', async () => {
  const queue = createToastStore(),
    values = new Map()
  const storage = {
    getItem: (key) => values.get(key),
    setItem: (key, value) => values.set(key, value),
  }
  for (let i = 0; i < 2; i++) {
    const { device, calls } = pushBrowser('default')
    const enrollment = createPushEnrollment({ device, toast: queue, storage, update() {} })
    await enrollment.start()
    assert.equal(queue.items.value.length, i === 0 ? 1 : 0)
    if (i === 0) queue.dismiss(queue.items.value[0].id)
    assert.equal(calls.includes('permission'), false)
    enrollment.dispose()
  }
})
test('granted and denied permission never show unsolicited suggestions or browser prompts', async () => {
  for (const permission of ['granted', 'denied']) {
    const { device, calls } = pushBrowser(permission),
      queue = createToastStore()
    const enrollment = createPushEnrollment({ device, toast: queue, update() {} })
    await enrollment.start()
    await enrollment.retry()
    assert.equal(queue.items.value.length, 0)
    assert.equal(calls.includes('permission'), false)
    assert.equal(calls.includes('/api/client/push/subscriptions'), permission === 'granted')
    enrollment.dispose()
  }
})

// Mount the real component's setup with Vue's renderer; visual output is tested by SSR.
const renderer = createRenderer({
  insert() {},
  remove() {},
  createElement: () => ({}),
  createText: () => ({}),
  createComment: () => ({}),
  setText() {},
  setElementText() {},
  parentNode: () => null,
  nextSibling: () => null,
  patchProp() {},
})
test('notification settings save and test Push/email feedback use global toasts; validation stays inline', async () => {
  const original = api.request,
    oldNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
  const events = Object.fromEntries(
    [
      'client.task_created',
      'client.comment_created',
      'client.task_approved',
      'admin.clarification_requested',
      'admin.comment_created',
      'admin.task_review',
      'admin.task_done',
    ].map((event) => [event, { email: true, push: true }]),
  )
  const calls = []
  let failure = null
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    if (failure) throw failure
    return { notificationEmail: '', events }
  }
  Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {
      serviceWorker: {
        getRegistration: async () => ({
          pushManager: {
            getSubscription: async () => ({ endpoint: 'https://fcm.googleapis.com/send/device' }),
          },
        }),
      },
    },
  })
  const app = renderer
    .createApp({ ...notificationSettings, render: () => null }, { projectId: 'project-uuid' })
    .use(i18n)
    .provide(ssrContextKey, {})
  try {
    i18n.global.locale.value = 'en'
    toast.clear()
    const instance = app.mount({})
    await new Promise((resolve) => setImmediate(resolve))
    const setup = instance.$.setupState
    await setup.save()
    assert.equal(toast.items.value[0].message(), 'Notification settings saved.')
    toast.clear()
    await setup.test('push')
    assert.equal(toast.items.value[0].message(), 'Test notification sent to this device.')
    assert.equal(calls.at(-1).body.endpoint, 'https://fcm.googleapis.com/send/device')
    toast.clear()
    await setup.test('email')
    assert.equal(toast.items.value[0].message(), 'Test email sent to the developer.')
    toast.clear()
    failure = { status: 422, errors: { notificationEmail: ['Invalid email'] } }
    await setup.save()
    assert.equal(toast.items.value.length, 0)
    assert.deepEqual(setup.validation.notificationEmail, ['Invalid email'])
    failure = new Error('offline')
    await setup.test('push')
    assert.equal(toast.items.value[0].type, 'error')
    assert.equal(setup.testing, null)
    await nextTick()
  } finally {
    app.unmount()
    toast.clear()
    api.request = original
    if (oldNavigator) Object.defineProperty(globalThis, 'navigator', oldNavigator)
    else delete globalThis.navigator
  }
})

test('manifest uses neutral entry and regular/maskable icons with the correct PNG dimensions', async () => {
  const manifest = JSON.parse(await readFile('public/manifest.webmanifest', 'utf8'))
  assert.equal(manifest.start_url, '/app')
  assert.equal(manifest.scope, '/')
  assert.equal(manifest.display, 'standalone')
  assert.equal(manifest.background_color, manifest.theme_color)
  assert.equal(manifest.icons.length, 4)
  for (const icon of manifest.icons) {
    const data = await readFile('public' + icon.src)
    const size = Number(icon.sizes.split('x')[0])
    assert.equal(data.readUInt32BE(16), size)
    assert.equal(data.readUInt32BE(20), size)
    assert.ok(['any', 'maskable'].includes(icon.purpose))
  }
  const apple = await readFile('public/apple-touch-icon.png')
  assert.equal(apple.readUInt32BE(16), 180)
})
test('HTML shell immediately displays a branded accessible loader and provides failure/reduced motion fallbacks', async (context) => {
  const html = await readFile('index.html', 'utf8')
  assert.equal((html.match(/name="theme-color"/g) || []).length, 1)
  assert.ok(html.includes('prefers-reduced-motion'))
  assert.match(html, /<noscript\b/)
  assert.ok(html.indexOf('id="bootstrap-splash"') < html.indexOf('src="/src/main.js"'))
  context.mock.timers.enable({ apis: ['setTimeout'] })
  const elements = new Map(
    [
      'bootstrap-splash',
      'bootstrap-label',
      'bootstrap-error',
      'bootstrap-retry',
      'bootstrap-fallback',
      'spinner',
    ].map((id) => [
      id,
      {
        hidden: false,
        removed: false,
        classList: { add() {} },
        remove() {
          this.removed = true
        },
      },
    ]),
  )
  elements.get('bootstrap-fallback').hidden = true
  elements.get('bootstrap-splash').querySelector = () => elements.get('spinner')
  const window = { addEventListener() {}, removeEventListener() {} }
  const source = html.match(/<script>([\s\S]*?)<\/script>/)[1]
  vm.runInNewContext(source, {
    window,
    document: { getElementById: (id) => elements.get(id) },
    navigator: { language: 'en' },
    localStorage: { getItem: () => null },
    matchMedia: () => ({ matches: true }),
    setTimeout,
    clearTimeout,
  })
  assert.equal(elements.get('bootstrap-fallback').hidden, true)
  context.mock.timers.tick(25000)
  assert.equal(elements.get('bootstrap-fallback').hidden, false)
  assert.equal(elements.get('spinner').hidden, true)
  window.projectBriefBootstrap.complete()
  context.mock.timers.tick(1)
  assert.equal(elements.get('bootstrap-splash').removed, true)
})

test('maskable logo pixels stay inside the safe circle and the brand is large', async () => {
  for (const size of [192, 512]) {
    const png = await readFile(`public/icon-maskable-${size}.png`)
    const chunks = []
    for (let offset = 8; offset < png.length;) {
      const length = png.readUInt32BE(offset)
      if (png.toString('ascii', offset + 4, offset + 8) === 'IDAT')
        chunks.push(png.subarray(offset + 8, offset + 8 + length))
      offset += length + 12
    }
    const rows = inflateSync(Buffer.concat(chunks))
    let minX = size,
      maxX = 0,
      minY = size,
      maxY = 0
    for (let y = 0; y < size; y++) {
      assert.equal(rows[y * (size * 3 + 1)], 0)
      for (let x = 0; x < size; x++) {
        const offset = y * (size * 3 + 1) + 1 + x * 3
        if (rows[offset] === 36 && rows[offset + 1] === 108 && rows[offset + 2] === 67) continue
        assert.ok(
          Math.hypot(x + 0.5 - size / 2, y + 0.5 - size / 2) <= size * 0.4,
          `outside safe zone: ${x},${y}`,
        )
        minX = Math.min(minX, x)
        maxX = Math.max(maxX, x)
        minY = Math.min(minY, y)
        maxY = Math.max(maxY, y)
      }
    }
    assert.ok(maxX - minX >= size * 0.4)
    assert.ok(maxY - minY >= size * 0.4)
  }
})
