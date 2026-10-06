import { test } from 'node:test'
import assert from 'node:assert/strict'
import { ref } from 'vue'
import { canEditClientTask, useDraftDeletion } from '../src/utils/clientDraft.js'

test('edit/delete visibility is restricted to unapproved new or clarification ideas', () => {
  for (const status of ['new', 'clarification', 'approved', 'in_progress', 'review', 'done']) {
    assert.equal(
      canEditClientTask({ status, clientApproved: false }),
      ['new', 'clarification'].includes(status),
      status,
    )
    assert.equal(canEditClientTask({ status, clientApproved: true }), false, status + ' approved')
  }
  assert.equal(canEditClientTask(null), false)
})
test('deletion requires confirmation, cancel keeps data, success returns to project list', async () => {
  const calls = []
  const store = {
    isDeveloper: false,
    projectPath: '/project/example',
    deleteTask: async (id) => {
      calls.push(['delete', id])
      return true
    },
  }
  const task = ref({ id: 'draft', status: 'new', clientApproved: false })
  const router = { push: async (path) => calls.push(['navigate', path]) }
  const action = useDraftDeletion({ store, task, router })
  assert.equal(await action.confirm(), false)
  action.requestDeletion()
  assert.equal(action.open.value, true)
  action.cancel()
  assert.equal(action.open.value, false)
  assert.deepEqual(calls, [])
  action.requestDeletion()
  assert.equal(await action.confirm(), true)
  assert.deepEqual(calls, [
    ['delete', 'draft'],
    ['navigate', '/project/example'],
  ])
  assert.equal(action.open.value, false)
  assert.equal(action.pending.value, false)
})
test('failed deletion keeps dialog open and does not navigate; approval prevents stale confirmation', async () => {
  const calls = []
  const task = ref({ id: 'draft', status: 'clarification', clientApproved: false })
  const action = useDraftDeletion({
    store: { isDeveloper: false, deleteTask: async () => false },
    task,
    router: { push: async () => calls.push('navigate') },
  })
  action.requestDeletion()
  assert.equal(await action.confirm(), false)
  assert.equal(action.open.value, true)
  task.value.clientApproved = true
  assert.equal(await action.confirm(), false)
  assert.deepEqual(calls, [])
})
