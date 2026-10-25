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
    navStore().projects = [{ id: 'test', unreadCommentCount: 3 }]
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
