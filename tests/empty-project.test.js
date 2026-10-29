import { before, after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createRouter, createMemoryHistory } from 'vue-router'
let server, useProject, View, Form, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    mode: 'test',
    define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
  })
  useProject = (await server.ssrLoadModule('/src/stores/project.js')).useProjectStore
  View = (await server.ssrLoadModule('/src/views/ProjectView.vue')).default
  Form = (await server.ssrLoadModule('/src/components/TaskForm.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => server?.close())
beforeEach(() => setActivePinia(createPinia()))
async function fixture(role, sections = []) {
  const store = useProject()
  store.project = {
    id: 'project',
    name: 'Project',
    permissions: { manage_structure: role === 'owner' },
  }
  store.admin = role === 'client' ? null : { membershipRole: role }
  store.currentClient = role === 'client' ? { id: 'client', name: 'Client' } : null
  store.sections = sections
  store.tasks = []
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/project/:uuid', component: View },
      { path: '/project/:uuid/task/new', component: Form },
      {
        path: '/admin/projects/:uuid/brief',
        component: View,
        meta: { adminShell: true, projectOverview: true },
      },
    ],
  })
  await router.push(role === 'client' ? '/project/project' : '/admin/projects/project/brief')
  return { store, router }
}
for (const role of ['client', 'owner', 'admin']) {
  for (const [locale, label] of [
    ['ru', 'Создать задачу'],
    ['en', 'Create task'],
    ['tr', 'Görev oluştur'],
    ['zh-CN', '创建任务'],
  ]) {
    test(`empty project lets ${role} open the existing task form in ${locale}`, async () => {
      const { store, router } = await fixture(role)
      i18n.global.locale.value = locale
      const html = await renderToString(createSSRApp(View).use(router).use(i18n))
      assert.ok(html.includes(`href="${store.newTaskPath}"`), html)
      assert.ok(html.includes(label), html)
      assert.ok(!html.includes(`/admin/projects/project/structure`))
      assert.ok(!html.includes('emptyProject.'))
    })
  }
}
test('client task form accepts a new section with no existing sections and suggests existing ones', async () => {
  const { store, router } = await fixture('client')
  for (const sections of [[], [{ id: 'home', name: 'Existing section', position: 1 }]]) {
    store.sections = sections
    const html = await renderToString(createSSRApp(Form).use(router).use(i18n))
    assert.match(html, /id="idea-location"[^>]*type="text"[^>]*role="combobox"/)
    assert.ok(!html.includes('<select id="idea-location"'))
  }
})
test('existing projects retain the task creation action for client, owner and assigned developer', async () => {
  for (const role of ['client', 'owner', 'admin']) {
    const { store, router } = await fixture(role, [{ id: 'home', name: 'Home', position: 1 }])
    const html = await renderToString(createSSRApp(View).use(router).use(i18n))
    assert.ok(html.includes(`href="${store.newTaskPath}"`))
    assert.ok(!html.includes('project-empty-state'))
  }
})
