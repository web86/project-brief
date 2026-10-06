import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createServer } from 'vite'
let server, TaskView, ProjectView, useProjectStore, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  TaskView = (await server.ssrLoadModule('/src/views/TaskView.vue')).default
  ProjectView = (await server.ssrLoadModule('/src/views/ProjectView.vue')).default
  useProjectStore = (await server.ssrLoadModule('/src/stores/project.js')).useProjectStore
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => {
  await server?.close()
})
async function render(component, path, configure, language = 'en') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useProjectStore(pinia)
  configure(store)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component },
      { path: '/task/:id', component },
      { path: '/task/:id/edit', component },
    ],
  })
  await router.push(path)
  i18n.global.locale.value = language
  return renderToString(createSSRApp(component).use(pinia).use(router).use(i18n))
}
test('actual client view shows edit/delete only for eligible states and translates confirmation', async () => {
  for (const [status, approved, visible] of [
    ['new', false, true],
    ['clarification', false, true],
    ['approved', false, false],
    ['in_progress', false, false],
    ['review', false, false],
    ['done', false, false],
    ['new', true, false],
    ['clarification', true, false],
  ]) {
    const html = await render(TaskView, '/task/demo-1', (store) => {
      store.currentMode = 'client'
      Object.assign(store.findTask('demo-1'), { status, clientApproved: approved })
    })
    assert.equal(html.includes('class="client-draft-actions"'), visible, status + ' ' + approved)
    assert.equal(html.includes('class="text-button delete-idea-action"'), visible)
    assert.ok(html.includes('Delete this idea?'))
    assert.ok(html.includes('This action cannot be undone.'))
    assert.ok(html.includes('Обновить первый экран'))
  }
  const russian = await render(
    TaskView,
    '/task/demo-1',
    (store) => {
      store.currentMode = 'client'
      Object.assign(store.findTask('demo-1'), { status: 'new', clientApproved: false })
    },
    'ru',
  )
  assert.ok(russian.includes('Удалить эту идею?'))
  assert.ok(russian.includes('Удалить идею'))
})
test('project renders empty canonical sections and developer controls outside links', async () => {
  const client = await render(ProjectView, '/', (store) => {
    store.currentMode = 'client'
  })
  assert.ok(client.includes('5. Общее'))
  assert.ok(client.includes('No ideas yet'))
  assert.equal(client.includes('class="card-order-actions"'), false)
  assert.equal(client.includes('class="section-order-actions"'), false)
  const developer = await render(ProjectView, '/', (store) => {
    store.currentMode = 'developer'
  })
  assert.ok(developer.includes('class="card-order-actions"'))
  assert.ok(developer.includes('class="section-order-actions"'))
  for (const anchor of developer.matchAll(/<a\b[^>]*>[\s\S]*?<\/a>/g))
    assert.equal(
      anchor[0].includes('<button'),
      false,
      'reorder buttons must not be inside navigation links',
    )
})
