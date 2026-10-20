import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createMemoryHistory } from 'vue-router'
import { createTelegramHandler } from '../deployment/telegram-bridge.mjs'
let server,
  api,
  projectStore,
  clientDiscussions,
  adminDiscussions,
  routerFactory,
  i18n,
  Export,
  Discussions,
  toast
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
  api = (await load('api/client.js')).api
  projectStore = (await load('stores/project.js')).useProjectStore
  const discussions = await load('stores/discussions.js')
  clientDiscussions = discussions.useClientDiscussionsStore
  adminDiscussions = discussions.useDiscussionsStore
  routerFactory = (await load('router/index.js')).createProjectRouter
  i18n = (await load('i18n/index.js')).i18n
  Export = (await load('views/AdminProjectExportView.vue')).default
  Discussions = (await load('views/AdminProjectDiscussionsView.vue')).default
  toast = (await load('services/toast.js')).toast
})
after(async () => {
  toast.clear()
  await server.close()
})
beforeEach(() => {
  setActivePinia(createPinia())
  i18n.global.locale.value = 'en'
  toast.clear()
})
const item = {
  id: 'comment',
  text: 'Public text',
  authorName: 'Alex',
  createdAt: '2026-10-20T10:00:00Z',
  unread: true,
  task: { id: 'task', title: 'Task', section: 'General' },
}
function backend() {
  const calls = []
  const request = async (path, options = {}) => {
    calls.push({ path, ...options })
    if (path === '/api/client/me') return { id: 'anna', name: 'Anna', preferredLocale: 'en' }
    if (path.includes('/discussions/read')) return { unreadCount: 1 }
    if (path.includes('/discussions'))
      return options.method === 'POST'
        ? { data: { ...item, id: 'new' } }
        : { data: [item], nextCursor: null, unreadCount: 2 }
    if (path.endsWith('/tasks')) return { data: [] }
    return { data: { id: 'project', name: 'Project', sections: [] } }
  }
  api.request = request
  return { calls, request }
}
async function fixture(section) {
  const { calls, request } = backend()
  const router = routerFactory({ apiMode: true, history: createMemoryHistory(), request })
  await router.push('/project/project/' + section)
  return { calls, router }
}
const html = (component, router) =>
  renderToString(createSSRApp(component).use(projectStore()._p).use(router).use(i18n))
test('client export uses shared controls with an explicit visible field allowlist', async () => {
  const { router } = await fixture('export')
  const output = await html(Export, router)
  for (const field of [
    'Description',
    'Expected result',
    'Priority',
    'Status',
    'Approval date',
    'Attachment list',
  ])
    assert.ok(output.includes(field), field)
  for (const field of ['Estimate hours', 'Price', 'Developer notes'])
    assert.equal(output.includes(field), false, field)
  assert.equal(router.currentRoute.value.meta.clientSection, 'export')
  assert.equal(projectStore().currentClient.id, 'anna')
})
test('client discussion route commits feeds and uses only client API and comment destinations', async () => {
  const { calls, router } = await fixture('discussions')
  const output = await html(Discussions, router)
  assert.ok(output.includes('/project/project/task/task#comment-comment'))
  assert.equal(output.includes('/admin/projects/'), false)
  assert.equal(clientDiscussions().unreadCount, 2)
  assert.equal(adminDiscussions().unreadCount, 0)
  await clientDiscussions().mark('project', ['comment'])
  assert.equal(projectStore().project.unreadCommentCount, 1)
  await clientDiscussions().send(' My general comment ')
  assert.equal(
    calls.some((call) => call.path.startsWith('/api/admin')),
    false,
  )
  assert.deepEqual(calls.at(-1).body, { text: 'My general comment' })
})
test('client identity change invalidates feeds, drafts and stale outstanding read responses', async () => {
  backend()
  const store = clientDiscussions()
  store.setIdentity('anna')
  await store.load('project')
  store.drafts.project = 'Anna private unsent draft'
  let resolve
  api.request = () =>
    new Promise((done) => {
      resolve = done
    })
  const pending = store.mark('project', ['comment'])
  await new Promise((done) => setTimeout(done, 0))
  store.setIdentity('john')
  resolve({ unreadCount: 99 })
  await pending
  assert.equal(store.unreadCount, 0)
  assert.deepEqual(store.drafts, {})
  assert.deepEqual(store.tasks, [])
})
test('failed client discussion navigation keeps the prior project view and reports failure', async () => {
  const { router } = await fixture('export')
  const previous = router.currentRoute.value.fullPath
  const original = api.request
  api.request = async (path, ...args) => {
    if (path.includes('/discussions'))
      throw Object.assign(new Error('Unavailable'), { status: 503 })
    return original(path, ...args)
  }
  await router.push('/project/project/discussions')
  assert.equal(router.currentRoute.value.fullPath, previous)
})
const token = '12345:abcdefghijklmnopqrstuvwxyzABCDEFG'
const secret = 's'.repeat(40)
function bridge(fetch) {
  return createTelegramHandler({
    env: (key) =>
      ({ TELEGRAM_BOT_TOKEN: token, TELEGRAM_BRIDGE_SECRET: secret, GROQ_API_KEY: 'DO_NOT_USE' })[
        key
      ],
    fetch,
  })
}
function req(
  body,
  authorization = 'Bearer ' + secret,
  url = 'https://bridge.deno.dev/telegram/send',
) {
  return new Request(url, {
    method: 'POST',
    headers: { Authorization: authorization, 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
}
test('isolated Deno bridge forwards exactly one fixed Telegram endpoint and returns no private data', async () => {
  const calls = []
  const handler = bridge(async (...args) => {
    calls.push(args)
    return Response.json({ ok: true, result: { private: 'SECRET_CHAT' } })
  })
  const result = await handler(
    req({ method: 'sendMessage', payload: { chat_id: '12345', text: 'Synthetic' } }),
  )
  assert.deepEqual(await result.json(), { ok: true })
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], `https://api.telegram.org/bot${token}/sendMessage`)
  assert.equal(calls[0][1].redirect, 'error')
  assert.equal(calls[0][1].headers.Authorization, undefined)
})
test('Deno bridge denies wrong auth, arbitrary methods, routes and payload fields before network', async () => {
  let calls = 0
  const handler = bridge(async () => {
    calls++
    return Response.json({ ok: true })
  })
  assert.equal((await handler(req({ method: 'getMe', payload: {} }, 'Bearer wrong'))).status, 401)
  assert.equal(
    (
      await handler(
        req({ method: 'getMe', payload: {} }, 'Bearer ' + secret, 'https://bridge.deno.dev/ai'),
      )
    ).status,
    404,
  )
  for (const body of [
    { method: 'https://evil.test', payload: {} },
    { method: 'setWebhook', payload: {} },
    { method: 'getMe', payload: { url: 'https://evil.test' } },
    { method: 'sendMessage', payload: { chat_id: '-12345', text: 'Group' } },
    {
      method: 'sendMessage',
      payload: { chat_id: '12345', text: 'Text', url: 'https://evil.test' },
    },
  ])
    assert.equal((await handler(req(body))).status, 400)
  assert.equal(calls, 0)
})
test('Deno bridge limits body size and sanitizes errors without retry or AI dependency', async () => {
  let calls = 0
  const handler = bridge(async () => {
    calls++
    return Response.json(
      { ok: false, error_code: 429, description: token + ' PRIVATE_MESSAGE' },
      { status: 429 },
    )
  })
  assert.equal(
    (
      await handler(
        req({ method: 'sendMessage', payload: { chat_id: '12345', text: 'x'.repeat(25000) } }),
      )
    ).status,
    413,
  )
  const result = await handler(req({ method: 'getMe', payload: {} }))
  assert.equal(result.status, 429)
  assert.deepEqual(await result.json(), { ok: false, error_code: 429 })
  assert.equal(calls, 1)
  const missing = createTelegramHandler({
    env: (key) => (key.startsWith('GROQ') ? 'AI_SECRET' : ''),
    fetch: async () => {
      throw new Error('must not call')
    },
  })
  assert.equal((await missing(req({ method: 'getMe', payload: {} }))).status, 401)
})
