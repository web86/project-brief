import * as Vue from 'vue'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import { readFile } from 'node:fs/promises'
import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { createRenderer, ssrContextKey, nextTick } from 'vue'

let server, useProjectStore, api, toast, routerFactory, TaskView, App, DeveloperNotes, i18n
// Use the actual client template with the SSR-loaded setup, without a DOM dependency.
async function clientComponent(filename) {
  const component = (await server.ssrLoadModule('/' + filename)).default
  const { descriptor } = parse(await readFile(filename, 'utf8'), { filename })
  const script = compileScript(descriptor, { id: filename })
  const template = compileTemplate({
    source: descriptor.template.content,
    filename,
    id: filename,
    compilerOptions: { bindingMetadata: script.bindings },
  })
  assert.deepEqual(template.errors, [])
  const code = template.code
    .replace(
      /import \{([^}]+)\} from "vue"/g,
      (_, names) => 'const {' + names.replace(/ as /g, ': ') + '} = Vue',
    )
    .replace('export function render', 'function render')
  component.render = new Function('Vue', code + '\nreturn render')(Vue)
  return component
}
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
  toast = (await server.ssrLoadModule('/src/services/toast.js')).toast
  routerFactory = (await server.ssrLoadModule('/src/router/index.js')).createProjectRouter
  TaskView = (await server.ssrLoadModule('/src/views/TaskView.vue')).default
  App = (await server.ssrLoadModule('/src/App.vue')).default
  await clientComponent('src/components/LoadingButton.vue')
  DeveloperNotes = await clientComponent('src/components/DeveloperNotes.vue')
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => {
  toast?.clear()
  await server?.close()
})
beforeEach(() => {
  setActivePinia(createPinia())
  toast.clear()
})
const task = () => ({
  id: 'task',
  title: 'Saved',
  developerNotes: 'Saved notes',
  estimateHours: 3,
  price: null,
  status: 'new',
  history: [],
  comments: [],
})
function setup() {
  const store = useProjectStore()
  assert.equal(store.apiMode, true)
  store.admin = { id: 1 }
  store.project = { id: 'project' }
  store.tasks = [task()]
  const calls = []
  api.request = async (path, options) => {
    calls.push({ path, ...options })
    return { data: { ...task(), ...options?.body } }
  }
  return { store, calls }
}

test('typing stays local after any timer delay; reverting fields clears dirty state', async (context) => {
  const { store, calls } = setup()
  context.mock.timers.enable({ apis: ['setTimeout'] })
  store.updateDeveloperData('task', { developerNotes: 'First' })
  context.mock.timers.tick(451)
  store.updateDeveloperData('task', { developerNotes: 'Current', price: 25000 })
  context.mock.timers.tick(60000)
  assert.deepEqual(calls, [])
  assert.equal(store.hasDeveloperChanges('task'), true)
  assert.equal(store.findTask('task').developerNotes, 'Saved notes')
  assert.equal(store.developerTask('task').developerNotes, 'Current')
  assert.equal(store.findTask('task').history.length, 0)
  store.updateDeveloperData('task', { developerNotes: 'Saved notes', price: null })
  assert.equal(store.hasDeveloperChanges('task'), false)
})

test('one explicit save sends only current changed fields, clears dirty, and uses success toast', async () => {
  const { store, calls } = setup()
  i18n.global.locale.value = 'en'
  store.updateDeveloperData('task', { developerNotes: 'First', estimateHours: 3 })
  store.updateDeveloperData('task', { developerNotes: 'Final', estimateHours: 12, price: 25000 })
  assert.equal(await store.saveDeveloperData('task'), true)
  assert.deepEqual(calls, [
    {
      path: '/api/admin/tasks/task',
      method: 'PATCH',
      body: { developerNotes: 'Final', estimateHours: 12, price: 25000 },
    },
  ])
  assert.equal(store.hasDeveloperChanges('task'), false)
  assert.equal(store.findTask('task').developerNotes, 'Final')
  assert.equal(toast.items.value[0].type, 'success')
  assert.equal(toast.items.value[0].message(), 'Changes saved.')
  assert.equal(await store.saveDeveloperData('task'), false)
  assert.equal(calls.length, 1)
})

test('failed save including expired session preserves draft, shows global error, and retries', async () => {
  const { store, calls } = setup()
  const successful = api.request
  api.request = async () => {
    throw { status: 401, message: 'Expired' }
  }
  store.updateDeveloperData('task', { developerNotes: 'Do not lose this', estimateHours: 12 })
  assert.equal(await store.saveDeveloperData('task'), false)
  assert.equal(store.savingDeveloper, false)
  assert.equal(store.hasDeveloperChanges('task'), true)
  assert.equal(store.developerTask('task').developerNotes, 'Do not lose this')
  assert.equal(store.findTask('task').developerNotes, 'Saved notes')
  assert.equal(store.sessionLost, false)
  assert.equal(toast.items.value[0].type, 'error')
  api.request = successful
  assert.equal(await store.saveDeveloperData('task'), true)
  assert.equal(calls.length, 1)
})

test('pending save does not duplicate requests or erase edits typed while it is in flight', async () => {
  const { store } = setup()
  let resolve,
    calls = 0
  api.request = async () => {
    calls++
    return new Promise((done) => {
      resolve = done
    })
  }
  store.updateDeveloperData('task', { developerNotes: 'Sending' })
  const pending = store.saveDeveloperData('task')
  await new Promise((done) => setImmediate(done))
  assert.equal(store.savingDeveloper, true)
  assert.equal(await store.saveDeveloperData('task'), false)
  store.updateDeveloperData('task', { developerNotes: 'Saved notes' })
  resolve({ data: { ...task(), developerNotes: 'Sending' } })
  assert.equal(await pending, true)
  assert.equal(calls, 1)
  assert.equal(store.developerTask('task').developerNotes, 'Saved notes')
  assert.equal(store.hasDeveloperChanges('task'), true)
})

function browser() {
  const listeners = new Map()
  return {
    confirm: () => false,
    addEventListener: (name, fn) => listeners.set(name, fn),
    removeEventListener: (name) => listeners.delete(name),
    listeners,
  }
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

test('real task editor intercepts Ctrl/Cmd+S only when active and dirty; beforeunload follows dirty state', async () => {
  const { store, calls } = setup()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: false })
  await router.push('/task/task')
  globalThis.window = browser()
  const app = renderer
    .createApp({ ...TaskView, render: () => null })
    .use(store._p)
    .use(router)
    .use(i18n)
    .provide(ssrContextKey, {})
  try {
    app.mount({})
    assert.equal(window.listeners.has('beforeunload'), false)
    let prevented = 0
    const key = (mod) => ({ key: 's', [mod]: true, preventDefault: () => prevented++ })
    window.listeners.get('keydown')(key('ctrlKey'))
    assert.equal(prevented, 0)
    for (const mod of ['ctrlKey', 'metaKey']) {
      store.updateDeveloperData('task', { developerNotes: mod })
      assert.ok(window.listeners.has('beforeunload'))
      const unload = { preventDefault: () => prevented++ }
      window.listeners.get('beforeunload')(unload)
      assert.equal(unload.returnValue, '')
      window.listeners.get('keydown')(key(mod))
      await new Promise((done) => setImmediate(done))
      assert.equal(store.hasDeveloperChanges('task'), false)
      assert.equal(window.listeners.has('beforeunload'), false)
    }
    assert.equal(calls.length, 2)
    assert.equal(prevented, 4)
    store.updateDeveloperData('task', { price: 42 })
    store.admin = null
    await nextTick()
    window.listeners.get('keydown')(key('ctrlKey'))
    assert.equal(prevented, 4)
    assert.equal(window.listeners.has('beforeunload'), false)
  } finally {
    app.unmount()
    delete globalThis.window
  }
})

test('real router blocks dirty task navigation and task changes; clean navigation has no warning or PATCH', async () => {
  const { store, calls } = setup()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: false })
  await router.push('/task/task')
  let confirmations = 0,
    allowed = false
  globalThis.window = {
    confirm: () => {
      confirmations++
      return allowed
    },
  }
  try {
    store.updateDeveloperData('task', { developerNotes: 'Draft' })
    await router.push('/task/other')
    assert.equal(router.currentRoute.value.path, '/task/task')
    assert.equal(store.developerTask('task').developerNotes, 'Draft')
    await router.push('/')
    assert.equal(router.currentRoute.value.path, '/task/task')
    assert.equal(confirmations, 2)
    allowed = true
    await router.push('/')
    assert.equal(store.hasDeveloperChanges('task'), false)
    assert.equal(confirmations, 3)
    await router.push('/task/task')
    assert.equal(confirmations, 3)
    assert.deepEqual(calls, [])
  } finally {
    delete globalThis.window
  }
})

test('immediate status and comment actions never include or silently save a private draft', async () => {
  const { store, calls } = setup()
  store.updateDeveloperData('task', { developerNotes: 'Private draft' })
  await store.changeStatus('task', 'review')
  await store.addComment('task', 'Public comment')
  assert.deepEqual(
    calls.map(({ body }) => body),
    [{ status: 'review' }, { text: 'Public comment' }],
  )
  assert.equal(store.developerTask('task').developerNotes, 'Private draft')
  assert.equal(store.hasDeveloperChanges('task'), true)
})

test('logout warns before discarding, preserves draft on cancellation/failure, and never PATCHes it', async () => {
  const { store, calls } = setup()
  const router = routerFactory({ history: createMemoryHistory(), apiMode: false })
  await router.push('/task/task')
  let confirmations = 0,
    allowed = false
  globalThis.window = {
    confirm: () => {
      confirmations++
      return allowed
    },
  }
  globalThis.document = { title: '', getElementById: () => null }
  const app = renderer
    .createApp({ ...App, render: () => null })
    .use(store._p)
    .use(router)
    .use(i18n)
    .provide(ssrContextKey, {})
  try {
    const instance = app.mount({})
    store.updateDeveloperData('task', { developerNotes: 'Keep this' })
    await instance.$.setupState.logout()
    assert.equal(confirmations, 1)
    assert.deepEqual(calls, [])
    assert.equal(store.hasDeveloperChanges('task'), true)
    allowed = true
    const success = api.request
    api.request = async () => {
      throw new Error('Offline')
    }
    await instance.$.setupState.logout()
    assert.equal(store.hasDeveloperChanges('task'), true)
    api.request = success
    await instance.$.setupState.logout()
    assert.deepEqual(calls, [{ path: '/api/admin/logout', method: 'POST' }])
    assert.equal(store.admin, null)
    assert.equal(store.hasDeveloperChanges('task'), false)
    assert.equal(router.currentRoute.value.path, '/admin/login')
    store.admin = { id: 1 }
    await instance.$.setupState.logout()
    assert.equal(confirmations, 3)
  } finally {
    app.unmount()
    delete globalThis.window
    delete globalThis.document
  }
})

test('project reload and move/reorder preserve drafts without hidden PATCH requests', async () => {
  const { store } = setup()
  store.sections = [{ id: 'section', position: 1, name: 'General' }]
  Object.assign(store.findTask('task'), { sectionId: 'section', position: 1 })
  const calls = []
  api.request = async (path, options = {}) => {
    calls.push({ path, ...options })
    if (path === '/api/admin/me') return { user: { id: 1 } }
    if (path === '/api/admin/projects/project')
      return { data: { id: 'project', sections: store.sections } }
    if (path === '/api/admin/projects/project/tasks')
      return { data: [{ ...task(), sectionId: 'section', position: 1 }] }
    return { data: { ...task(), sectionId: 'section', position: 1 } }
  }
  store.updateDeveloperData('task', { developerNotes: 'Private draft' })
  await store.loadApiProject('project', true)
  await store.moveTask('task', 'section')
  await store.reorderTask('task', 'up')
  assert.equal(store.developerTask('task').developerNotes, 'Private draft')
  assert.equal(store.hasDeveloperChanges('task'), true)
  assert.equal(
    calls.some((call) => call.method === 'PATCH'),
    false,
  )
  assert.deepEqual(
    calls.filter((call) => call.method === 'POST').map(({ body }) => body),
    [{ sectionId: 'section' }, { direction: 'up' }],
  )
})

test('actual notes textarea and Save button use the local draft and send exactly one PATCH', async () => {
  const { store, calls } = setup()
  const elements = []
  const ui = createRenderer({
    insert() {},
    remove() {},
    createElement(tag) {
      const node = { tag, props: {}, getBoundingClientRect: () => ({ width: 200, height: 44 }) }
      elements.push(node)
      return node
    },
    createText: () => ({}),
    createComment: () => ({}),
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp(node, key, previous, next) {
      node.props[key] = next
    },
  })
  const app = ui
    .createApp(DeveloperNotes, { task: store.developerTask('task') })
    .use(store._p)
    .use(i18n)
    .provide(ssrContextKey, {})
  try {
    app.mount({})
    elements
      .find((node) => node.tag === 'textarea')
      .props.onInput({ target: { value: 'Current notes from UI' } })
    await nextTick()
    assert.equal(store.hasDeveloperChanges('task'), true)
    assert.deepEqual(calls, [])
    const button = elements.find((node) => node.tag === 'button')
    assert.equal(button.props.disabled, false)
    button.props.onClick()
    await new Promise((done) => setImmediate(done))
    assert.deepEqual(calls, [
      {
        path: '/api/admin/tasks/task',
        method: 'PATCH',
        body: { developerNotes: 'Current notes from UI' },
      },
    ])
    assert.equal(store.hasDeveloperChanges('task'), false)
    assert.equal(button.props.disabled, true)
    assert.equal(toast.items.value.length, 1)
    assert.equal(toast.items.value[0].type, 'success')
  } finally {
    app.unmount()
  }
})
