import { before, after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
let server, routerFactory, useNav, Sidebar, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
  })
  routerFactory = (await server.ssrLoadModule('/src/router/index.js')).createProjectRouter
  useNav = (await server.ssrLoadModule('/src/stores/adminNavigation.js')).useAdminNavigationStore
  Sidebar = (await server.ssrLoadModule('/src/components/AdminSidebar.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => {
  await server?.close()
})
beforeEach(() => setActivePinia(createPinia()))
for (const role of ['owner', 'admin']) {
  test(`integrations direct route and sidebar enforce ${role} workspace role`, async () => {
    const nav = useNav()
    nav.ready = true
    nav.workspace = { id: 'studio', name: 'Studio' }
    nav.workspaces = [nav.workspace]
    nav.user = { name: 'Alex', membershipRole: role }
    const router = routerFactory({
      history: createMemoryHistory(),
      apiMode: true,
      request: async () => {
        throw new Error('Unexpected request')
      },
    })
    await router.push('/admin/settings/integrations')
    assert.equal(
      router.currentRoute.value.path,
      role === 'owner' ? '/admin/settings/integrations' : '/admin/unavailable',
    )
    const html = await renderToString(createSSRApp(Sidebar).use(router).use(i18n))
    assert.equal(html.includes('href="/admin/settings/integrations"'), role === 'owner')
  })
}
test('workspace role change removes integration access even when navigation cache is ready', async () => {
  const nav = useNav()
  nav.ready = true
  nav.workspace = { id: 'one' }
  nav.user = { membershipRole: 'owner' }
  const router = routerFactory({
    history: createMemoryHistory(),
    apiMode: true,
    request: async () => {
      throw new Error('Unexpected request')
    },
  })
  await router.push('/admin/settings/integrations')
  assert.equal(router.currentRoute.value.path, '/admin/settings/integrations')
  nav.workspace = { id: 'two' }
  nav.user = { membershipRole: 'admin' }
  await router.push('/admin')
  await router.push('/admin/settings/integrations')
  assert.equal(router.currentRoute.value.path, '/admin/unavailable')
})

test('documented PHP verifier accepts raw bytes and rejects tampering and stale timestamps', async () => {
  const { spawnSync } = await import('node:child_process')
  const { createHmac } = await import('node:crypto')
  const timestamp = String(Math.floor(Date.now() / 1000))
  const secret = 'synthetic-verifier-secret'
  const body = '{ "version": 1, "id": "synthetic-event", "text": "Привет" }\n'
  const signature =
    'sha256=' +
    createHmac('sha256', secret)
      .update(timestamp + '.' + body)
      .digest('hex')
  function verify(input, time = timestamp) {
    return spawnSync('php', ['docs/milestone-5-0/verify-webhook.php'], {
      input,
      encoding: 'utf8',
      env: { ...process.env, WH_SECRET: secret, PB_TIMESTAMP: time, PB_SIGNATURE: signature },
    })
  }
  assert.equal(verify(body).status, 0)
  assert.equal(verify(body.trim()).status, 1)
  assert.equal(verify(body, '1000000000').status, 1)
})

test('general and project navigation keep one return link and integrations under personal settings', async () => {
  const nav = useNav()
  nav.ready = true
  nav.workspace = { id: 'studio', name: 'Studio' }
  nav.workspaces = [nav.workspace]
  nav.user = { name: 'Alex', membershipRole: 'owner' }
  const router = routerFactory({ history: createMemoryHistory(), apiMode: true })
  await router.push('/admin')
  const html = await renderToString(createSSRApp(Sidebar).use(router).use(i18n))
  assert.equal((html.match(/sidebar-project-heading/g) || []).length, 1)
  const heading = html.match(/<a[^>]*sidebar-project-heading[^>]*>/)[0]
  assert.match(heading, /href="\/admin"/)
  assert.match(heading, /aria-current="page"/)
  assert.equal((html.match(/All projects|Все проекты/g) || []).length, 1)
  assert.ok(
    html.indexOf('personal-settings-nav') < html.indexOf('href="/admin/settings/integrations"'),
  )
  const ProjectSidebar = (await server.ssrLoadModule('/src/components/ProjectSidebar.vue')).default
  for (const role of ['owner', 'admin']) {
    nav.user.membershipRole = role
    const projectHtml = await renderToString(
      createSSRApp(ProjectSidebar, { project: { id: 'project', name: 'Project' } })
        .use(router)
        .use(i18n),
    )
    assert.equal((projectHtml.match(/project-all-projects/g) || []).length, 1)
    assert.equal(projectHtml.includes('href="/admin/settings/integrations"'), role === 'owner')
    if (role === 'owner')
      assert.ok(
        projectHtml.indexOf('project-personal-heading') <
          projectHtml.indexOf('href="/admin/settings/integrations"'),
      )
  }
  nav.workspace = null
  nav.user.membershipRole = 'owner'
  const noWorkspace = await renderToString(createSSRApp(Sidebar).use(router).use(i18n))
  assert.equal(noWorkspace.includes('href="/admin/settings/integrations"'), false)
})

test('team and clients render different SVG icons in the shared menu', async () => {
  const { projectMenu } = await server.ssrLoadModule('/src/services/adminMenu.js')
  const Icon = (await server.ssrLoadModule('/src/components/AppIcon.vue')).default
  const team = projectMenu.find((item) => item.section === 'team')
  const clients = projectMenu.find((item) => item.section === 'clients')
  assert.equal(team.icon, 'user-check')
  assert.equal(clients.icon, 'users')
  const teamSvg = await renderToString(createSSRApp(Icon, { name: team.icon }))
  const clientsSvg = await renderToString(createSSRApp(Icon, { name: clients.icon }))
  assert.notEqual(teamSvg, clientsSvg)
  assert.match(teamSvg, /viewbox="0 0 24 24"/i)
  assert.match(teamSvg, /M16 11l2 2 4-4/)
})
