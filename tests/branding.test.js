import { test, before, beforeEach, after } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { brandTokens, brandPresets, contrast } from '../src/services/branding.js'
let server, brandingStore, listingStore, api, listQuery, navigationStore
before(async () => {
  server = await createServer({
    optimizeDeps: { noDiscovery: true, include: [] },
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  const load = (p) => server.ssrLoadModule('/src/' + p)
  brandingStore = (await load('stores/workspaceBranding.js')).useWorkspaceBrandingStore
  navigationStore = (await load('stores/adminNavigation.js')).useAdminNavigationStore
  const listing = await load('stores/projectListing.js')
  listingStore = listing.useProjectListingStore
  listQuery = listing.listQuery
  api = (await load('api/client.js')).api
})
beforeEach(() => setActivePinia(createPinia()))
after(async () => server?.close())
const fixture = () => ({
  visual: { companyName: 'Agency', primaryColor: '#17695d' },
  business: { account: 'PRIVATE' },
  contact: { email: 'hello@example.test' },
  email: { enabled: false },
  export: { business: false },
  assets: { logo: null, favicon: null },
})
const deferred = () => {
  let resolve
  const promise = new Promise((r) => (resolve = r))
  return { promise, resolve }
}
test('brand tokens preserve contrast on light/dark surfaces even for white, yellow and black accents', () => {
  for (const color of [
    ...brandPresets,
    '#ffffff',
    '#ffff00',
    '#000000',
    '#ff0000',
    '#0000ff',
    '#888888',
  ])
    for (const dark of [true, false]) {
      const tokens = brandTokens(color, dark)
      assert.ok(contrast(color, tokens['--on-action']) >= 4.5, color)
      assert.ok(contrast(tokens['--link'], dark ? '#1e252e' : '#ffffff') >= 4.5, `${color}/${dark}`)
      assert.equal(tokens['--success'], undefined)
    }
  assert.deepEqual(brandTokens('url(https://unsafe.test)'), {})
  assert.deepEqual(brandTokens(''), {})
})
test('section saves preserve other drafts and edits made while the save is in flight', async () => {
  const store = brandingStore()
  api.request = async () => ({ data: fixture() })
  await store.load('workspace-a')
  store.draft.visual.companyName = 'First'
  store.draft.business.account = 'UNSAVED'
  const gate = deferred()
  let calls = 0
  api.request = async (path, { body }) => {
    calls++
    assert.ok(path.includes('workspace-a'))
    assert.deepEqual(Object.keys(body.values).sort(), ['companyName', 'primaryColor'])
    await gate.promise
    return { data: { ...fixture(), visual: { ...fixture().visual, companyName: 'First' } } }
  }
  const first = store.save('visual')
  assert.equal(await store.save('visual'), false)
  store.draft.visual.companyName = 'Later edit'
  gate.resolve()
  assert.equal(await first, true)
  assert.equal(calls, 1)
  assert.equal(store.draft.visual.companyName, 'Later edit')
  assert.equal(store.draft.business.account, 'UNSAVED')
  assert.equal(store.settings.visual.companyName, 'First')
  assert.equal(store.dirty, true)
})
test('failed save and a response from a previous workspace never discard or cross-apply drafts', async () => {
  const store = brandingStore()
  api.request = async () => ({ data: fixture() })
  await store.load('workspace-a')
  store.draft.visual.companyName = 'Keep me'
  api.request = async () => {
    throw Error('offline')
  }
  assert.equal(await store.save('visual'), false)
  assert.equal(store.draft.visual.companyName, 'Keep me')
  const gate = deferred()
  api.request = async () => gate.promise
  const save = store.save('visual')
  store.clear()
  gate.resolve({ data: fixture() })
  assert.equal(await save, false)
  assert.equal(store.draft, null)
  assert.equal(store.workspaceId, '')
})
test('URL query allowlist is bounded and paged list ignores older workspace responses', async () => {
  assert.deepEqual(
    listQuery({ q: 'hello', status: 'unsafe', sort: 'name', page: '-5', extra: 'ignored' }),
    { q: 'hello', sort: 'name' },
  )
  const store = listingStore(),
    first = deferred(),
    second = deferred()
  let count = 0
  api.request = () => (++count === 1 ? first.promise : second.promise)
  const a = store.load('a', { page: 2 }),
    b = store.load('b', { q: 'new' })
  second.resolve({ data: [{ id: 'b' }], meta: { current_page: 1, last_page: 1, total: 1 } })
  await b
  first.resolve({ data: [{ id: 'a' }], meta: { current_page: 2, last_page: 4, total: 80 } })
  await a
  assert.deepEqual(store.projects, [{ id: 'b' }])
  assert.equal(store.meta.total, 1)
})

test('appearance reset preserves unsaved business and contact drafts', async () => {
  const store = brandingStore()
  api.request = async () => ({ data: fixture() })
  await store.load('workspace-a')
  store.draft.business.account = 'UNSAVED BANK'
  store.draft.contact.email = 'draft@example.test'
  globalThis.window = { confirm: () => true }
  api.request = async () => ({
    data: { ...fixture(), visual: { companyName: '', primaryColor: '' } },
  })
  try {
    assert.equal(await store.reset(), true)
    assert.equal(store.draft.visual.companyName, '')
    assert.equal(store.draft.business.account, 'UNSAVED BANK')
    assert.equal(store.draft.contact.email, 'draft@example.test')
    assert.equal(store.settings.business.account, 'PRIVATE')
    assert.equal(store.dirty, true)
  } finally {
    delete globalThis.window
  }
})

test('late sidebar page cannot put a trashed project back after its removal', async () => {
  const store = navigationStore(),
    gate = deferred()
  store.projects = [{ id: 'kept' }]
  store.sidebarLastPage = 2
  api.request = () => gate.promise
  const pending = store.moreProjects()
  store.removeProject('deleted')
  gate.resolve({ data: [{ id: 'deleted' }], meta: { current_page: 2, last_page: 2 } })
  await pending
  assert.deepEqual(store.projects, [{ id: 'kept' }])
  assert.equal(store.sidebarPage, 1)
  assert.equal(store.refreshing, false)
})
