import assert from 'node:assert/strict'
import test from 'node:test'
import { createApiClient } from '../src/api/client.js'
import { formatApiError } from '../src/i18n/index.js'
import { toast } from '../src/services/toast.js'

test('a tariff denial is shown once with real usage and never replaces an ordinary 403', async () => {
  let payload = {
    code: 'plan_limit_reached',
    planRestriction: { entitlement: 'developers.max', usage: 10, maximum: 10, plan: 'studio' },
  }
  const api = createApiClient({
    fetchImpl: async () => ({ ok: false, status: 403, json: async () => payload }),
  })
  try {
    await assert.rejects(api.request('/api/example'), (error) => {
      assert.equal(error.code, 'plan_limit_reached')
      assert.equal(formatApiError(error), '')
      return true
    })
    const [notice] = toast.items.value
    assert.equal(toast.items.value.length, 1)
    assert.match(notice.details[0](), /10.*10.*Studio/)
    payload = { code: 'project_permission_denied' }
    await assert.rejects(api.request('/api/example'), (error) => {
      assert.notEqual(formatApiError(error), '')
      return true
    })
    assert.equal(toast.items.value.length, 1)
  } finally {
    toast.clear()
  }
})
