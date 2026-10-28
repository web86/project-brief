import { test, before, beforeEach, after } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createRouter, createMemoryHistory } from 'vue-router'
import { brandTokens, brandPresets, contrast } from '../src/services/branding.js'
let server, brandingStore, listingStore, api, listQuery, navigationStore, appearance
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
  appearance = (await load('services/workspaceAppearance.js')).activeWorkspaceBrand
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
  assetInfo: {},
  versions: Object.fromEntries(
    ['visual', 'business', 'contact', 'email', 'export'].map((key) => [key, 'a'.repeat(64)]),
  ),
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
test('one atomic save includes all dirty sections and preserves edits made in flight', async () => {
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
    assert.deepEqual(Object.keys(body.sections).sort(), ['business', 'visual'])
    assert.equal(body.sections.business.account, 'UNSAVED')
    assert.equal(body.versions.business, 'a'.repeat(64))
    await gate.promise
    return {
      data: {
        ...fixture(),
        business: { account: 'UNSAVED' },
        visual: { ...fixture().visual, companyName: 'First' },
      },
    }
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

test('short search queries reuse the unfiltered page and do not issue text requests', async () => {
  const store = listingStore(),
    calls = []
  api.request = async (path) => {
    calls.push(path)
    return { data: [], meta: { current_page: 1, last_page: 1, total: 0 } }
  }
  await store.load('a', {})
  await store.load('a', { q: ' a ' })
  await store.load('a', { q: ' ab ' })
  assert.deepEqual(calls, ['/api/admin/projects'])
  await store.load('a', { q: ' abc ' })
  assert.equal(calls.at(-1), '/api/admin/projects?q=abc')
})

test('successful atomic save clears all dirty sections while a conflict keeps the complete draft', async () => {
  const store = brandingStore()
  api.request = async () => ({ data: fixture() })
  await store.load('a')
  store.draft.visual.companyName = 'New name'
  store.draft.contact.email = 'new@example.test'
  store.draft.business.account = 'New bank'
  const snapshot = JSON.parse(JSON.stringify(store.draft))
  api.request = async () => {
    throw { status: 409, code: 'branding_conflict' }
  }
  assert.equal(await store.save(), false)
  assert.deepEqual(store.draft, snapshot)
  assert.equal(store.dirty, true)
  api.request = async (_path, { body }) => ({ data: { ...fixture(), ...body.sections } })
  assert.equal(await store.save(), true)
  assert.equal(store.dirty, false)
})

test('central appearance is workspace scoped and resets for switching, profile, security and guests', () => {
  const brandA = { companyName: 'A' },
    brandB = { companyName: 'B' }
  const admin = { ready: true, workspace: { id: 'a', branding: brandA }, switching: false }
  const project = { currentClient: null, project: {} }
  const route = { path: '/admin', meta: { adminShell: true } }
  assert.equal(appearance(route, admin, project), brandA)
  admin.switching = true
  assert.equal(appearance(route, admin, project), null)
  admin.workspace = { id: 'b', branding: brandB }
  admin.switching = false
  assert.equal(appearance(route, admin, project), brandB)
  assert.equal(
    appearance(
      { path: '/admin/account', meta: { adminShell: true, personalSettings: true } },
      admin,
      project,
    ),
    null,
  )
  assert.equal(appearance({ path: '/admin/login', meta: {} }, admin, project), null)
  project.currentClient = { id: 'c' }
  project.project.branding = brandA
  assert.equal(
    appearance({ path: '/project/a', meta: { apiProject: true } }, admin, project),
    brandA,
  )
})

test('failed image replacement retains the old image and selected file for an explicit retry', async () => {
  const store = brandingStore(),
    data = { ...fixture(), assets: { logo: '/old.png', favicon: null } }
  api.request = async () => ({ data })
  await store.load('a')
  const file = new File(['pixels'], 'logo.png', { type: 'image/png' })
  api.request = async () => {
    throw { status: 503 }
  }
  assert.equal(await store.asset('logo', file), false)
  assert.equal(store.draft.assets.logo, '/old.png')
  assert.equal(store.pendingAsset.file, file)
  assert.equal(store.dirty, true)
  assert.equal(store.hasChanges, false)
  api.request = async () => ({ data: { ...data, assets: { logo: '/new.png', favicon: null } } })
  assert.equal(await store.retryAsset(), true)
  assert.equal(store.draft.assets.logo, '/new.png')
  assert.equal(store.pendingAsset, null)
  assert.equal(store.dirty, false)
})

test('conflict refresh merges new server fields while preserving only actual local edits', async () => {
  const store = brandingStore()
  api.request = async () => ({ data: fixture() })
  await store.load('a')
  store.draft.visual.companyName = 'Local name'
  api.request = async () => ({
    data: {
      ...fixture(),
      visual: { companyName: 'Remote name', primaryColor: '#7354a3' },
      business: { account: 'REMOTE BANK' },
    },
  })
  assert.equal(await store.rebase(), true)
  assert.equal(store.draft.visual.companyName, 'Local name')
  assert.equal(store.draft.visual.primaryColor, '#7354a3')
  assert.equal(store.draft.business.account, 'REMOTE BANK')
  assert.equal(store.dirty, true)
})

test('branding and notification breadcrumbs link to the account across all locales and stay absent for clients', async () => {
  const Breadcrumbs = (await server.ssrLoadModule('/src/components/Breadcrumbs.vue')).default
  const { i18n } = await server.ssrLoadModule('/src/i18n/index.js')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/admin/settings/branding',
        component: { render: () => null },
        meta: { adminShell: true },
      },
      {
        path: '/admin/settings/notifications',
        component: { render: () => null },
        meta: { adminShell: true, personalSettings: true },
      },
      { path: '/project/a', component: { render: () => null }, meta: { apiProject: true } },
    ],
  })
  const pinia = brandingStore()._p
  for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
    i18n.global.locale.value = locale
    for (const target of ['/admin/settings/branding', '/admin/settings/notifications']) {
      await router.push(target)
      const html = await renderToString(createSSRApp(Breadcrumbs).use(pinia).use(router).use(i18n))
      assert.ok(html.includes('href="/admin/account"'))
      assert.ok(html.includes(i18n.global.t('account.title')))
      assert.ok(
        html.includes(
          i18n.global.t(target.endsWith('branding') ? 'branding.title' : 'nav.notifications'),
        ),
      )
    }
  }
  await router.push('/project/a')
  assert.ok(
    !(await renderToString(createSSRApp(Breadcrumbs).use(pinia).use(router).use(i18n))).includes(
      'breadcrumbs',
    ),
  )
  i18n.global.locale.value = 'en'
})
