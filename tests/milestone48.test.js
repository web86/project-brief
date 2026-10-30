import { allProjectPermissions } from './helpers/projectPermissions.js'
import { before, after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createMemoryHistory } from 'vue-router'
import { projectMenu, accountMenu } from '../src/services/adminMenu.js'
let server, routerFactory, navStore, i18n, Navigation, Tabs
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  const load = (file) => server.ssrLoadModule('/src/' + file)
  routerFactory = (await load('router/index.js')).createProjectRouter
  navStore = (await load('stores/adminNavigation.js')).useAdminNavigationStore
  i18n = (await load('i18n/index.js')).i18n
  Navigation = (await load('components/ProjectNavigation.vue')).default
  Tabs = (await load('components/AccountTabs.vue')).default
})
after(async () => server.close())
beforeEach(() => setActivePinia(createPinia()))
async function render(Component, path, props = {}) {
  navStore().ready = true
  const management = (
    await server.ssrLoadModule('/src/stores/projectManagement.js')
  ).useProjectManagementStore()
  management.adopt(
    navStore().projects.find((project) => path.includes('/' + project.id + '/')) || null,
  )
  const router = routerFactory({ history: createMemoryHistory(), apiMode: false })
  await router.push(path)
  const app = createSSRApp(Component, props)
  app.use(router)
  app.use(i18n)
  return renderToString(app)
}
test('shared developer menu only names supported AppIcon paths and has no duplicate sections', async () => {
  const source = await readFile(new URL('../src/components/AppIcon.vue', import.meta.url), 'utf8')
  for (const item of [...projectMenu, ...accountMenu])
    assert.ok(
      source.includes(item.icon + ':') || source.includes("'" + item.icon + "':"),
      item.icon,
    )
  assert.equal(new Set(projectMenu.map((item) => item.section)).size, projectMenu.length)
})
test('owner team navigation has decorative aligned icons, active state, right-hand badge and separate trash', async () => {
  for (const lang of ['ru', 'en']) {
    i18n.global.locale.value = lang
    navStore().user = { membershipRole: 'owner' }
    navStore().projects = [
      { id: 'test', unreadCommentCount: 3, permissions: { ...allProjectPermissions } },
    ]
    const html = await render(Navigation, '/admin/projects/test/team', { vertical: true })
    assert.ok(html.includes(i18n.global.t('team.title')))
    assert.match(html, /aria-current="page"[^>]*href="\/admin\/projects\/test\/team"/)
    assert.equal((html.match(/aria-hidden="true"/g) || []).length, 9)
    assert.ok(html.includes('discussion-badge'))
    assert.ok(html.indexOf('project-trash-link') > html.indexOf('/test/export'))
    assert.ok(!html.includes('nav.team'))
  }
})
test('developer menu omits owner-only team management and retains existing tools', async () => {
  navStore().user = { membershipRole: 'admin' }
  navStore().projects = [
    { id: 'test', permissions: { ...allProjectPermissions, manage_team: false } },
  ]
  const html = await render(Navigation, '/admin/projects/test/brief', { vertical: true })
  assert.ok(!html.includes('/test/team'))
  assert.ok(html.includes('/test/clients'))
  assert.ok(html.includes('/test/export'))
})
test('global account tabs use the same icons and exact active page in both languages', async () => {
  for (const lang of ['ru', 'en']) {
    i18n.global.locale.value = lang
    const html = await render(Tabs, '/admin/account/security')
    assert.equal((html.match(/aria-current="page"/g) || []).length, 1)
    assert.ok(html.includes(i18n.global.t('account.security')))
    assert.ok(html.includes('/admin/settings/notifications'))
    assert.equal((html.match(/aria-hidden="true"/g) || []).length, 3)
  }
})

test('project menu fails closed and reflects every independent API capability in both languages and layouts', async () => {
  const { defaultProjectPermissions } = await import('../src/services/projectPermissions.js')
  navStore().user = { membershipRole: 'admin' }
  for (const lang of ['ru', 'en']) {
    i18n.global.locale.value = lang
    for (const vertical of [false, true]) {
      for (const enabled of [null, ...Object.keys(defaultProjectPermissions)]) {
        const permissions = Object.fromEntries(
          Object.keys(defaultProjectPermissions).map((key) => [key, key === enabled]),
        )
        navStore().projects = [{ id: 'test', permissions }]
        const html = await render(Navigation, '/admin/projects/test/brief', { vertical })
        for (const item of projectMenu)
          assert.equal(
            html.includes('/test/' + item.section),
            !item.owner && (!item.permission || item.permission === enabled),
          )
        assert.equal(html.includes('project-trash-link'), enabled === 'manage_trash')
      }
    }
  }
  navStore().projects = [{ id: 'test' }]
  const html = await render(Navigation, '/admin/projects/test/brief')
  assert.ok(!html.includes('/test/settings'))
  assert.ok(html.includes('/test/discussions'))
})

test('global administrators navigation is owner-only in every locale and changes with workspace authority', async () => {
  const Sidebar = (await server.ssrLoadModule('/src/components/AdminSidebar.vue')).default
  for (const lang of ['ru', 'en', 'tr', 'zh-CN']) {
    i18n.global.locale.value = lang
    navStore().workspace = { id: 'workspace', name: 'Workspace' }
    navStore().workspaces = [navStore().workspace]
    navStore().user = { name: 'Owner', membershipRole: 'owner' }
    const owner = await render(Sidebar, '/admin/administrators')
    assert.ok(owner.includes('href="/admin/administrators"'))
    assert.ok(owner.includes(i18n.global.t('administrators.title')))
    navStore().user = { name: 'Developer', membershipRole: 'admin' }
    const developer = await render(Sidebar, '/admin')
    assert.ok(!developer.includes('href="/admin/administrators"'))
    navStore().workspace = null
    const unavailable = await render(Sidebar, '/admin')
    assert.ok(!unavailable.includes('href="/admin/administrators"'))
  }
})

test('administrator route guard checks current workspace owner without granting developer authority', async () => {
  for (const membershipRole of ['owner', 'admin']) {
    setActivePinia(createPinia())
    const request = async (path) => {
      if (path === '/api/admin/me')
        return { user: { membershipRole }, workspace: { id: 'current', name: 'Current' } }
      if (path === '/api/admin/workspaces') return { data: [{ id: 'current', name: 'Current' }] }
      throw new Error(path)
    }
    const router = routerFactory({ history: createMemoryHistory(), apiMode: true, request })
    await router.push('/admin/administrators')
    assert.equal(
      router.currentRoute.value.path,
      membershipRole === 'owner' ? '/admin/administrators' : '/admin/unavailable',
    )
    if (membershipRole === 'admin')
      assert.equal(router.currentRoute.value.query.reason, 'permission')
  }
})
