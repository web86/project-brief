import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp, createRenderer, ssrContextKey, nextTick } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { renderToString } from '@vue/server-renderer'
import { createApiClient } from '../src/api/client.js'
let server,
  Section,
  Export,
  ProjectView,
  TaskView,
  storeFactory,
  managementFactory,
  api,
  toast,
  i18n,
  routerFactory,
  collapse
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  const load = (path) => server.ssrLoadModule('/src/' + path)
  Section = (await load('components/SectionBlock.vue')).default
  Export = (await load('views/AdminProjectExportView.vue')).default
  ProjectView = (await load('views/ProjectView.vue')).default
  TaskView = (await load('views/TaskView.vue')).default
  storeFactory = (await load('stores/project.js')).useProjectStore
  managementFactory = (await load('stores/projectManagement.js')).useProjectManagementStore
  api = (await load('api/client.js')).api
  toast = (await load('services/toast.js')).toast
  i18n = (await load('i18n/index.js')).i18n
  routerFactory = (await load('router/index.js')).createProjectRouter
  collapse = await load('utils/sectionCollapse.js')
})
after(async () => {
  toast?.clear()
  await server?.close()
})
beforeEach(() => {
  setActivePinia(createPinia())
  toast.clear()
  delete globalThis.localStorage
  i18n.global.locale.value = 'en'
})
function storage() {
  const values = new Map()
  globalThis.localStorage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  }
  return values
}
const renderer = createRenderer({
  insert() {},
  remove() {},
  createElement: () => ({}),
  createText: () => ({}),
  createComment: () => ({}),
  setText() {},
  setElementText() {},
  parentNode: () => null,
  nextSibling: () => null,
  patchProp() {},
})
function mount(component, props = {}, router = routerFactory({ apiMode: false })) {
  const app = renderer
    .createApp({ ...component, render: () => null }, props)
    .use(storeFactory()._p)
    .use(router)
    .use(i18n)
    .provide(ssrContextKey, {})
  const instance = app.mount({})
  return { app, state: instance.$.setupState }
}
async function html(component, props = {}, router = routerFactory({ apiMode: false })) {
  return renderToString(createSSRApp(component, props).use(storeFactory()._p).use(router).use(i18n))
}

test('export route uses summary only, normal project shell and accessible Export tab after Settings', async () => {
  const calls = []
  const request = async (path) => {
    calls.push(path)
    if (path === '/api/admin/me') return { user: { name: 'Owner' }, workspace: { id: 'workspace' } }
    if (path === '/api/admin/workspaces') return { data: [] }
    if (path === '/api/admin/projects') return { data: [] }
    if (path.endsWith('/tasks')) return { data: [] }
    return { data: { id: 'project', name: 'Project', sections: [] } }
  }
  api.request = request
  const router = routerFactory({ apiMode: true, history: createMemoryHistory(), request })
  await router.push('/admin/projects/project/export')
  assert.equal(router.currentRoute.value.meta.projectSection, 'export')
  assert.equal(router.currentRoute.value.meta.adminShell, true)
  assert.deepEqual(calls, [
    '/api/admin/me',
    '/api/admin/workspaces',
    '/api/admin/projects',
    '/api/admin/projects/project?summary=1',
  ])
  const Shell = (await server.ssrLoadModule('/src/views/AdminProjectShell.vue')).default
  const output = await html(Shell, {}, router)
  assert.match(
    output,
    /<a(?=[^>]*href="\/admin\/projects\/project\/export")(?=[^>]*aria-current="page")[^>]*>/,
  )
  assert.ok(output.indexOf('/settings') < output.indexOf('/export'))
  await router.push('/project/project')
  assert.equal(router.currentRoute.value.meta.adminShell, undefined)
})

test('actual RU/EN export form defaults, every format/scope/language and optional field are available', async () => {
  managementFactory().adopt({ id: 'project' })
  for (const [lang, title, optional] of [
    ['en', 'Export specification', 'Estimate hours'],
    ['ru', 'Экспорт технического задания', 'Оценка часов'],
  ]) {
    i18n.global.locale.value = lang
    const output = await html(Export)
    for (const label of [title, optional, 'PDF', 'Word (.docx)', 'Русский', 'English'])
      assert.ok(output.includes(label), label)
    assert.match(output, /<input(?=[^>]*checked)(?=[^>]*value="pdf")(?=[^>]*name="format")[^>]*>/)
    assert.match(output, /<input(?=[^>]*checked)(?=[^>]*value="all")(?=[^>]*name="scope")[^>]*>/)
    assert.match(
      output,
      new RegExp(`<input(?=[^>]*checked)(?=[^>]*value="${lang}")(?=[^>]*name="language")[^>]*>`),
    )
    assert.equal(
      (output.match(/<input(?=[^>]*checked)(?=[^>]*type="checkbox")[^>]*>/g) || []).length,
      4,
    )
    assert.equal((output.match(/type="checkbox"/g) || []).length, 10)
  }
})

test('download submits current controls with loading guard, saves binary and presents empty/API errors', async () => {
  managementFactory().adopt({ id: 'project' })
  const { app, state } = mount(Export)
  const calls = [],
    downloads = []
  let finish
  const previousDocument = globalThis.document
  globalThis.document = {
    documentElement: {},
    body: { append() {} },
    createElement: () => ({
      click() {
        downloads.push(this.download)
      },
      remove() {},
    }),
  }
  try {
    api.request = (path, options) => {
      calls.push({ path, ...options })
      return new Promise((resolve) => {
        finish = resolve
      })
    }
    state.format = 'docx'
    state.scope = 'approved'
    state.language = 'ru'
    state.fields.estimateHours = true
    state.fields.price = true
    state.documentTitle = 'План работ'
    const pending = state.download()
    assert.equal(state.pending, true)
    await state.download()
    assert.equal(calls.length, 1)
    assert.deepEqual(calls[0], {
      path: '/api/admin/projects/project/export',
      method: 'POST',
      responseType: 'blob',
      body: {
        format: 'docx',
        scope: 'approved',
        locale: 'ru',
        documentTitle: 'План работ',
        pageBreakBySection: false,
        fields: {
          description: true,
          expectedResult: true,
          priority: true,
          status: true,
          estimateHours: true,
          price: true,
          attachments: false,
          approvalDate: false,
          developerNotes: false,
        },
      },
    })
    finish({ blob: new Blob(['PK']), filename: 'project.docx' })
    await pending
    assert.equal(state.pending, false)
    assert.deepEqual(downloads, ['project.docx'])
    for (const lang of ['ru', 'en']) {
      const message =
        lang === 'ru'
          ? 'Нет задач для выбранного варианта экспорта.'
          : 'No tasks for the selected export option.'
      api.request = async () => {
        throw { status: 422, errors: { scope: [message] } }
      }
      await state.download()
      assert.equal(state.error, message)
      assert.equal(toast.items.value.at(-1).message, message)
      assert.equal(state.pending, false)
    }
    api.request = async () => {
      throw { status: 500, message: 'Secret SQL' }
    }
    await state.download()
    assert.equal(state.error.includes('Secret SQL'), false)
    assert.equal(toast.items.value.at(-1).type, 'error')
    assert.match(await html(Export), /class="export-form|export-form/)
  } finally {
    app.unmount()
    globalThis.document = previousDocument
  }
})

test('binary API requests retain CSRF/JSON error handling and use only safe response filenames', async () => {
  const calls = []
  let status = 200,
    disposition = 'attachment; filename=project-specification-2026-10-19.pdf'
  const client = createApiClient({
    fetchImpl: async (url, options) => {
      calls.push({ url, options })
      if (url === '/api/csrf') return new Response(JSON.stringify({ token: 'csrf' }))
      return new Response(
        status === 200 ? '%PDF-content' : JSON.stringify({ errors: { scope: ['No tasks'] } }),
        { status, headers: { 'Content-Disposition': disposition } },
      )
    },
  })
  const options = { method: 'POST', responseType: 'blob', body: { format: 'pdf' } }
  const download = await client.request('/api/admin/projects/project/export', options)
  assert.equal(await download.blob.text(), '%PDF-content')
  assert.equal(download.filename, 'project-specification-2026-10-19.pdf')
  assert.equal(calls[1].options.headers['X-CSRF-TOKEN'], 'csrf')
  assert.equal(calls[1].options.credentials, 'same-origin')
  disposition = 'attachment; filename="../../unsafe.pdf"'
  assert.equal((await client.request('/export', options)).filename, 'project-specification')
  status = 422
  await assert.rejects(
    client.request('/export', options),
    (error) => error.status === 422 && error.errors.scope[0] === 'No tasks',
  )
})

test('sections expand by default, toggle independently, persist per project/section and survive renaming/reordering', async () => {
  const values = storage(),
    store = storeFactory()
  store.project.id = 'project-A'
  const props = { id: 'section-A', name: 'Main', position: 1, tasks: [], totalCount: 12 }
  const first = mount(Section, props)
  const second = mount(Section, { ...props, id: 'section-B' })
  try {
    assert.equal(first.state.collapsed, false)
    let output = await html(Section, props)
    assert.match(
      output,
      /<button type="button" class="section-toggle" aria-expanded="true" aria-controls="section-tasks-section-A"/,
    )
    assert.ok(output.includes('12 ideas'))
    first.state.toggle()
    await nextTick()
    assert.equal(first.state.collapsed, true)
    assert.equal(second.state.collapsed, false)
    assert.deepEqual([...values], [['projectbrief:collapsed:v1:project-A:section-A', 'true']])
    output = await html(Section, { ...props, name: 'Renamed', position: 2 })
    assert.match(output, /aria-expanded="false"/)
    assert.match(output, /id="section-tasks-section-A" style="display:none/)
    store.project.id = 'project-B'
    await nextTick()
    assert.equal(first.state.collapsed, false)
    store.project.id = 'project-A'
    await nextTick()
    assert.equal(first.state.collapsed, true)
    first.state.toggle()
    await nextTick()
    assert.equal(first.state.collapsed, false)
    assert.equal(values.size, 0)
  } finally {
    first.app.unmount()
    second.app.unmount()
  }
})

test('corrupt/unavailable preferences always fall back to expanded and never interrupt toggling', () => {
  const values = storage()
  values.set('projectbrief:collapsed:v1:project:section', '{invalid')
  assert.equal(collapse.readSectionCollapsed('project', 'section'), false)
  for (const malformed of ['null', '[]', '{}', '"true"', '1']) {
    values.set('projectbrief:collapsed:v1:project:section', malformed)
    assert.equal(collapse.readSectionCollapsed('project', 'section'), false)
  }
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    get() {
      throw new Error('Blocked')
    },
  })
  assert.equal(collapse.readSectionCollapsed('project', 'section'), false)
  assert.doesNotThrow(() => collapse.writeSectionCollapsed('project', 'section', true))
  delete globalThis.localStorage
})

test('client/admin brief share collapse with separate order controls; task deep links remain rendered', async () => {
  storage()
  const store = storeFactory(),
    router = routerFactory({ apiMode: false })
  await router.push('/task/demo-1')
  const task = store.findTask('demo-1')
  collapse.writeSectionCollapsed(store.project.id, task.sectionId, true)
  for (const mode of ['client', 'developer']) {
    store.currentMode = mode
    const output = await html(ProjectView, {}, router)
    assert.ok(output.includes('aria-expanded="false"'))
    assert.equal(output.includes('section-order-actions'), mode === 'developer')
    assert.equal(output.includes('/export'), false)
    assert.ok((await html(TaskView, {}, router)).includes(task.title))
    const section = await html(
      Section,
      { id: task.sectionId, name: 'Main', position: 1, tasks: [task] },
      router,
    )
    const headingButton = section.match(/<button[^>]*section-toggle[\s\S]*?<\/button>/)[0]
    assert.equal(headingButton.includes('order-icon'), false)
    assert.equal(
      headingButton.includes('onkeydown'),
      false,
      'native button handles Enter and Space',
    )
  }
})

test('document title defaults follow document language until customized; optional controls default off', async () => {
  managementFactory().adopt({ id: 'project' })
  const { app, state } = mount(Export)
  try {
    assert.equal(state.documentTitle, 'Technical specification')
    assert.equal(state.pageBreakBySection, false)
    assert.equal(state.fields.attachments, false)
    assert.equal(state.fields.approvalDate, false)
    state.language = 'ru'
    await nextTick()
    assert.equal(state.documentTitle, 'Техническое задание')
    state.documentTitle = 'Перечень доработок'
    state.language = 'en'
    await nextTick()
    assert.equal(state.documentTitle, 'Перечень доработок')
    state.documentTitle = ''
    state.language = 'ru'
    await nextTick()
    assert.equal(state.documentTitle, 'Техническое задание')
    for (const [language, labels, defaultTitle] of [
      [
        'en',
        [
          'Document title',
          'Attachment list',
          'Approval date',
          'Start each section on a new page',
          'Completed tasks only',
        ],
        'Technical specification',
      ],
      [
        'ru',
        [
          'Заголовок документа',
          'Список вложений',
          'Дата согласования',
          'Каждый раздел с новой страницы',
          'Только готовые',
        ],
        'Техническое задание',
      ],
    ]) {
      i18n.global.locale.value = language
      const output = await html(Export)
      for (const label of labels) assert.ok(output.includes(label), label)
      assert.match(
        output,
        new RegExp(
          `<input(?=[^>]*id="export-document-title")(?=[^>]*value="${defaultTitle}")(?=[^>]*maxlength="160")[^>]*>`,
        ),
      )
      assert.match(output, /type="radio" value="done" name="scope"/)
      assert.equal(output.includes('Show totals'), false)
      assert.equal(output.includes('Показывать итоги'), false)
    }
  } finally {
    app.unmount()
  }
})

test('refined export payload trims custom title, supports completed scope and opts into each new field', async () => {
  managementFactory().adopt({ id: 'project' })
  const { app, state } = mount(Export)
  const calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    throw { status: 422 }
  }
  try {
    await state.download()
    assert.equal(calls[0].body.documentTitle, 'Technical specification')
    assert.equal(calls[0].body.pageBreakBySection, false)
    assert.equal(calls[0].body.fields.attachments, false)
    assert.equal(calls[0].body.fields.approvalDate, false)
    assert.equal(calls[0].body.fields.estimateHours, false)
    assert.equal(calls[0].body.fields.price, false)
    assert.equal('totals' in calls[0].body, false)
    state.documentTitle = '  Смета <работ> & план  '
    state.scope = 'done'
    state.format = 'pdf'
    state.fields.attachments = true
    state.fields.approvalDate = true
    state.fields.estimateHours = true
    state.fields.price = true
    state.pageBreakBySection = true
    state.language = 'ru'
    await nextTick()
    await state.download()
    assert.equal(calls[1].body.documentTitle, 'Смета <работ> & план')
    assert.equal(calls[1].body.format, 'pdf')
    assert.equal(calls[1].body.scope, 'done')
    assert.equal(calls[1].body.locale, 'ru')
    assert.equal(calls[1].body.pageBreakBySection, true)
    assert.equal(calls[1].body.fields.attachments, true)
    assert.equal(calls[1].body.fields.approvalDate, true)
    assert.equal(calls[1].body.fields.estimateHours, true)
    assert.equal(calls[1].body.fields.price, true)
    assert.equal('totals' in calls[1].body, false)
  } finally {
    app.unmount()
  }
})
