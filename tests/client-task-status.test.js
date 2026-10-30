import { test, before, beforeEach, after } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { canChangeClientStatus } from '../src/utils/clientTaskStatus.js'
let server, useProjectStore, api, Component, i18n
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
  useProjectStore = (await server.ssrLoadModule('/src/stores/project.js')).useProjectStore
  api = (await server.ssrLoadModule('/src/api/client.js')).api
  Component = (await server.ssrLoadModule('/src/components/ClientTaskStatusActions.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
beforeEach(() => {
  setActivePinia(createPinia())
})
after(async () => {
  await server.close()
})
function setup(status = 'review') {
  const store = useProjectStore()
  store.admin = null
  store.currentClient = { id: 'client' }
  store.project = { id: 'project' }
  store.tasks = [
    { id: 'task', status, editVersion: 'a'.repeat(64), comments: [], history: [], attachments: [] },
  ]
  return store
}
test('client status matrix exposes exactly review to approved/done and never changes developer actions', () => {
  for (const from of ['new', 'clarification', 'approved', 'in_progress', 'review', 'done'])
    for (const to of ['new', 'clarification', 'approved', 'in_progress', 'review', 'done'])
      assert.equal(
        canChangeClientStatus({ status: from }, to),
        from === 'review' && ['approved', 'done'].includes(to),
      )
})
test('only one status request is sent, with the observed editVersion, and no optimistic status update', async () => {
  const store = setup(),
    calls = []
  let finish
  api.request = (path, options) => {
    calls.push({ path, ...options })
    return new Promise((resolve) => {
      finish = resolve
    })
  }
  const first = store.changeClientStatus('task', 'done')
  const second = store.changeClientStatus('task', 'done')
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(calls.length, 1)
  assert.equal(store.tasks[0].status, 'review')
  assert.deepEqual(calls[0].body, { status: 'done', editVersion: 'a'.repeat(64) })
  finish({ data: { ...store.tasks[0], status: 'done', editVersion: 'b'.repeat(64) } })
  assert.equal(await first, true)
  assert.equal(await second, false)
  assert.equal(store.tasks[0].status, 'done')
})
test('failed saves retain the original status; conflicts fetch actual state without replaying intent', async () => {
  const store = setup()
  api.request = async () => {
    throw { status: 503 }
  }
  assert.equal(await store.changeClientStatus('task', 'done'), false)
  assert.equal(store.tasks[0].status, 'review')
  const calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    if (options?.method === 'PATCH') throw { status: 409, code: 'task_conflict' }
    return { data: { ...store.tasks[0], status: 'in_progress', editVersion: 'b'.repeat(64) } }
  }
  assert.equal(await store.changeClientStatus('task', 'done'), false)
  assert.equal(store.tasks[0].status, 'in_progress')
  assert.deepEqual(
    calls.map((call) => call.method || 'GET'),
    ['PATCH', 'GET'],
  )
  assert.equal(await store.changeClientStatus('task', 'done'), false)
  assert.equal(calls.length, 2)
})
test('review actions are compact and localized; locked states have no active status control', async () => {
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    i18n.global.locale.value = language
    const store = setup()
    const html = await renderToString(
      createSSRApp(Component, { task: store.tasks[0] }).use(store._p).use(i18n),
    )
    assert.ok(html.includes('client-status-buttons'))
    assert.ok(!html.includes('<select'))
    assert.ok(!html.includes('clientStatus.'))
    for (const status of ['new', 'approved', 'in_progress', 'done']) {
      store.tasks[0].status = status
      const locked = await renderToString(
        createSSRApp(Component, { task: store.tasks[0] }).use(store._p).use(i18n),
      )
      assert.ok(!locked.includes('client-status-buttons'))
    }
  }
})
