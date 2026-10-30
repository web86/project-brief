import { before, after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createMemoryHistory } from 'vue-router'
let server, api, adminStore, clientStore, navStore, projectStore, routerFactory, View, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  const load = (name) => server.ssrLoadModule('/src/' + name)
  api = (await load('api/client.js')).api
  const stores = await load('stores/discussions.js')
  adminStore = stores.useDiscussionsStore
  clientStore = stores.useClientDiscussionsStore
  navStore = (await load('stores/adminNavigation.js')).useAdminNavigationStore
  projectStore = (await load('stores/project.js')).useProjectStore
  routerFactory = (await load('router/index.js')).createProjectRouter
  View = (await load('views/AdminProjectDiscussionsView.vue')).default
  i18n = (await load('i18n/index.js')).i18n
})
after(() => server.close())
beforeEach(() => setActivePinia(createPinia()))
const deferred = () => {
  let resolve
  const promise = new Promise((yes) => {
    resolve = yes
  })
  return { promise, resolve }
}
const item = (id) => ({
  id,
  text: id,
  authorName: 'Writer',
  createdAt: '2026-10-25T10:00:00Z',
  unread: true,
})
const response = (kind, counts = { general: 31, tasks: 42 }) => ({
  kind,
  data: [item(kind)],
  nextCursor: kind + '-page2',
  unreadCount: counts.general + counts.tasks,
  unreadCounts: { ...counts },
})
for (const persona of ['admin', 'client']) {
  test(`${persona}: server counts cover unloaded rows, mark-all is scoped, and the sidebar stays synchronized`, async () => {
    const store = persona === 'admin' ? adminStore() : clientStore()
    navStore().projects = [{ id: 'project' }]
    projectStore().project = { id: 'project' }
    const calls = []
    api.request = async (path, options) => {
      calls.push({ path, ...options })
      if (options?.method === 'POST')
        return { unreadCount: 42, unreadCounts: { general: 0, tasks: 42 } }
      return response(path.includes('kind=general') ? 'general' : 'tasks')
    }
    await store.load('project')
    assert.deepEqual(store.unreadCounts, { general: 31, tasks: 42 })
    assert.equal(store.general.length, 1)
    assert.equal(calls.length, 2)
    await store.mark('project', [], true, 'general')
    assert.deepEqual(calls.at(-1).body, { all: true, kind: 'general' })
    assert.equal(store.general[0].unread, false)
    assert.equal(store.tasks[0].unread, true)
    assert.deepEqual(store.unreadCounts, { general: 0, tasks: 42 })
    assert.equal(
      persona === 'admin'
        ? navStore().projects[0].unreadCommentCount
        : projectStore().project.unreadCommentCount,
      42,
    )
    assert.ok(calls.every((call) => call.path.startsWith('/api/' + persona + '/')))
    const before = calls.length
    const commit = await store.load('project', { defer: true, reuse: true })
    assert.equal(commit(), true)
    assert.equal(calls.length, before)
  })
}
test('independent pagination deduplicates requests and discards a page older than a read mutation', async () => {
  const store = adminStore(),
    gate = deferred(),
    calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    if (options?.method === 'POST')
      return { unreadCount: 31, unreadCounts: { general: 31, tasks: 0 } }
    if (path.includes('cursor=')) {
      await gate.promise
      return { ...response('tasks'), data: [item('old-page')] }
    }
    return response(path.includes('kind=general') ? 'general' : 'tasks')
  }
  await store.load('project')
  const page = store.more('tasks')
  const duplicate = store.more('tasks')
  await new Promise((resolve) => setImmediate(resolve))
  await store.mark('project', [], true, 'tasks')
  gate.resolve()
  assert.deepEqual(await Promise.all([page, duplicate]), [false, false])
  assert.deepEqual(
    store.tasks.map((comment) => comment.id),
    ['tasks'],
  )
  assert.deepEqual(store.unreadCounts, { general: 31, tasks: 0 })
  assert.equal(store.next.general, 'general-page2')
  assert.equal(store.next.tasks, 'tasks-page2')
  assert.equal(calls.filter((call) => call.path.includes('cursor=')).length, 1)
})
test('a target beyond the first page chooses its category and keeps an independent chronological cursor', async () => {
  const store = adminStore(),
    calls = []
  api.request = async (path) => {
    calls.push(path)
    return path.includes('?comment=')
      ? { ...response('tasks'), data: [item('target')] }
      : response(path.includes('kind=general') ? 'general' : 'tasks')
  }
  await store.load('project')
  store.drafts.project = 'Unsent general message'
  assert.equal(await store.revealComment('target'), 'tasks')
  assert.equal(store.targetWindows.tasks, true)
  assert.equal(store.next.tasks, 'tasks-page2')
  assert.equal(store.general[0].id, 'general')
  assert.equal(await store.revealComment('target'), 'tasks')
  assert.equal(calls.filter((path) => path.includes('?comment=')).length, 1)
  await store.latest('tasks')
  assert.equal(store.targetWindows.tasks, false)
  assert.equal(store.tasks[0].id, 'tasks')
  assert.equal(store.drafts.project, 'Unsent general message')
})
test('project and account switches reject late target, page, counters and draft updates', async () => {
  const store = clientStore(),
    gate = deferred()
  api.request = async (path) =>
    path.includes('?comment=')
      ? gate.promise
      : response(path.includes('kind=general') ? 'general' : 'tasks')
  store.setIdentity('client-a')
  await store.load('one')
  store.drafts.one = 'Private draft'
  const lookup = store.revealComment('old-target')
  store.setIdentity('client-b')
  await store.load('two')
  gate.resolve({ ...response('tasks', { general: 99, tasks: 99 }), data: [item('old-target')] })
  assert.equal(await lookup, null)
  assert.equal(store.projectId, 'two')
  assert.deepEqual(store.drafts, {})
  assert.deepEqual(store.unreadCounts, { general: 31, tasks: 42 })
  assert.equal(
    store.tasks.some((comment) => comment.id === 'old-target'),
    false,
  )
})
test('both languages render accessible tabs with independent badges and one visible panel', async () => {
  const store = adminStore()
  api.request = async (path) => response(path.includes('kind=general') ? 'general' : 'tasks')
  await store.load('project')
  const router = routerFactory({ history: createMemoryHistory(), apiMode: false })
  await router.push('/admin/projects/project/discussions')
  for (const lang of ['ru', 'en']) {
    i18n.global.locale.value = lang
    const html = await renderToString(createSSRApp(View).use(store._p).use(router).use(i18n))
    assert.match(html, /role="tablist"/)
    assert.equal((html.match(/role="tab"/g) || []).length, 2)
    assert.equal((html.match(/role="tabpanel"/g) || []).length, 2)
    assert.equal((html.match(/aria-selected="true"/g) || []).length, 1)
    assert.equal((html.match(/display:none/g) || []).length, 1)
    assert.ok(html.includes('>42</span>'))
    assert.ok(html.includes(i18n.global.t('team95.chat')))
    assert.equal(html.includes('filter-tabs'), false)
  }
})

test('a slower earlier target cannot overwrite the latest hash target in the same project', async () => {
  const store = adminStore(),
    older = deferred(),
    newer = deferred()
  api.request = async (path) =>
    path.includes('comment=older')
      ? older.promise
      : path.includes('comment=newer')
        ? newer.promise
        : response(path.includes('kind=general') ? 'general' : 'tasks')
  await store.load('project')
  const first = store.revealComment('older')
  await new Promise((resolve) => setImmediate(resolve))
  const second = store.revealComment('newer')
  await new Promise((resolve) => setImmediate(resolve))
  newer.resolve({ ...response('tasks'), data: [item('newer')] })
  assert.equal(await second, 'tasks')
  older.resolve({ ...response('tasks'), data: [item('older')] })
  assert.equal(await first, null)
  assert.deepEqual(
    store.tasks.map((comment) => comment.id),
    ['newer'],
  )
})

test('a cached hash target cancels an older lookup that would remove it from the visible feed', async () => {
  const store = adminStore(),
    gate = deferred()
  api.request = async (path) =>
    path.includes('?comment=')
      ? gate.promise
      : response(path.includes('kind=general') ? 'general' : 'tasks')
  await store.load('project')
  const first = store.revealComment('older')
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(await store.revealComment('tasks'), 'tasks')
  gate.resolve({ ...response('tasks'), data: [item('older')] })
  assert.equal(await first, null)
  assert.deepEqual(
    store.tasks.map((comment) => comment.id),
    ['tasks'],
  )
})
