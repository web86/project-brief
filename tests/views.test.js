import { test, before, after } from 'node:test'
import assert from 'node:assert/strict'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createServer } from 'vite'
let server, TaskView, ProjectView, PushDevicePanel, useProjectStore, i18n
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
  PushDevicePanel = (await server.ssrLoadModule('/src/components/PushDevicePanel.vue')).default
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
    assert.equal(html.includes('class="text-button delete-idea-action"'), true)
    assert.equal(
      /class="text-button delete-idea-action" disabled/.test(html),
      status !== 'new' || approved,
    )
    assert.ok(html.includes('Move idea to trash?'))
    assert.ok(html.includes('It can be restored from trash.'))
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
  assert.ok(russian.includes('Переместить идею в корзину?'))
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
  assert.equal(developer.includes('class="card-order-actions"'), false)
  assert.ok(developer.includes('class="section-order-actions"'))
  for (const anchor of developer.matchAll(/<a\b[^>]*>[\s\S]*?<\/a>/g))
    assert.equal(
      anchor[0].includes('<button'),
      false,
      'reorder buttons must not be inside navigation links',
    )
})

test('actual push settings panel translates states, actions and retry feedback', async () => {
  for (const [state, text, action] of [
    ['unsupported', 'Push notifications are unavailable', null],
    ['default', 'Not enabled', 'Enable push notifications'],
    ['denied', 'Notifications are blocked', null],
    ['enabled', 'Enabled on this device', 'Disable on this device'],
    ['unconfigured', 'The developer has not configured', null],
  ]) {
    i18n.global.locale.value = 'en'
    const html = await renderToString(createSSRApp(PushDevicePanel, { state }).use(i18n))
    assert.ok(html.includes(text))
    assert.equal(html.includes('<button'), !!action)
    if (action) assert.ok(html.includes(action))
  }
  i18n.global.locale.value = 'ru'
  const russian = await renderToString(
    createSSRApp(PushDevicePanel, { state: 'default', error: true }).use(i18n),
  )
  assert.ok(russian.includes('Включить push-уведомления'))
  assert.ok(russian.includes('role="alert"'))
  assert.ok(russian.includes('Попробовать снова'))
})

test('developer editor renders localized dirty state and explicit save for all private fields', async () => {
  for (const [language, dirty, save] of [
    ['en', 'Unsaved changes', 'Save changes'],
    ['ru', 'Есть несохранённые изменения', 'Сохранить изменения'],
  ]) {
    const html = await render(
      TaskView,
      '/task/demo-1',
      (store) => {
        store.currentMode = 'developer'
        store.updateDeveloperData('demo-1', {
          developerNotes: 'Local draft',
          estimateHours: 12,
          price: 25000,
        })
      },
      language,
    )
    assert.ok(html.includes(dirty))
    assert.ok(html.includes(save))
    assert.ok(html.includes('Local draft'))
    assert.ok(html.includes('value="12"'))
    assert.ok(html.includes('value="25000"'))
    const clean = await render(
      TaskView,
      '/task/demo-1',
      (store) => {
        store.currentMode = 'developer'
      },
      language,
    )
    assert.equal(clean.includes(dirty), false)
    assert.equal(clean.includes(save), false)
  }
})

test('actual create and edit TaskForm apply AI text locally without saving or changing other fields', async () => {
  const TaskForm = (await server.ssrLoadModule('/src/components/TaskForm.vue')).default
  i18n.global.locale.value = 'en'
  for (const editing of [false, true]) {
    const pinia = createPinia()
    setActivePinia(pinia)
    const store = useProjectStore(pinia)
    store.currentMode = 'client'
    const task = editing ? store.findTask('demo-1') : undefined
    const originalTasks = JSON.stringify(store.tasks)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: TaskForm },
        { path: '/task/:id', component: TaskForm },
      ],
    })
    await router.push('/')
    const instances = new Map()
    const app = createSSRApp(TaskForm, { task }).use(pinia).use(router).use(i18n)
    app.mixin({
      created() {
        instances.set(this.$options.__name, this.$)
      },
    })
    const html = await renderToString(app)
    const form = instances.get('TaskForm').setupState
    const assistant = instances.get('AiTaskAssistant').setupState
    const originalLocation = form.location
    const originalPriority = form.priority
    const originalAttachments = [...form.attachments]
    let saves = 0
    store.addTask = store.updateTask = async () => {
      saves++
      return true
    }
    assistant.open = true
    assistant.response = {
      title: '<script>alert(1)</script>',
      description: 'AI description',
      expectedResult: 'AI result',
      questions: [],
      suggestedIdeas: [],
    }
    assistant.draft = {
      title: 'Reviewed title',
      description: 'Reviewed description',
      expectedResult: 'Reviewed result',
    }

    assert.equal(assistant.applyResult(), true)
    assert.equal(form.title, 'Reviewed title')
    assert.equal(form.description, 'Reviewed description')
    assert.equal(form.expectedResult, 'Reviewed result')
    assert.equal(form.location, originalLocation)
    assert.equal(form.priority, originalPriority)
    assert.deepEqual(form.attachments, originalAttachments)
    assert.equal(saves, 0)
    assert.equal(JSON.stringify(store.tasks), originalTasks)
    assert.equal(assistant.open, false)
    assert.ok(html.includes('AI is unavailable in the local demo'))
  }
})

test('actual AI dialog escapes model text in editable fields, questions and split suggestions', async () => {
  const AiTaskAssistant = (await server.ssrLoadModule('/src/components/AiTaskAssistant.vue'))
    .default
  const hostile = '<script>alert(1)</script>'
  const task = { title: hostile, description: hostile, expectedResult: hostile }
  const app = createSSRApp(AiTaskAssistant, {
    source: { title: 'Task', description: 'Brief', expectedResult: '' },
    context: { apiMode: true, projectId: 'project', isDeveloper: false },
  }).use(i18n)
  app.mixin({
    created() {
      if (this.$options.__name === 'AiTaskAssistant') {
        Object.assign(this.$.setupState, {
          open: true,
          draft: task,
          response: { ...task, questions: [hostile], suggestedIdeas: [task] },
        })
      }
    },
  })

  const html = await renderToString(app)

  assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'))
  assert.ok(html.includes('What else should we clarify?'))
  assert.ok(html.includes('This idea might work better as separate tasks'))
  assert.ok(!html.includes(hostile))
})

test('AI trigger guards empty descriptions, saving and pending without requiring other fields', async () => {
  const AiTaskAssistant = (await server.ssrLoadModule('/src/components/AiTaskAssistant.vue'))
    .default
  const service = (await server.ssrLoadModule('/src/services/ideaAssistant.js')).ideaAssistant
  const original = service.help
  let requests = 0
  service.help = async () => {
    requests++
    throw new Error('unexpected request')
  }
  try {
    for (const [source, disabled, pending, allowed] of [
      [{ title: 'Title', description: '  ', expectedResult: 'Result' }, false, false, false],
      [{ description: 'Only a description' }, false, false, true],
      [{ description: 'Description' }, true, false, false],
      [{ description: 'Description' }, false, true, false],
    ]) {
      let setup
      const app = createSSRApp(AiTaskAssistant, {
        source,
        disabled,
        context: { apiMode: true, projectId: 'project' },
      }).use(i18n)
      app.mixin({
        created() {
          if (this.$options.__name === 'AiTaskAssistant') setup = this.$.setupState
        },
      })
      const html = await renderToString(app)
      setup.pending = pending
      setup.openAssistant()
      assert.equal(setup.open, allowed)
      assert.equal(requests, 0)
      if (!allowed) {
        setup.requestSuggestion()
        assert.equal(requests, 0)
      }
      if (!source.description.trim()) {
        assert.match(html, /aria-disabled="true"/)
        assert.match(html, /aria-describedby="[^"]+"/)
        assert.ok(html.includes('Describe your idea first'))
        assert.equal(/class="ai-button" disabled/.test(html), false)
      }
      setup.dispose()
    }
  } finally {
    service.help = original
  }
})

test('AI ready view explains manual application and an empty expected result in both locales', async () => {
  const AiTaskAssistant = (await server.ssrLoadModule('/src/components/AiTaskAssistant.vue'))
    .default
  for (const language of ['ru', 'en']) {
    i18n.global.locale.value = language
    const task = { title: 'Title', description: 'Description', expectedResult: '' }
    const app = createSSRApp(AiTaskAssistant, {
      source: task,
      context: { apiMode: true, projectId: 'project' },
    }).use(i18n)
    app.mixin({
      created() {
        if (this.$options.__name === 'AiTaskAssistant')
          Object.assign(this.$.setupState, {
            open: true,
            draft: task,
            response: { ...task, questions: ['Question'], suggestedIdeas: [] },
          })
      },
    })
    const html = await renderToString(app)
    for (const key of [
      'ready',
      'review',
      'questions',
      'questionsNote',
      'emptyResult',
      'saveReminder',
      'close',
      'apply',
    ])
      assert.ok(html.includes(i18n.global.t('ai.' + key)), key)
  }
})
