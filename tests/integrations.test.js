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
