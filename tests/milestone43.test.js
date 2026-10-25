import { allProjectPermissions } from './helpers/projectPermissions.js'
import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createMemoryHistory } from 'vue-router'
let server,
  projectStore,
  managementStore,
  discussionsStore,
  navStore,
  routerFactory,
  api,
  toast,
  Card,
  i18n
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
  const load = (path) => server.ssrLoadModule('/src/' + path)
  projectStore = (await load('stores/project.js')).useProjectStore
  managementStore = (await load('stores/projectManagement.js')).useProjectManagementStore
  discussionsStore = (await load('stores/discussions.js')).useDiscussionsStore
  navStore = (await load('stores/adminNavigation.js')).useAdminNavigationStore
  routerFactory = (await load('router/index.js')).createProjectRouter
  api = (await load('api/client.js')).api
  toast = (await load('services/toast.js')).toast
  Card = (await load('components/TaskCard.vue')).default
  i18n = (await load('i18n/index.js')).i18n
})
after(async () => {
  toast.clear()
  await server.close()
})
beforeEach(() => {
  setActivePinia(createPinia())
  toast.clear()
})
const task = {
  id: 'task',
  sectionId: 'section',
  title: 'Test idea',
  status: 'new',
  priority: 'high',
  description: 'Text',
  attachments: [],
  comments: [],
  history: [],
}
const deferred = () => {
  let resolve, reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
async function fixture() {
  const request = async (path) => {
    if (path === '/api/admin/me') return { user: { name: 'Admin' }, workspace: { id: 'workspace' } }
    if (path.endsWith('/workspaces') || path === '/api/admin/projects') return { data: [] }
    if (path.endsWith('/tasks')) return { data: [task] }
    if (path.endsWith('/activity/read')) return { unreadCount: 0 }
    if (path.endsWith('/clients') || path.endsWith('/sections')) return { data: [] }
    return {
      data: {
        id: path.includes('second') ? 'second' : 'first',
        name: 'Project',
        sections: [],
        permissions: { ...allProjectPermissions },
      },
    }
  }
  api.request = request
  const router = routerFactory({ history: createMemoryHistory(), request, apiMode: true })
  await router.push('/admin/projects/first/brief')
  return { router, request }
}
test('card financial metadata renders only for admin, handles each field independently and retains zero', async () => {
  const { router } = await fixture()
  const store = projectStore()
  store.project.currency = 'EUR'
  async function render(fields) {
    return renderToString(
      createSSRApp(Card, { task: { ...task, ...fields } })
        .use(store._p)
        .use(router)
        .use(i18n),
    )
  }
  for (const fields of [
    { estimateHours: 5 },
    { price: 12000 },
    { estimateHours: 0, price: 0 },
    {},
  ]) {
    const html = await render(fields)
    assert.equal(html.includes('card-estimate'), Object.hasOwn(fields, 'estimateHours'))
    assert.equal(html.includes('card-price-tooltip'), Object.hasOwn(fields, 'price'))
    if (Object.hasOwn(fields, 'price')) assert.ok(html.includes('€'))
  }
  store.admin = null
  const client = await render({ estimateHours: 5, price: 12000 })
  assert.equal(client.includes('card-estimate'), false)
  assert.equal(client.includes('card-price'), false)
})
test('navigation retains route and page data until ready and recovers from errors without a stuck spinner', async () => {
  const { router, request } = await fixture()
  const store = projectStore(),
    oldProject = store.project,
    oldTasks = store.tasks
  const gate = deferred(),
    entered = deferred()
  api.request = (path) => {
    if (path.includes('second') && !path.endsWith('/tasks')) {
      entered.resolve()
      return gate.promise
    }
    return request(path)
  }
  const navigation = router.push('/admin/projects/second/brief')
  await entered.promise
  assert.equal(router.navigationPending.value, true)
  assert.equal(router.currentRoute.value.path, '/admin/projects/first/brief')
  assert.equal(store.project, oldProject)
  assert.equal(store.tasks, oldTasks)
  gate.reject({ status: 503 })
  await navigation
  assert.equal(router.currentRoute.value.path, '/admin/projects/first/brief')
  assert.equal(store.project, oldProject)
  assert.equal(store.tasks, oldTasks)
  assert.equal(router.navigationPending.value, false)
  api.request = request
  await router.push('/admin/projects/second/brief')
  assert.equal(store.project.id, 'second')
  assert.equal(router.navigationPending.value, false)
})
test('a slower earlier navigation cannot commit over a later destination or clear its spinner', async () => {
  const { router, request } = await fixture()
  const firstGate = deferred(),
    entered = deferred()
  api.request = (path) => {
    if (path.includes('second') && !path.endsWith('/tasks')) {
      entered.resolve()
      return firstGate.promise
    }
    return request(path)
  }
  const first = router.push('/admin/projects/second/brief')
  await entered.promise
  await router.push('/admin/projects/first/export')
  firstGate.resolve({ data: { id: 'second', name: 'Obsolete', sections: [] } })
  await first
  assert.equal(router.currentRoute.value.path, '/admin/projects/first/export')
  assert.equal(managementStore().project.id, 'first')
  assert.equal(projectStore().project.id, 'first')
  assert.equal(router.navigationPending.value, false)
})
test('lazy component failure leaves the previous route and draft intact and releases the overlay', async () => {
  const { router } = await fixture()
  router.addRoute({
    path: '/broken',
    component: () => Promise.reject(new Error('Chunk unavailable')),
  })
  await assert.rejects(router.push('/broken'), /Chunk unavailable/)
  assert.equal(router.currentRoute.value.path, '/admin/projects/first/brief')
  assert.equal(router.navigationPending.value, false)
  assert.equal(projectStore().tasks[0].id, 'task')
})
test('refusing developer draft leave causes no data fetch and clears pending navigation', async () => {
  const { router } = await fixture()
  projectStore().updateDeveloperData('task', { developerNotes: 'Unsaved' })
  let calls = 0
  api.request = async () => {
    calls++
    throw new Error('Must not fetch')
  }
  globalThis.window = { confirm: () => false }
  try {
    await router.push('/admin/projects/second/brief')
    assert.equal(calls, 0)
    assert.equal(router.currentRoute.value.path, '/admin/projects/first/brief')
    assert.equal(projectStore().developerTask('task').developerNotes, 'Unsaved')
    assert.equal(router.navigationPending.value, false)
  } finally {
    delete globalThis.window
  }
})
test('discussions open and paginate without read writes, then explicitly mark selected IDs with a local badge update', async () => {
  navStore().projects = [{ id: 'first', unreadCommentCount: 2 }]
  const calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    if (options?.method === 'POST') return { unreadCount: 1 }
    if (path.includes('cursor='))
      return {
        data: [{ id: 'older', text: 'Older', unread: true }],
        nextCursor: null,
        unreadCount: 2,
      }
    return {
      data: [{ id: path.includes('kind=general') ? 'general' : 'task-comment', unread: true }],
      nextCursor: 'next',
      unreadCount: 2,
    }
  }
  const store = discussionsStore()
  await store.load('first')
  assert.equal(
    calls.some((call) => call.method),
    false,
  )
  await store.more('tasks')
  assert.equal(store.tasks.length, 2)
  await store.mark('first', ['task-comment'])
  assert.deepEqual(calls.at(-1).body, { ids: ['task-comment'] })
  assert.equal(store.tasks[0].unread, false)
  assert.equal(store.tasks[1].unread, true)
  assert.equal(store.general[0].unread, true)
  assert.equal(navStore().projects[0].unreadCommentCount, 1)
  store.clear()
  assert.equal(store.tasks.length, 0)
  assert.equal(store.general.length, 0)
})

test('discussion loading waits for an in-flight read so stale responses cannot resurrect unread badges', async () => {
  const gate = deferred(),
    calls = []
  api.request = async (path, options) => {
    calls.push(path)
    if (options?.method === 'POST') {
      await gate.promise
      return { unreadCount: 0 }
    }
    return { data: [], nextCursor: null, unreadCount: 0 }
  }
  const store = discussionsStore()
  const mark = store.mark('first', ['comment'])
  await new Promise((resolve) => setImmediate(resolve))
  const load = store.load('first')
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(calls.length, 1)
  gate.resolve()
  await Promise.all([mark, load])
  assert.equal(calls.length, 3)
  assert.equal(store.unreadCount, 0)
})

test('returning to the current route cancels an outstanding transition without waiting for its response', async () => {
  const { router, request } = await fixture()
  const gate = deferred(),
    entered = deferred()
  api.request = (path) => {
    if (path.includes('second') && !path.endsWith('/tasks')) {
      entered.resolve()
      return gate.promise
    }
    return request(path)
  }
  const navigation = router.push('/admin/projects/second/brief')
  await entered.promise
  await router.push('/admin/projects/first/brief')
  assert.equal(router.navigationPending.value, false)
  gate.resolve({ data: { id: 'second', name: 'Canceled', sections: [] } })
  await navigation
  assert.equal(projectStore().project.id, 'first')
  assert.equal(router.currentRoute.value.path, '/admin/projects/first/brief')
})
