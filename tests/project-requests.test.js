import { before, after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
let server, useRequests, useNav, routerFactory, Sidebar, View, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
  })
  useRequests = (await server.ssrLoadModule('/src/stores/projectRequests.js'))
    .useProjectRequestsStore
  useNav = (await server.ssrLoadModule('/src/stores/adminNavigation.js')).useAdminNavigationStore
  routerFactory = (await server.ssrLoadModule('/src/router/index.js')).createProjectRouter
  Sidebar = (await server.ssrLoadModule('/src/components/AdminSidebar.vue')).default
  View = (await server.ssrLoadModule('/src/views/AdminProjectRequestsView.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => await server?.close())
beforeEach(() => setActivePinia(createPinia()))
const deferred = () => {
  let resolve, reject
  const promise = new Promise((a, b) => {
    resolve = a
    reject = b
  })
  return { promise, resolve, reject }
}
const pending = { id: 'request-1', status: 'pending', project: { name: 'Website' } }
const page = (data = [pending]) => ({
  data,
  meta: { page: 1, lastPage: 1, total: data.length, pending: data.length },
})
test('request list encodes filters and rejects an old workspace response', async () => {
  const store = useRequests(),
    slow = deferred()
  let url
  const old = store.load('one', async (path) => {
    url = path
    return slow.promise
  })
  store.clear()
  store.search = 'Ada & Co'
  store.status = 'rejected'
  store.sort = 'asc'
  store.page = 2
  await store.load('two', async (path) => {
    assert.ok(path.includes('search=Ada+%26+Co'))
    assert.ok(path.includes('status=rejected'))
    assert.ok(path.includes('page=2'))
    return page([])
  })
  slow.resolve(page())
  await old
  assert.equal(store.items.length, 0)
  assert.ok(url.includes('status=pending'))
})
test('duplicate decision activation sends one request and a lost response reuses its key', async () => {
  const store = useRequests()
  await store.load('one', async () => page())
  store.selected = { ...pending }
  const slow = deferred(),
    calls = []
  const request = async (path, options) => {
    calls.push([path, options])
    return slow.promise
  }
  const first = store.decide('approve', { sendInvitation: true }, request)
  assert.equal(await store.decide('approve', { sendInvitation: true }, request), false)
  assert.equal(calls.length, 1)
  slow.reject({ status: 503 })
  assert.equal(await first, false)
  assert.equal(store.selected.status, 'pending')
  const key = calls[0][1].headers['Idempotency-Key']
  const saved = await store.decide('approve', { sendInvitation: true }, async (path, options) => {
    if (!options) return page([])
    assert.equal(options.headers['Idempotency-Key'], key)
    return { data: { ...pending, status: 'approved', projectId: 'project-1' } }
  })
  assert.equal(saved, true)
  assert.equal(store.selected.status, 'approved')
  assert.equal(store.busy, false)
})
test('workspace switch while saving cannot reveal another workspace result', async () => {
  const store = useRequests()
  await store.load('one', async () => page())
  store.selected = { ...pending }
  const slow = deferred()
  const action = store.decide('reject', { reason: 'internal' }, () => slow.promise)
  store.clear()
  await store.load('two', async () => page([]))
  slow.resolve({ data: { ...pending, status: 'rejected' } })
  assert.equal(await action, false)
  assert.equal(store.selected, null)
  assert.deepEqual(store.items, [])
})
test('live refresh keeps the selected request while fetching and preserves its identity', async () => {
  const store = useRequests()
  await store.load('one', async () => page())
  store.selected = { ...pending }
  const slow = deferred()
  const refresh = store.refresh(async (path) => (path.includes('?') ? page() : slow.promise))
  assert.equal(store.selected.id, 'request-1')
  slow.resolve({ data: { ...pending, project: { name: 'Updated' } } })
  await refresh
  assert.equal(store.selected.id, 'request-1')
  assert.equal(store.selected.project.name, 'Updated')
})
test('conflicting decision refreshes the final server result without optimistic approval', async () => {
  const store = useRequests()
  await store.load('one', async () => page())
  store.selected = { ...pending }
  const result = await store.decide('approve', { sendInvitation: false }, async (path, options) => {
    if (options) throw { status: 409, code: 'idempotency_conflict' }
    return path.includes('?') ? page([]) : { data: { ...pending, status: 'rejected' } }
  })
  assert.equal(result, false)
  assert.equal(store.selected.status, 'rejected')
  assert.equal(store.error.status, 409)
})
for (const role of ['owner', 'admin'])
  test(`project requests route and navigation require owner: ${role}`, async () => {
    const nav = useNav()
    nav.ready = true
    nav.workspace = { id: 'studio', name: 'Studio' }
    nav.workspaces = [nav.workspace]
    nav.user = { name: 'Alex', membershipRole: role }
    nav.pendingRequests = 7
    const router = routerFactory({
      history: createMemoryHistory(),
      apiMode: true,
      request: async () => {
        throw Error('Unexpected request')
      },
    })
    await router.push('/admin/requests')
    assert.equal(
      router.currentRoute.value.path,
      role === 'owner' ? '/admin/requests' : '/admin/unavailable',
    )
    const html = await renderToString(createSSRApp(Sidebar).use(router).use(i18n))
    assert.equal(html.includes('href="/admin/requests"'), role === 'owner')
    if (role === 'owner') assert.match(html, /unread-badge[^>]*>7/)
  })
for (const locale of ['ru', 'en', 'tr', 'zh-CN'])
  test(`request interface has translated controls: ${locale}`, async () => {
    const nav = useNav()
    nav.ready = true
    nav.workspace = { id: 'studio' }
    nav.user = { membershipRole: 'owner' }
    i18n.global.locale.value = locale
    const router = routerFactory({
      history: createMemoryHistory(),
      apiMode: true,
      request: async () => {
        throw Error('Unexpected request')
      },
    })
    await router.push('/admin/requests')
    const html = await renderToString(createSSRApp(View).use(router).use(i18n))
    assert.ok(html.includes(i18n.global.t('requests.title')))
    assert.ok(html.includes(i18n.global.t('requests.status_approved')))
    assert.ok(!html.includes('requests.'))
  })
