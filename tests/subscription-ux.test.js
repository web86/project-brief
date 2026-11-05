import assert from 'node:assert/strict'
import test from 'node:test'
import { createApiClient, retryAfterTime } from '../src/api/client.js'
import { selectedCounts, suggestedSelection } from '../src/services/subscription.js'

test('429 provides a retry deadline and never replays the request automatically', async () => {
  assert.equal(retryAfterTime('3', 1000), 4000)
  assert.equal(retryAfterTime('Thu, 01 Jan 1970 00:00:05 GMT', 1000), 5000)
  assert.equal(retryAfterTime(null, 1000), 61000)
  let calls = 0
  const api = createApiClient({
    fetchImpl: async () => {
      calls++
      return {
        ok: false,
        status: 429,
        headers: new Headers({ 'Retry-After': '5' }),
        json: async () => ({}),
      }
    },
  })
  await assert.rejects(
    api.request('/api/admin/resource-selection/summary'),
    (error) => error.status === 429 && error.retryAt > Date.now(),
  )
  assert.equal(calls, 1)
})

test('suggested downgrade respects capacity, project dependencies and seat identity', () => {
  const resources = {
    projects: [{ id: 'p1' }, { id: 'p2' }],
    clients: [{ id: 'c1', projectIds: ['p2'] }],
    developers: [
      { id: 'owner', owner: true, seatKey: 'a' },
      { id: 'dev', seatKey: 'b' },
    ],
    agents: [
      { id: 'a1', projectIds: ['p2'] },
      { id: 'a2', projectIds: ['p1'] },
    ],
    invitations: [
      { id: 'i1', projectIds: ['p1'], eligible: true, seatKey: 'a' },
      { id: 'i2', projectIds: [], eligible: true, seatKey: 'c' },
    ],
  }
  const limits = Object.fromEntries(
    ['projects.active.max', 'clients.max', 'developers.max', 'agents.active.max'].map((key) => [
      key,
      { kind: 'integer', value: 1 },
    ]),
  )
  const selection = suggestedSelection(resources, limits)
  assert.deepEqual(selection, {
    projects: ['p1'],
    clients: [],
    developers: ['owner'],
    agents: ['a2'],
    invitations: ['i1'],
  })
  assert.equal(selectedCounts(resources, selection)['developers.max'], 1)
})
