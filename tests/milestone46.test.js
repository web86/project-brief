import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp, createRenderer, ssrContextKey } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createDemoData } from '../src/data/demo.js'
let server,
  api,
  toast,
  i18n,
  projectStore,
  navStore,
  createProjectRouter,
  ProjectSettings,
  PersonalSettings,
  Status,
  Card,
  Panel,
  ClientNav
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    mode: 'test',
    logLevel: 'error',
  })
  const load = (file) => server.ssrLoadModule('/src/' + file)
  api = (await load('api/client.js')).api
  toast = (await load('services/toast.js')).toast
  i18n = (await load('i18n/index.js')).i18n
  projectStore = (await load('stores/project.js')).useProjectStore
  navStore = (await load('stores/adminNavigation.js')).useAdminNavigationStore
  createProjectRouter = (await load('router/index.js')).createProjectRouter
  ;[ProjectSettings, PersonalSettings, Status, Card, Panel, ClientNav] = await Promise.all(
    [
      'components/ProjectNotificationSettings.vue',
      'views/AdminNotificationsView.vue',
      'components/TaskStatus.vue',
      'components/TaskCard.vue',
      'components/DeveloperTaskPanel.vue',
      'components/ClientPageNavigation.vue',
    ].map(async (file) => (await load(file)).default),
  )
})
after(async () => {
  toast?.clear()
  await server?.close()
})
beforeEach(() => {
  setActivePinia(createPinia())
  i18n.global.locale.value = 'en'
  toast.clear()
})
const events = [
  'client.task_created',
  'client.comment_created',
  'client.task_approved',
  'admin.clarification_requested',
  'admin.comment_created',
  'admin.task_review',
  'admin.task_done',
  'client.project_comment_created',
  'admin.project_comment_created',
]
const account = () => ({
  user: { name: 'Alex', email: 'alex@example.test', preferredLocale: 'en' },
  workspaces: [{ id: 'one', name: 'One' }],
  preferences: { email: true, push: true, telegram: true },
  email: 'alex@example.test',
  accountEmail: 'alex@example.test',
  emailAvailable: true,
  pendingEmail: null,
  emailUnified: false,
  legacyEmails: [],
})
const settings = () => ({
  deliveryMode: 'legacy',
  events: Object.fromEntries(
    events.map((event) => [event, { email: true, push: false, telegram: false }]),
  ),
  recipientOptions: { team: [{ id: 1, name: 'Alex' }], clients: [{ id: 'client', name: 'Anna' }] },
})
async function router() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:path(.*)', component: { render: () => null } }],
  })
  await router.push('/admin/settings/notifications')
  return router
}
async function render(component, props = {}) {
  return renderToString(
    createSSRApp(component, props)
      .use(projectStore()._p)
      .use(await router())
      .use(i18n),
  )
}
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
async function mount(component, route) {
  const app = renderer
    .createApp({ ...component, render: () => null })
    .use(projectStore()._p)
    .use(route || (await router()))
    .use(i18n)
    .provide(ssrContextKey, {})
  const instance = app.mount({})
  await settle()
  return { app, setup: instance.$.setupState }
}
async function settle() {
  for (let i = 0; i < 3; i++) await new Promise((resolve) => setImmediate(resolve))
}

test('project settings render events and recipients with explicit migration consent, without personal channel controls', async () => {
  const html = await render(ProjectSettings, { projectId: 'project', initialSettings: settings() })
  assert.equal((html.match(/class="notification-event-row"/g) || []).length, 9)
  assert.match(html, /Previous delivery rules are still active/)
  assert.match(html, /Confirm switch and save/)
  assert.match(html, /href="\/admin\/settings\/notifications"/)
  assert.doesNotMatch(
    html,
    /id="notification-email"|push-trigger|telegram-settings|Test Email|Test Push/,
  )
})
test('project settings selected recipients use project-scoped candidates and preserve off events in previews', async () => {
  const value = settings()
  value.eventRules = Object.fromEntries(
    events.map((event) => [event, { enabled: false, recipients: { mode: 'selected', ids: [] } }]),
  )
  const html = await render(ProjectSettings, { projectId: 'project', initialSettings: value })
  assert.match(html, /Anna/)
  assert.match(html, /Alex/)
  assert.match(html, /Nobody selected/)
  assert.equal((html.match(/<select[^>]*disabled/g) || []).length, 9)
})
test('status dots occur only for in_progress and approved has a different green treatment from done', async () => {
  const active = await render(Status, { status: 'in_progress' }),
    approved = await render(Status, { status: 'approved' }),
    done = await render(Status, { status: 'done' })
  assert.match(active, /status-progress-dots.*aria-hidden="true"/)
  assert.equal((active.match(/<span>\.<\/span>/g) || []).length, 3)
  assert.doesNotMatch(approved, /status-progress-dots|tone-violet/)
  assert.match(approved, /tone-approved/)
  assert.match(done, /tone-green/)
})
test('developer card omits arrow while client keeps it, and task management retains numbers and approval only', async () => {
  const store = projectStore(),
    data = createDemoData()
  store.project = { ...data.project, sections: data.sections }
  store.tasks = data.tasks
  store.admin = { name: 'Alex' }
  assert.doesNotMatch(await render(Card, { task: data.tasks[0] }), /card-arrow/)
  const panel = await render(Panel, { task: data.tasks[0] })
  assert.doesNotMatch(panel, /task-brief-section|order-actions|structurePending/)
  assert.match(panel, /id="developer-status"/)
  assert.match(panel, /id="estimate"/)
  assert.match(panel, /id="price"/)
  assert.match(panel, /developer-approval/)
  store.admin = null
  store.currentClient = { id: 'anna' }
  assert.match(await render(Card, { task: data.tasks[0] }), /card-arrow/)
})
test('personal page loads once and tests channels through the personal endpoint with current device only', async () => {
  const original = api.request,
    oldNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator'),
    calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    return path.endsWith('/test') ? { status: 'sent' } : account()
  }
  Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {
      serviceWorker: {
        getRegistration: async () => ({
          pushManager: {
            getSubscription: async () => ({
              endpoint: 'https://fcm.googleapis.com/send/own-device',
            }),
          },
        }),
      },
    },
  })
  const { app, setup } = await mount(PersonalSettings)
  try {
    await setup.test('email')
    assert.equal(toast.items.value.at(-1).message(), 'Test email sent to your primary address.')
    await setup.test('push')
    assert.equal(calls.at(-1).path, '/api/admin/notifications/test')
    assert.equal(calls.at(-1).body.endpoint, 'https://fcm.googleapis.com/send/own-device')
    assert.equal(
      calls.some((call) => call.path.includes('/projects/')),
      false,
    )
  } finally {
    app.unmount()
    api.request = original
    if (oldNavigator) Object.defineProperty(globalThis, 'navigator', oldNavigator)
    else delete globalThis.navigator
  }
})
test('personal channel failures roll back the toggle and address changes require verification response', async () => {
  const original = api.request
  let failure = false
  const state = account(),
    calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    if (failure) throw { code: 'email_rate_limited' }
    if (options?.body?.email) return { ...state, pendingEmail: options.body.email }
    return structuredClone(state)
  }
  const { app, setup } = await mount(PersonalSettings)
  try {
    failure = true
    await setup.toggle('telegram', { target: { checked: false } })
    assert.equal(setup.settings.preferences.telegram, true)
    assert.match(setup.error, /minute/)
    failure = false
    setup.email = 'new@example.test'
    await setup.saveEmail()
    assert.equal(setup.settings.email, 'alex@example.test')
    assert.equal(setup.settings.pendingEmail, 'new@example.test')
    assert.equal(calls.at(-1).body.email, 'new@example.test')
  } finally {
    app.unmount()
    api.request = original
  }
})
test('email proof is read from the fragment, removed from navigation and posted only to own verification API', async () => {
  const original = api.request,
    calls = [],
    route = await router()
  const token = 'a'.repeat(64)
  await route.push('/admin/settings/notifications#verify=' + token)
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    return account()
  }
  const { app } = await mount(PersonalSettings, route)
  try {
    assert.equal(route.currentRoute.value.hash, '')
    assert.equal(
      calls.some((call) => call.path.includes(token)),
      false,
    )
    assert.deepEqual(calls.find((call) => call.path.endsWith('/email/verify')).body, { token })
  } finally {
    app.unmount()
    api.request = original
  }
})
test('personal routing bootstraps without project or workspace selection and keeps verification hash through login', async () => {
  const requests = []
  const router = createProjectRouter({
    history: createMemoryHistory(),
    apiMode: true,
    request: async (path) => {
      requests.push(path)
      assert.equal(path, '/api/admin/notifications')
      return account()
    },
  })
  await router.push('/admin/settings/notifications')
  assert.equal(router.currentRoute.value.path, '/admin/settings/notifications')
  assert.deepEqual(requests, ['/api/admin/notifications'])
  assert.equal(navStore().user.email, 'alex@example.test')
  const rejected = createProjectRouter({
    history: createMemoryHistory(),
    apiMode: true,
    request: async () => {
      throw { status: 401 }
    },
  })
  await rejected.push('/admin/settings/notifications#verify=' + 'b'.repeat(64))
  assert.equal(rejected.currentRoute.value.path, '/admin/login')
  assert.equal(rejected.currentRoute.value.hash, '#verify=' + 'b'.repeat(64))
})

test('shared client navigation renders current project links, active page, unread count and deterministic back link', async () => {
  const store = projectStore()
  store.admin = null
  store.currentClient = { id: 'anna' }
  store.project = { ...store.project, id: 'project-one', unreadCommentCount: 3 }
  const current = await router()
  await current.push('/project/project-one/discussions')
  const html = await renderToString(createSSRApp(ClientNav).use(store._p).use(current).use(i18n))
  for (const section of ['discussions', 'notifications', 'export'])
    assert.match(html, new RegExp('href="/project/project-one/' + section + '"'))
  assert.match(html, /aria-current="page"/)
  assert.match(html, /Back to ideas/)
  assert.match(html, /unread-badge/)
  assert.doesNotMatch(html, /\/admin/)
})

test('removed recipients cannot remain hidden selections that prevent saving', async () => {
  const state = settings()
  state.eventRules = Object.fromEntries(
    events.map((event) => [
      event,
      {
        enabled: true,
        recipients: {
          mode: 'selected',
          ids: event.startsWith('client.') ? [99] : ['removed-client'],
        },
      },
    ]),
  )
  const html = await render(ProjectSettings, { projectId: 'project', initialSettings: state })
  assert.match(html, /no longer have access/)
  assert.match(html, /Nobody selected/)
  assert.doesNotMatch(html, /value="99"|value="removed-client"/)
})
