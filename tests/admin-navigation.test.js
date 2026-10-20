import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { createSSRApp, createRenderer, ssrContextKey, nextTick } from 'vue'
import { renderToString } from '@vue/server-renderer'
let server,
  navStore,
  projectStore,
  managementStore,
  api,
  toast,
  routerFactory,
  i18n,
  Shell,
  Sidebar,
  ProjectShell,
  App,
  drawerFactory
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  navStore = (await server.ssrLoadModule('/src/stores/adminNavigation.js')).useAdminNavigationStore
  projectStore = (await server.ssrLoadModule('/src/stores/project.js')).useProjectStore
  managementStore = (await server.ssrLoadModule('/src/stores/projectManagement.js'))
    .useProjectManagementStore
  api = (await server.ssrLoadModule('/src/api/client.js')).api
  toast = (await server.ssrLoadModule('/src/services/toast.js')).toast
  routerFactory = (await server.ssrLoadModule('/src/router/index.js')).createProjectRouter
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
  Shell = (await server.ssrLoadModule('/src/views/AdminShell.vue')).default
  Sidebar = (await server.ssrLoadModule('/src/components/AdminSidebar.vue')).default
  ProjectShell = (await server.ssrLoadModule('/src/views/AdminProjectShell.vue')).default
  App = (await server.ssrLoadModule('/src/App.vue')).default
  drawerFactory = (await server.ssrLoadModule('/src/utils/adminDrawer.js')).createAdminDrawer
})
after(async () => {
  toast?.clear()
  await server?.close()
})
beforeEach(() => {
  setActivePinia(createPinia())
  toast.clear()
})
test('native language select uses the shared native-name catalog and persists only the current client locale', async () => {
  const { default: Switcher } = await server.ssrLoadModule('/src/components/LanguageSwitcher.vue')
  const localization = await server.ssrLoadModule('/src/i18n/index.js')
  const previousStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
  const previousRequest = api.request
  const values = new Map()
  const calls = []
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => values.get(key) ?? null,
      setItem: (key, value) => values.set(key, value),
    },
  })
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    return { preferredLocale: options.body.locale }
  }
  try {
    const store = projectStore()
    store.currentClient = { id: 'current-client', preferredLocale: 'ru' }
    const snapshot = JSON.stringify({ project: store.project, tasks: store.tasks })
    for (const language of ['ru', 'en']) {
      localization.setManualLocale(language)
      const html = await renderToString(createSSRApp(Switcher).use(createPinia()).use(i18n))
      assert.match(html, /<select\b/)
      assert.match(html, /value="ru" lang="ru">Русский<\/option>/)
      assert.match(html, /value="en" lang="en">English<\/option>/)
      assert.equal(html.includes('<button'), false)
    }
    await Promise.all([store.selectLocale('en'), store.selectLocale('ru')])
    assert.deepEqual(
      calls.map(({ path, method, body }) => ({ path, method, body })),
      [
        { path: '/api/client/me/locale', method: 'PATCH', body: { locale: 'en' } },
        { path: '/api/client/me/locale', method: 'PATCH', body: { locale: 'ru' } },
      ],
    )
    assert.equal(store.currentClient.preferredLocale, 'ru')
    assert.equal(values.get('projectbrief.locale'), 'ru')
    assert.equal(JSON.stringify({ project: store.project, tasks: store.tasks }), snapshot)
    await store.selectLocale('de')
    assert.equal(calls.length, 2)
    store.admin = { name: 'Admin' }
    await store.selectLocale('en')
    assert.equal(calls.length, 3)
    assert.equal(calls[2].path, '/api/admin/me/locale')
    assert.equal(store.currentClient.preferredLocale, 'ru')
    assert.equal(values.get('projectbrief.locale'), 'en')
  } finally {
    api.request = previousRequest
    if (previousStorage) Object.defineProperty(globalThis, 'localStorage', previousStorage)
    else delete globalThis.localStorage
  }
})
const uuid = '11111111-1111-4111-8111-111111111111'
const task = {
  id: 'task',
  title: 'Task',
  status: 'new',
  estimateHours: 2,
  price: null,
  developerNotes: 'Saved',
  history: [],
  comments: [],
  attachments: [],
}
function backend() {
  let selected = 'alpha'
  const calls = []
  const request = async (path, options = {}) => {
    calls.push({ path, ...options })
    if (path.includes('/workspaces/') && path.endsWith('/select')) {
      selected = path.split('/')[4]
      return { data: { id: selected } }
    }
    if (path === '/api/admin/me')
      return {
        user: {
          name: 'Ko',
          email: 'ko@example.test',
          membershipRole: selected === 'alpha' ? 'owner' : 'admin',
        },
        workspace: { id: selected, name: selected },
      }
    if (path === '/api/admin/workspaces')
      return {
        data: [
          { id: 'alpha', name: 'Alpha' },
          { id: 'beta', name: 'Beta' },
        ],
      }
    if (path === '/api/admin/projects' && options.method === 'POST')
      return { data: { id: uuid, name: 'Created project', status: 'active', unreadCount: 0 } }
    if (path === '/api/admin/projects')
      return {
        data: [
          { id: uuid, name: selected + ' project', unreadCount: 3, status: 'active' },
          { id: 'clean', name: 'Clean project', unreadCount: 0 },
        ],
      }
    if (path.endsWith('/activity/read')) return { unreadCount: 0 }
    if (path.endsWith('/tasks')) return { data: [task] }
    if (path.endsWith('/clients')) return { data: [] }
    if (path.endsWith('/sections')) return { data: [] }
    return {
      data: {
        id: uuid,
        name: selected + ' project',
        website: '',
        currency: 'RUB',
        sections: [],
        status: 'active',
      },
    }
  }
  api.request = request
  return { calls, request }
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

test('workspace startup loads me/workspaces/projects once in parallel and renders localized shell navigation', async () => {
  const { calls, request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push('/admin')
  const nav = navStore()
  assert.equal(nav.ready, true)
  assert.deepEqual(
    calls.map(({ path }) => path),
    ['/api/admin/me', '/api/admin/workspaces', '/api/admin/projects'],
  )
  for (const [lang, labels] of [
    ['en', ['Workspace', 'New project', 'All projects', 'Owner', 'Open navigation menu']],
    [
      'ru',
      ['Рабочее пространство', 'Новый проект', 'Все проекты', 'Владелец', 'Открыть меню навигации'],
    ],
  ]) {
    i18n.global.locale.value = lang
    const html = await renderToString(createSSRApp(Shell).use(nav._p).use(router).use(i18n))
    for (const label of labels) assert.ok(html.includes(label), label)
    assert.ok(html.includes('alpha project'))
    assert.ok(html.includes('aria-expanded="false"'))
    assert.ok(html.includes('<dialog'))
    assert.ok(html.includes('aria-controls="admin-drawer"'))
    assert.ok(
      html.includes('3 new client activities') || html.includes('3 новых события от клиента'),
    )
  }
})

test('sidebar active project and unread badge use accessible labels; zero badges remain hidden', async () => {
  const { request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}/settings`)
  i18n.global.locale.value = 'en'
  const nav = navStore()
  const html = await renderToString(createSSRApp(Sidebar).use(nav._p).use(router).use(i18n))
  assert.ok(html.includes('class="sidebar-project active'))
  assert.ok(html.includes('aria-current="page"'))
  assert.equal((html.match(/class="unread-badge"/g) || []).length, 1)
  assert.ok(html.includes('aria-label="3 new client activities"'))
})

test('project subnavigation has dedicated routes and legacy URL redirects without hash', async () => {
  const { request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}`)
  assert.equal(router.currentRoute.value.path, `/admin/projects/${uuid}/settings`)
  await router.push(`/admin/projects/${uuid}#client-access`)
  assert.equal(router.currentRoute.value.path, `/admin/projects/${uuid}/clients`)
  assert.equal(router.currentRoute.value.hash, '')
  const nav = navStore()
  for (const [lang, labels] of [
    ['en', ['Ideas', 'Clients', 'Structure', 'Notifications', 'Settings']],
    ['ru', ['Идеи', 'Клиенты', 'Структура', 'Уведомления', 'Настройки']],
  ]) {
    i18n.global.locale.value = lang
    const html = await renderToString(createSSRApp(ProjectShell).use(nav._p).use(router).use(i18n))
    for (const label of labels) assert.ok(html.includes(label))
    for (const suffix of ['brief', 'clients', 'structure', 'notifications', 'settings'])
      assert.ok(html.includes(`/admin/projects/${uuid}/${suffix}`))
    assert.ok(html.includes('aria-current="page"'))
  }
})

test('each project subpage loads only its resource and never marks activity as read', async () => {
  for (const section of ['settings', 'clients', 'structure', 'notifications']) {
    setActivePinia(createPinia())
    const { request, calls } = backend()
    const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
    await router.push(`/admin/projects/${uuid}/${section}`)
    assert.equal(
      calls.some(({ path }) => path.endsWith('/activity/read')),
      false,
    )
    assert.equal(
      calls.some(({ path }) => path.endsWith('/tasks')),
      false,
    )
    assert.equal(
      calls.some(({ path }) => path.endsWith('/clients')),
      section === 'clients',
    )
    assert.equal(
      calls.some(({ path }) => path.endsWith('/sections')),
      section === 'structure',
    )
    assert.ok(calls.some(({ path }) => path.endsWith('?summary=1')))
  }
})

test('successful brief/task loading precedes explicit read POST and clears the sidebar badge locally', async () => {
  const { request, calls } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}/brief`)
  const read = calls.findIndex(({ path }) => path.endsWith('/activity/read'))
  assert.ok(read > calls.findIndex(({ path }) => path.endsWith('/tasks')))
  assert.equal(calls[read].method, 'POST')
  assert.equal(navStore().projects[0].unreadCount, 0)
  assert.equal(calls.filter(({ path }) => path === '/api/admin/projects').length, 1)
  await router.push(`/admin/projects/${uuid}/brief/task/task`)
  assert.equal(calls.filter(({ path }) => path.endsWith('/activity/read')).length, 1)
  assert.equal(calls.filter(({ path }) => path.endsWith('/tasks')).length, 1)
})

test('failed/unauthorized brief load never marks read or switches workspace implicitly', async () => {
  const { request, calls } = backend()
  const actual = api.request
  api.request = async (path, options) => {
    if (path.endsWith('/tasks')) throw { status: 404, message: 'Denied' }
    return actual(path, options)
  }
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}/brief`)
  assert.equal(
    calls.some(({ path }) => path.endsWith('/activity/read')),
    false,
  )
  assert.equal(
    calls.some(({ path }) => path.endsWith('/select')),
    false,
  )
  assert.equal(router.currentRoute.value.path, '/admin/unavailable')
})

test('workspace switch confirms dirty draft, clears all previous data before POST, then refreshes and routes to dashboard', async () => {
  const { request, calls } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}/brief/task/task`)
  const nav = navStore(),
    project = projectStore(),
    management = managementStore()
  project.updateDeveloperData('task', { developerNotes: 'Draft' })
  management.clients = [{ name: 'Old client' }]
  management.sections = [{ name: 'Old section' }]
  const before = calls.length
  assert.equal(await nav.switchWorkspace('beta', router, () => false), false)
  assert.equal(project.developerTask('task').developerNotes, 'Draft')
  assert.equal(calls.length, before)
  const actual = api.request
  api.request = async (path, options) => {
    if (path.endsWith('/select')) {
      assert.equal(project.project.id, '')
      assert.deepEqual(project.tasks, [])
      assert.deepEqual(project.developerDrafts, {})
      assert.equal(management.project, null)
      assert.deepEqual(management.clients, [])
      assert.deepEqual(management.sections, [])
      assert.deepEqual(nav.projects, [])
      assert.equal(nav.workspace, null)
    }
    return actual(path, options)
  }
  assert.equal(await nav.switchWorkspace('beta', router, () => true), true)
  assert.equal(nav.workspace.id, 'beta')
  assert.equal(nav.user.membershipRole, 'admin')
  assert.equal(nav.projects[0].name, 'beta project')
  assert.equal(router.currentRoute.value.path, '/admin')
  assert.equal(
    calls.some(({ method }) => method === 'PATCH'),
    false,
  )
  assert.equal(toast.items.value.at(-1).type, 'success')
})

test('workspace failure shows error toast and resolves actual server context without restoring stale task data', async () => {
  const { request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}/brief`)
  const actual = api.request
  api.request = async (path, options) => {
    if (path.endsWith('/select')) throw { status: 0, message: 'Offline' }
    return actual(path, options)
  }
  assert.equal(await navStore().switchWorkspace('beta', router), false)
  assert.equal(navStore().workspace.id, 'alpha')
  assert.equal(projectStore().project.id, '')
  assert.deepEqual(projectStore().tasks, [])
  assert.equal(managementStore().project, null)
  assert.equal(toast.items.value[0].type, 'error')
})

test('late old-project responses cannot repopulate data after workspace clear', async () => {
  backend()
  const project = projectStore()
  project.admin = { email: 'ko@example.test' }
  let resolve
  api.request = async (path) =>
    path.endsWith('/tasks')
      ? new Promise((done) => {
          resolve = done
        })
      : { data: { id: uuid, name: 'Old', sections: [] } }
  const loading = project.loadApiProject(uuid, true, { skipAdmin: true })
  await new Promise((done) => setImmediate(done))
  navStore().clear()
  resolve({ data: [task] })
  assert.equal(await loading, false)
  assert.deepEqual(project.tasks, [])
  assert.equal(project.project.id, '')
})

test('client and login pages remain outside authenticated shell', async () => {
  const { request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push('/admin/login')
  assert.equal(router.currentRoute.value.meta.adminShell, undefined)
  // Render the actual App on the client route using already loaded client state.
  await router.push('/project/' + uuid)
  assert.equal(router.currentRoute.value.meta.adminShell, undefined)
  const project = projectStore()
  project.admin = null
  project.currentClient = { id: 'client', name: 'Client' }
  globalThis.document = { title: '', documentElement: {}, getElementById: () => null }
  try {
    const html = await renderToString(createSSRApp(App).use(project._p).use(router).use(i18n))
    assert.equal(html.includes('admin-sidebar'), false)
    assert.equal(html.includes('sidebar-workspace'), false)
  } finally {
    delete globalThis.document
  }
})

test('authenticated shells render one Push control and its mobile footer outlet', async () => {
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'document')
  globalThis.document = { title: '', documentElement: {}, getElementById: () => null }
  try {
    const { request } = backend()
    const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
    await router.push('/admin')
    const nav = navStore()
    const admin = await renderToString(createSSRApp(App).use(nav._p).use(router).use(i18n))
    assert.equal((admin.match(/class="text-button push-trigger"/g) || []).length, 1)
    assert.match(admin, /id="admin-push-footer"/)
    assert.ok(admin.indexOf('id="admin-push-footer"') > admin.indexOf('</main>'))

    await router.push('/admin/login')
    const store = projectStore()
    store.admin = null
    store.currentClient = null
    const guest = await renderToString(createSSRApp(App).use(nav._p).use(router).use(i18n))
    assert.equal(guest.includes('push-trigger'), false)
    assert.equal(guest.includes('id="client-push-footer"'), false)
    store.currentClient = { id: 'client', name: 'Client' }
    const client = await renderToString(createSSRApp(App).use(nav._p).use(router).use(i18n))
    assert.equal((client.match(/class="text-button push-trigger"/g) || []).length, 1)
    assert.match(client, /id="client-push-footer"/)
    assert.ok(client.indexOf('id="client-push-footer"') > client.indexOf('</main>'))
  } finally {
    if (previous) Object.defineProperty(globalThis, 'document', previous)
    else delete globalThis.document
  }
})

test('mobile native drawer opens, closes on Escape/backdrop and restores trigger focus; route change closes it', async () => {
  const actions = [],
    dialog = { showModal: () => actions.push('open'), close: () => actions.push('close') },
    trigger = { focus: () => actions.push('focus') }
  const drawer = drawerFactory({ dialog: () => dialog, trigger: () => trigger })
  drawer.show()
  assert.equal(drawer.open.value, true)
  let prevented = false
  drawer.keydown({
    key: 'Escape',
    preventDefault: () => {
      prevented = true
    },
  })
  assert.equal(drawer.open.value, false)
  assert.equal(prevented, true)
  assert.deepEqual(actions, ['open', 'close', 'focus'])
  drawer.show()
  drawer.backdrop({ target: dialog })
  assert.equal(drawer.open.value, false)
  const { request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push('/admin')
  globalThis.window = {
    matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
  }
  const nav = navStore()
  const app = renderer
    .createApp({ ...Shell, render: () => null })
    .use(nav._p)
    .use(router)
    .use(i18n)
    .provide(ssrContextKey, {})
  try {
    const instance = app.mount({})
    instance.$.setupState.drawer.show()
    assert.equal(instance.$.setupState.drawerOpen, true)
    await router.push('/admin/projects/new')
    await nextTick()
    assert.equal(instance.$.setupState.drawerOpen, false)
  } finally {
    app.unmount()
    delete globalThis.window
  }
})

test('read failure retains unread badge and gives global toast without replaying the mutation', async () => {
  const { request, calls } = backend()
  const nav = navStore()
  await nav.bootstrap(request)
  const failed = async (path, options) => {
    calls.push({ path, ...options })
    throw { status: 0, message: 'Offline' }
  }
  await nav.markRead(uuid, failed)
  assert.equal(nav.projects[0].unreadCount, 3)
  assert.equal(calls.filter(({ path }) => path.endsWith('/activity/read')).length, 1)
  assert.equal(toast.items.value[0].type, 'error')
})

test('server selection that succeeded but lost its response is recovered from authoritative context', async () => {
  const { request } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push('/admin')
  api.request = async (path, options) => {
    const result = await request(path, options)
    if (path.endsWith('/select')) throw { status: 0, message: 'Lost response' }
    return result
  }
  assert.equal(await navStore().switchWorkspace('beta', router), false)
  assert.equal(navStore().workspace.id, 'beta')
  assert.equal(navStore().projects[0].name, 'beta project')
  assert.equal(toast.items.value[0].type, 'error')
})

test('settings saves request summary only; common header cache is reused between sibling routes', async () => {
  const { request, calls } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push(`/admin/projects/${uuid}/settings`)
  await managementStore().saveProject({ title: 'Renamed' })
  await router.push(`/admin/projects/${uuid}/clients`)
  await router.push(`/admin/projects/${uuid}/structure`)
  assert.equal(calls.filter(({ path, method }) => path.endsWith('?summary=1') && !method).length, 1)
  assert.ok(calls.some(({ path, method }) => path.endsWith('?summary=1') && method === 'PATCH'))
  assert.equal(
    calls.some(({ path }) => path.endsWith('/activity/read')),
    false,
  )
})

test('new project refreshes sidebar and navigates directly to its work context', async () => {
  const { request, calls } = backend()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  await router.push('/admin/projects/new')
  const Form = (await server.ssrLoadModule('/src/views/AdminProjectFormView.vue')).default
  const nav = navStore()
  const app = renderer
    .createApp({ ...Form, render: () => null })
    .use(nav._p)
    .use(router)
    .use(i18n)
    .provide(ssrContextKey, {})
  try {
    const instance = app.mount({})
    await instance.$.setupState.create()
    assert.equal(router.currentRoute.value.path, `/admin/projects/${uuid}/brief`)
    assert.ok(calls.some(({ path, method }) => path === '/api/admin/projects' && method === 'POST'))
    assert.ok(calls.filter(({ path }) => path === '/api/admin/projects').length >= 2)
  } finally {
    app.unmount()
  }
})

test('an older list response cannot resurrect the badge after marking a project seen', async () => {
  const { request } = backend()
  const nav = navStore()
  await nav.bootstrap(request)
  let resolve
  const refresh = nav.refreshProjects(
    async () =>
      new Promise((done) => {
        resolve = done
      }),
  )
  await nav.markRead(uuid, request)
  resolve({ data: [{ id: uuid, unreadCount: 3 }] })
  await refresh
  assert.equal(nav.projects[0].unreadCount, 0)
  assert.equal(nav.refreshing, false)
})
