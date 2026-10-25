import { allProjectPermissions } from './helpers/projectPermissions.js'
import { test, before, beforeEach, after } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
let server,
  projectStore,
  workspaceStore,
  navStore,
  routerFactory,
  api,
  i18n,
  App,
  TaskView,
  Breadcrumbs,
  Account,
  drawerFactory,
  History,
  demo
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
  const load = (file) => server.ssrLoadModule('/src/' + file)
  projectStore = (await load('stores/project.js')).useProjectStore
  workspaceStore = (await load('stores/taskWorkspace.js')).useTaskWorkspaceStore
  navStore = (await load('stores/adminNavigation.js')).useAdminNavigationStore
  routerFactory = (await load('router/index.js')).createProjectRouter
  api = (await load('api/client.js')).api
  i18n = (await load('i18n/index.js')).i18n
  App = (await load('App.vue')).default
  TaskView = (await load('views/TaskView.vue')).default
  History = (await load('components/HistoryTimeline.vue')).default
  Breadcrumbs = (await load('components/Breadcrumbs.vue')).default
  Account = (await load('components/AdminAccount.vue')).default
  drawerFactory = (await load('utils/adminDrawer.js')).createAdminDrawer
  demo = (await load('data/demo.js')).createDemoData
})
after(async () => server.close())
beforeEach(() => {
  setActivePinia(createPinia())
  i18n.global.locale.value = 'en'
})
const uuid = '11111111-1111-4111-8111-111111111111'
async function fixture(admin = true) {
  const data = demo(),
    calls = []
  const project = {
    ...data.project,
    permissions: { ...allProjectPermissions },
    id: uuid,
    sections: data.sections,
    unreadCommentCount: 3,
  }
  const request = async (path, options) => {
    calls.push({ path, ...options })
    if (path === '/api/admin/me')
      return {
        user: { name: 'Administrator', email: 'admin@example.test', membershipRole: 'owner' },
        workspace: { id: 'workspace', name: 'Workspace' },
      }
    if (path === '/api/admin/workspaces') return { data: [{ id: 'workspace', name: 'Workspace' }] }
    if (path === '/api/admin/projects') return { data: [project] }
    if (path === '/api/client/me')
      return { id: 'client', name: 'PRIVATE_CLIENT_HEADER_NAME', preferredLocale: 'en' }
    if (path.includes('/discussions?')) return { data: [], nextCursor: null, unreadCount: 3 }
    if (path.endsWith('/clients')) return { data: [] }
    if (path.endsWith('/sections')) return { data: data.sections }
    if (path.endsWith('/notifications')) return { events: {}, notificationEmail: null }
    if (path.endsWith('/activity/read')) return { unreadCount: 0 }
    if (path.endsWith('/tasks'))
      return {
        data: admin
          ? data.tasks
          : data.tasks.map(({ developerNotes, estimateHours, price, ...task }) => task),
      }
    if (path.includes('/foreign')) throw { status: 404 }
    return { data: project }
  }
  api.request = request
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
  const path = (id) =>
    admin ? `/admin/projects/${uuid}/brief/task/${id}` : `/project/${uuid}/task/${id}`
  await router.push(path(data.tasks[0].id))
  return { router, data, calls, path }
}
async function html(component, router, props) {
  const oldDocument = globalThis.document
  globalThis.document = { title: '', documentElement: {}, getElementById: () => null }
  try {
    return await renderToString(
      createSSRApp(component, props).use(projectStore()._p).use(router).use(i18n),
    )
  } finally {
    if (oldDocument) globalThis.document = oldDocument
    else delete globalThis.document
  }
}
test('admin detail replaces global sidebar and project header with the sticky back control and closed management drawer', async () => {
  const { router } = await fixture()
  for (const lang of ['en', 'ru']) {
    i18n.global.locale.value = lang
    const output = await html(App, router)
    assert.equal(output.includes('<aside class="admin-sidebar'), false)
    assert.equal(output.includes('class="admin-project-header"'), false)
    assert.ok(output.includes('task-admin-trigger'))
    assert.ok(output.includes('task-header-back'))
    assert.ok(output.includes('task-management-drawer'))
    assert.ok(output.includes(lang === 'ru' ? 'Управление проектом' : 'Project management'))
    assert.equal(output.includes('sidebar-workspace'), false)
    assert.ok(output.includes('task-all-projects'))
    assert.ok(output.includes('task-account'))
    assert.ok(output.includes('discussion-badge'))
    for (const section of [
      'brief',
      'discussions',
      'clients',
      'structure',
      'notifications',
      'settings',
      'export',
    ])
      assert.ok(output.includes(`/admin/projects/${uuid}/${section}`))
    assert.equal((output.match(/class="project-panel-nav"/g) || []).length, 1)
    assert.match(
      output,
      /<dialog(?=[^>]*id="admin-drawer")(?=[^>]*task-management-drawer)(?![^>]*\bopen\b)[^>]*>/,
    )
    assert.equal((output.match(/id="developer-notes"/g) || []).length, 1)
    assert.equal((output.match(/id="comment-text"/g) || []).length, 1)
    assert.equal((output.match(/class="surface history-panel"/g) || []).length, 1)
    assert.ok(output.indexOf('developer-notes') < output.indexOf('comments-panel'))
    assert.ok(output.indexOf('comments-panel') < output.indexOf('history-panel'))
  }
})
test('project root uses the contextual sidebar and a single shared overview heading', async () => {
  const { router } = await fixture()
  await router.push(`/admin/projects/${uuid}/brief`)
  const output = await html(App, router)
  assert.ok(output.includes('<aside class="admin-sidebar"'))
  assert.equal(output.includes('class="admin-project-header"'), false)
  assert.equal((output.match(/class="project-subnav"/g) || []).length, 0)
  assert.ok(output.includes('admin-project-overview'))
  assert.equal((output.match(/<h1\b/g) || []).length, 1)
  assert.ok(output.includes('project-sidebar-content'))
  assert.equal((output.match(/id="project-trash-title"/g) || []).length, 1)
  assert.equal(output.includes('task-admin-trigger'), false)
})
test('client detail has project-scoped navigation, comments and back control with personal menu and without admin chrome or private fields', async () => {
  const { router, data } = await fixture(false)
  const output = await html(App, router)
  assert.ok(output.includes('task-header-back'))
  assert.ok(output.includes('Project tasks'))
  assert.ok(output.includes('comments-panel'))
  assert.equal(output.includes('id="admin-drawer"'), false)
  assert.equal(output.includes('id="admin-drawer"'), false)
  assert.ok(output.includes('client-menu-trigger'))
  assert.equal(output.includes('PRIVATE_CLIENT_HEADER_NAME'), true)
  assert.equal(output.includes('id="developer-notes"'), false)
  assert.equal(output.includes('developer-panel'), false)
  assert.equal(output.includes('/admin/projects/'), false)
  assert.ok(output.includes(`/project/${uuid}/task/${data.tasks[0].id}`))
})
test('current section opens on navigation; other manual accordion states survive and task changes issue no duplicate fetches', async () => {
  const { router, data, calls, path } = await fixture()
  await html(App, router)
  const workspace = workspaceStore()
  assert.equal(workspace.expanded[uuid][data.tasks[0].sectionId], true)
  const other = data.tasks.find((task) => task.sectionId !== data.tasks[0].sectionId)
  workspace.revealSection(uuid, other.sectionId)
  workspace.toggleSection(uuid, other.sectionId)
  const count = calls.length
  await router.push(path(other.id))
  const output = await html(App, router)
  assert.equal(calls.length, count)
  assert.equal(workspace.expanded[uuid][other.sectionId], true)
  assert.equal(workspace.expanded[uuid][data.tasks[0].sectionId], true)
  assert.match(
    output,
    new RegExp(`<a(?=[^>]*href="${path(other.id)}")(?=[^>]*aria-current="page")[^>]*>`),
  )
  workspace.setHidden(true)
  await router.push(path(data.tasks[0].id))
  assert.equal(workspace.hidden, true)
  assert.equal(calls.length, count)
  await router.push(`/admin/projects/foreign/brief/task/guessed`)
  assert.ok(calls.length > count)
  assert.equal(router.currentRoute.value.path, '/admin/unavailable')
})
test('client task switches reuse only their current authorized project snapshot', async () => {
  const { router, data, calls, path } = await fixture(false)
  const count = calls.length
  await router.push(path(data.tasks[1].id))
  assert.equal(calls.length, count)
  projectStore().sessionLost = true
  await router.push(path(data.tasks[0].id))
  assert.ok(calls.length > count)
})
test('notes are collapsed but retain the existing textarea, explicit save and dirty state', async () => {
  const { router, data } = await fixture()
  const store = projectStore()
  store.updateDeveloperData(data.tasks[0].id, { developerNotes: 'Unsaved text is retained' })
  const output = await html(TaskView, router)
  assert.match(output, /<details(?=[^>]*class="notes-disclosure")(?![^>]*\bopen\b)[^>]*>/)
  assert.ok(output.includes('Unsaved text is retained'))
  assert.ok(output.includes('Unsaved changes'))
  assert.ok(output.includes('Save changes'))
  assert.ok(store.hasDeveloperChanges(data.tasks[0].id))
})
test('panel state is exclusive and the desktop hide preference survives safely blocked storage', () => {
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    get() {
      throw new Error('Blocked')
    },
  })
  try {
    const workspace = workspaceStore()
    workspace.setHidden(true)
    workspace.mobile = true
    workspace.openTasks()
    assert.equal(workspace.panel, 'tasks')
    workspace.openAdmin()
    assert.equal(workspace.panel, 'admin')
    workspace.closePanel()
    assert.equal(workspace.panel, null)
    workspace.mobile = false
    workspace.openTasks()
    assert.equal(workspace.hidden, false)
    workspace.revealSection('project', 'section')
    navStore().clear()
    assert.deepEqual(workspace.expanded, {})
    assert.equal(workspace.panel, null)
  } finally {
    if (previous) Object.defineProperty(globalThis, 'localStorage', previous)
    else delete globalThis.localStorage
  }
})
test('client history omits every private audit event while preserving the order of public events', async () => {
  const { router } = await fixture(false)
  const history = [
    {
      id: '1',
      type: 'created',
      actorName: 'PUBLIC_FIRST',
      text: 'PUBLIC_FIRST',
      createdAt: '2026-10-20T08:00:00Z',
    },
    ...['developer', 'estimate', 'price', 'developer_notes'].map((type, i) => ({
      id: String(i + 2),
      type,
      actorName: 'PRIVATE_' + type,
      text: 'PRIVATE_' + type,
      createdAt: '2026-10-20T08:01:00Z',
    })),
    {
      id: '9',
      type: 'comment',
      actorName: 'PUBLIC_LAST',
      text: 'PUBLIC_LAST',
      createdAt: '2026-10-20T08:02:00Z',
    },
  ]
  const output = await html(History, router, { history, developer: false })
  assert.equal(output.includes('PRIVATE_'), false)
  // Same newest-first order as the pre-existing HistoryTimeline.
  assert.ok(output.indexOf('PUBLIC_LAST') < output.indexOf('PUBLIC_FIRST'))
})

test('administrative breadcrumbs use existing project routes, localized names and a non-link current page', async () => {
  const { router, data, path } = await fixture()
  for (const lang of ['en', 'ru']) {
    i18n.global.locale.value = lang
    for (const section of [
      'brief',
      'clients',
      'discussions',
      'structure',
      'notifications',
      'settings',
      'export',
    ]) {
      await router.push(`/admin/projects/${uuid}/${section}`)
      const output = await html(Breadcrumbs, router)
      assert.ok(output.includes('href="/admin"'))
      assert.ok(output.includes(`aria-label="${i18n.global.t('nav.breadcrumbs')}"`))
      assert.ok(output.includes('aria-current="page"'))
      assert.equal(output.replace(/<[^>]*>/g, '').includes(uuid), false)
      assert.equal((output.match(/aria-current="page"/g) || []).length, 1)
      if (section === 'brief') assert.ok(output.includes('>' + data.project.name + '</span>'))
      else {
        assert.ok(output.includes(`href="/admin/projects/${uuid}/brief"`))
        assert.ok(output.includes('>' + i18n.global.t('nav.' + section) + '</span>'))
      }
    }
    for (const [destination, current, links] of [
      [path(data.tasks[0].id) + '/edit', i18n.global.t('flow.editTitle'), 4],
      [`/admin/projects/${uuid}/brief/task/new`, i18n.global.t('ui.newIdea'), 3],
      ['/admin/projects/new', i18n.global.t('nav.newProject'), 1],
    ]) {
      await router.push(destination)
      const output = await html(Breadcrumbs, router)
      assert.equal((output.match(/<a /g) || []).length, links)
      assert.ok(output.includes('>' + current + '</span>'))
      assert.equal(output.replace(/<[^>]*>/g, '').includes(uuid), false)
    }
  }
})
test('breadcrumbs stay absent on task details with a back control, the project list, login and client pages', async () => {
  const { router, data, path } = await fixture()
  for (const destination of [
    path(data.tasks[0].id),
    '/admin',
    '/admin/login',
    `/project/${uuid}`,
  ]) {
    await router.push(destination)
    assert.equal((await html(Breadcrumbs, router)).includes('<nav'), false)
    if (router.currentRoute.value.meta.taskDetail) {
      const output = await html(App, router)
      assert.ok(output.includes('task-header-back'))
      assert.equal(output.includes('class="breadcrumbs"'), false)
    }
  }
})
test('the task logo targets the admin project list or the current client project and precedes the toolbar', async () => {
  for (const admin of [true, false]) {
    const { router } = await fixture(admin)
    const output = await html(App, router)
    const destination = admin ? '/admin' : `/project/${uuid}`
    assert.match(
      output,
      new RegExp(`<a(?=[^>]*href="${destination}")(?=[^>]*task-navigation-brand)[^>]*>`),
    )
    assert.equal((output.match(/id="project-task-navigation"/g) || []).length, 1)
    assert.ok(output.indexOf('task-navigation-brand') < output.indexOf('task-topbar'))
  }
})
test('compact account always uses the signed-in user and actual membership role, with a global account link and separate logout', async () => {
  const { router } = await fixture()
  for (const lang of ['en', 'ru']) {
    i18n.global.locale.value = lang
    for (const role of ['owner', 'admin']) {
      const output = await html(Account, router, {
        user: { name: 'Анна Смирнова', membershipRole: role },
      })
      assert.ok(output.includes('Анна Смирнова'))
      assert.ok(output.includes('АС'))
      assert.ok(output.includes(i18n.global.t('nav.' + role)))
      assert.ok(output.includes('aria-label="' + i18n.global.t('ui.signOut') + '"'))
      assert.ok(output.includes('href="/admin/account"'))
    }
  }
})
test('native drawers retain keyboard focus at either end of their compact controls', () => {
  const focused = [],
    doc = {}
  const controls = ['first', 'last'].map((name) => ({
    getClientRects: () => [{}],
    focus: () => focused.push(name),
  }))
  const element = { showModal() {}, ownerDocument: doc, querySelectorAll: () => controls }
  const drawer = drawerFactory({ dialog: () => element, trigger: () => null })
  drawer.show()
  for (const [index, shiftKey, expected] of [
    [0, true, 'last'],
    [1, false, 'first'],
  ]) {
    doc.activeElement = controls[index]
    let prevented = false
    drawer.keydown({
      key: 'Tab',
      shiftKey,
      preventDefault: () => {
        prevented = true
      },
    })
    assert.equal(prevented, true)
    assert.equal(focused.at(-1), expected)
  }
})
