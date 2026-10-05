import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { useProjectStore, isValidSnapshot } from '../src/stores/project.js'
import { STORAGE_KEY, getTaskLocation } from '../src/constants/project.js'

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
  assert.equal(store.deleteTask(id), false)
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

test('custom locations and complete URLs persist and become grouping/filter options', () => {
  const store = createStore()
  const url =
    'https://example.com/catalog/' + 'product-name/'.repeat(35) + '?color=green&size=large#photos'
  const values = ['Главная', 'Страница доставки', 'Корзина', url, 'all']
  const ids = values.map((location) => store.addTask({ ...idea(), location }))
  values.forEach((location, index) => {
    assert.equal(getTaskLocation(store.findTask(ids[index])), location)
    assert.equal(store.findTask(ids[index]).section, location)
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
