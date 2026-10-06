import { locale } from '../src/i18n/index.js'
import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { useProjectStore, isValidSnapshot } from '../src/stores/project.js'
import { STORAGE_KEY, getTaskLocation } from '../src/constants/project.js'
import { applyQuickStatusAction, getQuickStatusActions } from '../src/utils/taskWorkflow.js'
import { getProjectCurrencySymbol } from '../src/utils/currency.js'

class MemoryStorage {
  values = new Map()
  failWrites = false
  getItem(key) {
    return this.values.get(key) ?? null
  }
  setItem(key, value) {
    if (this.failWrites) throw new Error('QuotaExceededError')
    this.values.set(key, String(value))
  }
}

let storage
beforeEach(() => {
  locale.value = 'ru'
  storage = new MemoryStorage()
  Object.defineProperty(globalThis, 'localStorage', { value: storage, configurable: true })
  setActivePinia(createPinia())
})

const createStore = () => {
  const store = useProjectStore()
  store.loadFromStorage()
  return store
}
const idea = () => ({
  title: '  Большая галерея  ',
  section: 'Карточка товара',
  description: 'Хочу видеть детали',
  expectedResult: 'Листать фотографии',
  priority: 'high',
  attachments: [
    {
      id: 'sample-file',
      name: 'example.png',
      size: 128,
      type: 'image/png',
      preview: 'data:image/png;base64,aGVsbG8=',
    },
  ],
})

test('first launch seeds nine ideas, all six statuses and consistent filter counts', () => {
  const store = createStore()
  assert.equal(store.tasks.length, 9)
  assert.equal(new Set(store.tasks.map((task) => task.status)).size, 6)
  assert.equal(store.progress, 33)
  assert.equal(
    store.counts.ideas + store.counts.approved + store.counts.working + store.counts.done,
    store.counts.all,
  )
  assert.ok(isValidSnapshot(JSON.parse(storage.getItem(STORAGE_KEY))))
})

test('complete client/developer workflow survives a new store and reload', () => {
  const store = createStore()
  const id = store.addTask(idea())
  assert.equal(store.findTask(id).title, 'Большая галерея')
  assert.equal(store.findTask(id).status, 'new')
  assert.equal(store.addComment(id, '  Мне нравится!  '), true)
  assert.equal(store.approveTask(id), true)
  assert.equal(store.approveTask(id), false)
  assert.equal(store.findTask(id).status, 'new')
  store.setMode('developer')
  store.changeStatus(id, 'in_progress')
  store.updateDeveloperData(id, {
    estimateHours: 3.5,
    price: 15000,
    developerNotes: 'ProductGallery.vue + lightbox',
  })
  store.addComment(id, 'Начинаю работу')
  const original = JSON.parse(JSON.stringify(store.findTask(id)))
  setActivePinia(createPinia())
  const reloaded = createStore()
  assert.deepEqual(JSON.parse(JSON.stringify(reloaded.findTask(id))), original)
  assert.equal(reloaded.currentMode, 'developer')
  assert.equal(original.comments[0].author, 'client')
  assert.equal(original.comments[1].author, 'developer')
  assert.ok(original.history.some((event) => event.type === 'status'))
  assert.ok(original.history.some((event) => event.type === 'approved'))
  assert.ok(original.history.some((event) => event.type === 'developer'))
})

test('client approval never moves completed work back to an earlier status', () => {
  const store = createStore()
  const id = store.addTask(idea())
  store.setMode('developer')
  store.changeStatus(id, 'done')
  store.setMode('client')
  store.approveTask(id)
  assert.equal(store.findTask(id).status, 'done')
  assert.equal(store.findTask(id).clientApproved, true)
})

test('mode restrictions and invalid updates cannot corrupt the project', () => {
  const store = createStore()
  const id = store.addTask(idea())
  assert.equal(store.changeStatus(id, 'done'), false)
  assert.equal(store.deleteTask('missing'), false)
  assert.equal(store.updateDeveloperData(id, { price: 100 }), false)
  assert.equal(store.addComment(id, '   '), false)
  assert.throws(() => store.addTask({ ...idea(), title: ' ' }))
  store.setMode('developer')
  assert.equal(store.changeStatus(id, 'invalid'), false)
  assert.equal(store.updateDeveloperData(id, { estimateHours: -2, price: NaN }), false)
  assert.equal(store.updateTask(id, { id: 'hijacked', status: 'done' }), false)
  assert.equal(store.updateTask(id, { title: 123, expectedResult: null }), false)
  assert.equal(store.addComment(id, null), false)
  assert.equal(store.findTask(id).status, 'new')
  assert.equal(store.findTask(id).estimateHours, null)
  assert.ok(isValidSnapshot(JSON.parse(storage.getItem(STORAGE_KEY))))
})

test('updates, developer deletion and an intentionally empty project persist', () => {
  const store = createStore()
  const id = store.addTask(idea())
  store.updateTask(id, { title: 'Новое название', expectedResult: '', attachments: [] })
  assert.equal(
    JSON.parse(storage.getItem(STORAGE_KEY)).tasks.find((task) => task.id === id).title,
    'Новое название',
  )
  store.setMode('developer')
  for (const task of [...store.tasks]) assert.equal(store.deleteTask(task.id), true)
  setActivePinia(createPinia())
  const reloaded = createStore()
  assert.equal(reloaded.tasks.length, 0)
  assert.equal(reloaded.progress, 0)
})

test('malformed saved data is preserved instead of silently overwritten by demo data', () => {
  storage.setItem(STORAGE_KEY, '{broken-json')
  const store = createStore()
  assert.equal(store.storageBlocked, true)
  assert.ok(store.storageError)
  store.setMode('developer')
  store.saveToStorage()
  assert.equal(storage.getItem(STORAGE_KEY), '{broken-json')
})

test('invalid schema, unknown statuses and unsafe attachment previews are rejected', () => {
  createStore()
  const data = JSON.parse(storage.getItem(STORAGE_KEY))
  data.tasks[0].status = '__proto__'
  assert.equal(isValidSnapshot(data), false)
  data.tasks[0].status = 'new'
  data.tasks[0].attachments = [
    { id: 'bad', name: 'x', size: 1, type: 'text/html', preview: 'javascript:alert(1)' },
  ]
  assert.equal(isValidSnapshot(data), false)
  data.tasks[0].attachments = []
  data.tasks[0].createdAt = 'not a date'
  assert.equal(isValidSnapshot(data), false)
})

test('storage failure keeps edits in memory, shows an error and supports retry', () => {
  const store = createStore()
  storage.failWrites = true
  const id = store.addTask(idea())
  assert.ok(store.storageError)
  assert.ok(store.findTask(id))
  assert.equal(
    JSON.parse(storage.getItem(STORAGE_KEY)).tasks.some((task) => task.id === id),
    false,
  )
  storage.failWrites = false
  assert.equal(store.saveToStorage(), true)
  assert.equal(store.storageError, '')
  assert.ok(JSON.parse(storage.getItem(STORAGE_KEY)).tasks.some((task) => task.id === id))
})

test('legacy version 1 data loads without migration or changes to existing ideas', () => {
  const initial = createStore()
  const legacy = JSON.parse(storage.getItem(STORAGE_KEY))
  assert.ok(legacy.tasks.every((task) => !('location' in task)))
  const originalTasks = JSON.parse(JSON.stringify(initial.tasks))
  setActivePinia(createPinia())
  const restored = createStore()
  assert.equal(restored.storageBlocked, false)
  assert.deepEqual(JSON.parse(JSON.stringify(restored.tasks)), originalTasks)
  assert.equal(getTaskLocation(restored.tasks[0]), 'Главная')
  assert.equal(restored.findTask('demo-5').comments.length, 2)
})

test('custom locations and URLs persist independently of their organizational section', () => {
  const store = createStore()
  const url =
    'https://example.com/catalog/' + 'product-name/'.repeat(35) + '?color=green&size=large#photos'
  const values = ['Главная', 'Страница доставки', 'Корзина', url, 'all']
  const ids = values.map((location) => store.addTask({ ...idea(), location, section: location }))
  values.forEach((location, index) => {
    assert.equal(getTaskLocation(store.findTask(ids[index])), location)
    assert.equal(store.taskSection(store.findTask(ids[index])).name, location)
    assert.ok(store.locationOptions.includes(location))
  })
  store.updateTask(ids[0], { location: 'Мобильное меню' })
  store.updateTask(ids[1], { section: 'Форма заказа' })
  setActivePinia(createPinia())
  const restored = createStore()
  assert.equal(getTaskLocation(restored.findTask(ids[0])), 'Мобильное меню')
  assert.equal(getTaskLocation(restored.findTask(ids[1])), 'Форма заказа')
  assert.equal(getTaskLocation(restored.findTask(ids[3])), url)
  assert.ok(isValidSnapshot(JSON.parse(storage.getItem(STORAGE_KEY))))
})

test('blank locations are rejected and location-only snapshots are accepted', () => {
  const store = createStore()
  assert.throws(() => store.addTask({ ...idea(), location: '  ' }))
  const id = store.addTask({ ...idea(), location: 'Корзина' })
  assert.equal(store.updateTask(id, { location: '' }), false)
  const snapshot = JSON.parse(storage.getItem(STORAGE_KEY))
  delete snapshot.tasks.find((task) => task.id === id).section
  assert.ok(isValidSnapshot(snapshot))
  snapshot.tasks.find((task) => task.id === id).location = 123
  assert.equal(isValidSnapshot(snapshot), false)
})

test('quick actions depend on status and cannot bypass the current mode or storage lock', () => {
  const expected = {
    new: ['understood', 'clarify'],
    clarification: ['understood', 'start'],
    approved: ['start'],
    in_progress: ['review', 'finish'],
    review: ['finish', 'reopen'],
    done: ['reopen'],
  }
  for (const [status, ids] of Object.entries(expected))
    assert.deepEqual(
      getQuickStatusActions({ status }).map((action) => action.id),
      ids,
    )
  assert.deepEqual(getQuickStatusActions({ status: 'unknown' }), [])
  const store = createStore()
  const id = store.addTask(idea())
  assert.equal(applyQuickStatusAction(store, id, 'clarify'), null)
  store.setMode('developer')
  assert.equal(applyQuickStatusAction(store, id, 'finish'), null)
  assert.equal(applyQuickStatusAction(store, 'missing', 'clarify'), null)
  store.storageBlocked = true
  assert.equal(applyQuickStatusAction(store, id, 'clarify'), null)
  assert.equal(store.findTask(id).status, 'new')
})

test('understanding the brief never sets client approval; approved requires the client', () => {
  const store = createStore()
  const id = store.addTask(idea())
  const task = store.findTask(id)
  store.setMode('developer')
  const originalHistory = task.history.length
  assert.ok(applyQuickStatusAction(store, id, 'understood').message)
  assert.equal(task.status, 'new')
  assert.equal(task.clientApproved, false)
  assert.equal(task.history.length, originalHistory)
  const clarification = applyQuickStatusAction(store, id, 'clarify')
  assert.equal(clarification.focusComment, true)
  assert.equal(task.comments.length, 0)
  applyQuickStatusAction(store, id, 'understood')
  assert.equal(task.status, 'new')
  assert.equal(task.clientApproved, false)
  assert.equal(store.approveTask(id), false)
  store.setMode('client')
  store.approveTask(id)
  store.setMode('developer')
  applyQuickStatusAction(store, id, 'understood')
  assert.equal(task.status, 'approved')
  assert.equal(task.clientApproved, true)
  const saved = JSON.parse(storage.getItem(STORAGE_KEY)).tasks.find((item) => item.id === id)
  assert.equal(saved.status, 'approved')
  assert.equal(saved.clientApproved, true)
})

test('quick status transitions use existing history exactly once and preserve scope approval', () => {
  const store = createStore()
  const transitions = [
    ['new', 'clarify', 'clarification'],
    ['clarification', 'start', 'in_progress'],
    ['approved', 'start', 'in_progress'],
    ['in_progress', 'review', 'review'],
    ['in_progress', 'finish', 'done'],
    ['review', 'reopen', 'in_progress'],
    ['review', 'finish', 'done'],
    ['done', 'reopen', 'in_progress'],
  ]
  for (const approved of [false, true]) {
    for (const [from, action, to] of transitions) {
      store.setMode('client')
      const id = store.addTask(idea())
      if (approved) store.approveTask(id)
      store.setMode('developer')
      store.changeStatus(id, from)
      const task = store.findTask(id)
      const before = task.history.length
      assert.ok(applyQuickStatusAction(store, id, action))
      assert.equal(task.status, to)
      assert.equal(task.clientApproved, approved)
      assert.equal(task.history.length, before + 1)
      assert.equal(task.history.at(-1).type, 'status')
      assert.equal(task.comments.length, 0)
    }
  }
  const task = store.tasks[0]
  store.changeStatus(task.id, 'done')
  assert.equal(store.addComment(task.id, 'Уточнение к выполненной работе'), true)
  assert.equal(task.status, 'done')
  assert.equal(task.clientApproved, true)
  assert.equal('resultApproved' in task, false)
})

test('legacy currency falls back to rubles; configured project currency persists', () => {
  createStore()
  const legacy = JSON.parse(storage.getItem(STORAGE_KEY))
  delete legacy.project.currency
  storage.setItem(STORAGE_KEY, JSON.stringify(legacy))
  setActivePinia(createPinia())
  const restored = createStore()
  assert.equal(restored.storageBlocked, false)
  assert.deepEqual(JSON.parse(JSON.stringify(restored.tasks)), legacy.tasks)
  assert.equal(getProjectCurrencySymbol(restored.project), '₽')
  restored.project.currency = 'TRY'
  setActivePinia(createPinia())
  const configured = createStore()
  assert.equal(configured.project.currency, 'TRY')
  assert.equal(getProjectCurrencySymbol(configured.project), '₺')
  const snapshot = JSON.parse(storage.getItem(STORAGE_KEY))
  snapshot.project.currency = 100
  assert.equal(isValidSnapshot(snapshot), false)
})

test('legacy sections migrate once without using URLs or changing existing task content', () => {
  const initial = createStore()
  const data = JSON.parse(storage.getItem(STORAGE_KEY))
  data.sections = data.sections.map((section) => section.name)
  data.tasks.forEach((task, index) => {
    delete task.sectionId
    delete task.position
    task.location = `https://example.com/page/${index}`
  })
  const original = JSON.parse(JSON.stringify(data.tasks))
  storage.setItem(STORAGE_KEY, JSON.stringify(data))
  setActivePinia(createPinia())
  const restored = createStore()
  assert.equal(restored.storageBlocked, false)
  assert.ok(restored.sections.every((section) => section.id && Number.isInteger(section.position)))
  restored.tasks.forEach((task, index) => {
    const { sectionId, position, ...unchanged } = JSON.parse(JSON.stringify(task))
    assert.deepEqual(unchanged, original[index])
    assert.equal(restored.taskSection(task).name, original[index].section)
    assert.ok(restored.numberForTask(task))
  })
  const once = JSON.parse(storage.getItem(STORAGE_KEY))
  setActivePinia(createPinia())
  createStore()
  assert.deepEqual(JSON.parse(storage.getItem(STORAGE_KEY)), once)
  assert.equal(initial.tasks.length, restored.tasks.length)
})

test('status and filters preserve canonical numbers; explicit moves and reorders update them', async () => {
  const { groupBriefTasks } = await import('../src/utils/structure.js')
  const store = createStore()
  store.setMode('developer')
  const general = store.sections.find((section) => section.name === 'Общее')
  const main = store.sections.find((section) => section.name === 'Главная')
  const first = store.findTask(store.addTask({ ...idea(), section: 'Общее' }))
  const second = store.findTask(store.addTask({ ...idea(), section: 'Общее' }))
  const before = store.numberForTask(first)
  store.changeStatus(first.id, 'done')
  const groups = groupBriefTasks(
    store.tasks,
    store.sections,
    Object.keys((await import('../src/constants/project.js')).STATUSES),
  )
  assert.equal(groups.find((group) => group.id === general.id).tasks.at(-1).id, first.id)
  const doneOnly = groupBriefTasks(store.tasks, store.sections, ['done'], general.id)
  assert.equal(store.numberForTask(doneOnly[0].tasks[0]), before)
  assert.equal(store.numberForTask(first), before)
  await store.reorderTask(second.id, 'up')
  assert.equal(store.numberForTask(second), before)
  assert.notEqual(store.numberForTask(first), before)
  await store.moveTask(second.id, main.id)
  assert.equal(store.taskSection(second).name, 'Главная')
  assert.equal(second.position, store.sectionTasks(main.id).length)
  assert.deepEqual(
    store.sectionTasks(general.id).map((task) => task.position),
    [1],
  )
  assert.equal(second.history.at(-1).type, 'section_moved')
  const persistedNumber = store.numberForTask(second)
  setActivePinia(createPinia())
  const restored = createStore()
  assert.equal(restored.numberForTask(restored.findTask(second.id)), persistedNumber)
  restored.setMode('client')
  assert.equal(await restored.moveTask(second.id, general.id), false)
})

test('invalid canonical section references and duplicate positions cannot overwrite saved data', () => {
  createStore()
  const data = JSON.parse(storage.getItem(STORAGE_KEY))
  data.tasks[0].sectionId = 'foreign-section'
  assert.equal(isValidSnapshot(data), false)
  const valid = JSON.parse(storage.getItem(STORAGE_KEY))
  valid.sections[1].position = valid.sections[0].position
  assert.equal(isValidSnapshot(valid), false)
  storage.setItem(STORAGE_KEY, JSON.stringify(data))
  setActivePinia(createPinia())
  assert.equal(createStore().storageBlocked, true)
  assert.deepEqual(JSON.parse(storage.getItem(STORAGE_KEY)), data)
})
