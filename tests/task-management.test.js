import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { useProjectStore, isValidSnapshot } from '../src/stores/project.js'
import { canDeleteTask, useDraftDeletion } from '../src/utils/clientDraft.js'
import { STORAGE_KEY } from '../src/constants/project.js'
let values
beforeEach(() => {
  values = new Map()
  globalThis.localStorage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  }
  setActivePinia(createPinia())
})
function store() {
  const result = useProjectStore()
  result.loadFromStorage()
  result.setMode('developer')
  return result
}
test('placement supports arbitrary slots, empty sections and reload while preserving developer drafts', async () => {
  const s = store()
  const first = s.tasks[0]
  const originalSection = first.sectionId
  const empty = s.sections.find((section) => !s.sectionTasks(section.id).length)
  s.updateDeveloperData(first.id, { developerNotes: 'Unsaved draft' })
  assert.equal(await s.placeTask(first.id, empty.id), true)
  assert.equal(s.numberForTask(first), `${empty.position}.1`)
  const anchor = s.sectionTasks(originalSection)[0]
  assert.equal(await s.placeTask(first.id, originalSection, anchor.id), true)
  assert.equal(s.sectionTasks(originalSection)[0].id, first.id)
  assert.equal(s.hasDeveloperChanges(first.id), true)
  assert.equal(s.developerTask(first.id).developerNotes, 'Unsaved draft')
  assert.equal(await s.placeTask(first.id, empty.id, anchor.id), false)
  assert.equal(isValidSnapshot(JSON.parse(values.get(STORAGE_KEY))), true)
  setActivePinia(createPinia())
  const reloaded = store()
  assert.equal(reloaded.sectionTasks(originalSection)[0].id, first.id)
  reloaded.setMode('client')
  assert.equal(await reloaded.placeTask(first.id, empty.id), false)
})
test('trash eligibility is independent from edit eligibility for both actors and protects approval', () => {
  for (const mode of ['client', 'developer']) {
    const s = store()
    s.setMode(mode)
    const task = s.tasks[0]
    for (const status of ['clarification', 'approved', 'in_progress', 'review', 'done']) {
      task.status = status
      assert.equal(s.deleteTask(task.id), false)
      assert.equal(canDeleteTask(task), false)
    }
    Object.assign(task, { status: 'new', clientApproved: true })
    assert.equal(s.deleteTask(task.id), false)
    task.clientApproved = false
    assert.equal(s.deleteTask(task.id), true)
    assert.equal(s.deleteTask(task.id), false)
    assert.equal(
      s.trash.some((item) => item.id === task.id),
      true,
    )
  }
})
test('local trash preserves content, comments, history and attachments across reload and restore', async () => {
  const s = store()
  const task = s.tasks.find((item) => item.status === 'new' && !item.clientApproved)
  const saved = structuredClone(JSON.parse(JSON.stringify(task)))
  assert.equal(s.deleteTask(task.id), true)
  setActivePinia(createPinia())
  const reloaded = store()
  assert.equal(reloaded.findTask(task.id), undefined)
  assert.deepEqual(
    JSON.parse(JSON.stringify(reloaded.trash.find((item) => item.id === task.id))),
    saved,
  )
  assert.equal(await reloaded.restoreTask(task.id), true)
  assert.deepEqual(JSON.parse(JSON.stringify(reloaded.findTask(task.id))), saved)
  assert.equal(reloaded.trash.length, 0)
  assert.equal(isValidSnapshot(JSON.parse(values.get(STORAGE_KEY))), true)
})
test('failed local persistence rolls placement and trash back', async () => {
  const s = store()
  const task = s.tasks.find((item) => item.status === 'new' && !item.clientApproved)
  const empty = s.sections.find((section) => !s.sectionTasks(section.id).length)
  const saved = JSON.stringify(s.tasks)
  globalThis.localStorage.setItem = () => {
    throw new Error('quota')
  }
  assert.equal(await s.placeTask(task.id, empty.id), false)
  assert.equal(JSON.stringify(s.tasks), saved)
  assert.equal(s.deleteTask(task.id), false)
  assert.equal(JSON.stringify(s.tasks), saved)
  assert.equal(s.trash.length, 0)
})
test('confirmation blocks duplicate requests and refuses changed status before sending', async () => {
  let release
  let calls = 0
  const task = ref({ id: 'idea', status: 'new', clientApproved: false })
  const action = useDraftDeletion({
    store: {
      isDeveloper: true,
      projectPath: '/',
      deleteTask: () => {
        calls++
        return new Promise((resolve) => {
          release = resolve
        })
      },
    },
    task,
    router: { push: async () => {} },
  })
  action.requestDeletion()
  const pending = action.confirm()
  assert.equal(await action.confirm(), false)
  action.cancel()
  assert.equal(action.open.value, true)
  release(false)
  await pending
  task.value.status = 'review'
  assert.equal(await action.confirm(), false)
  assert.equal(calls, 1)
})
