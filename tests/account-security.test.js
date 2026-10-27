import test from 'node:test'
import assert from 'node:assert/strict'
import { appDestination } from '../src/services/session.js'
import { createInvitationFlow } from '../src/services/invitationFlow.js'
import { createApiClient } from '../src/api/client.js'
import { stepUpRequest } from '../src/services/stepUp.js'
import { LANGUAGE_CATALOG } from '../src/i18n/catalog.js'

for (const state of ['pending_2fa', 'enrollment_required']) {
  test(`${state} session routes to restricted security setup`, async () => {
    assert.equal(
      await appDestination(async () => ({ authenticated: false, state })),
      '/admin/security',
    )
  })
  test(`invitation ${state} retains token in memory and does not accept prematurely`, async () => {
    const calls = []
    const token = 'a'.repeat(64)
    const flow = createInvitationFlow({
      token,
      request: async (url, options) => {
        calls.push({ url, ...options })
        return url.endsWith('/resolve')
          ? { authenticated: false, email: 'owner@example.test' }
          : { state }
      },
      resetCsrf() {},
      onAccepted() {
        throw new Error('premature acceptance')
      },
    })
    await flow.load()
    flow.chooseRegistration(false)
    flow.password.value = 'Owner-password!'
    assert.equal(await flow.accept(), false)
    assert.equal(flow.securityPending.value, true)
    assert.equal(flow.password.value, '')
    assert.equal(
      calls.some((call) => call.url.endsWith('/accept')),
      false,
    )
    assert.equal(calls.at(-1).body.invitation_token, token)
  })
}

test('step-up retries only a denied mutation after explicit confirmation with fresh CSRF', async () => {
  let mutations = 0,
    tokens = 0
  const response = (status, data) => ({ ok: status < 400, status, json: async () => data })
  const client = createApiClient({
    fetchImpl: async (url) =>
      url === '/api/csrf'
        ? response(200, { token: String(++tokens) })
        : response(++mutations === 1 ? 423 : 200, { code: 'security_step_up_required' }),
  })
  const pending = client.request('/api/admin/integrations/tokens', {
    method: 'POST',
    body: { name: 'CI' },
  })
  while (!stepUpRequest.value) await new Promise((resolve) => setImmediate(resolve))
  assert.equal(mutations, 1)
  stepUpRequest.value(true)
  await pending
  assert.equal(mutations, 2)
  assert.equal(tokens, 2)
})

test('cancelling step-up never retries a mutation', async () => {
  const client = createApiClient({
    fetchImpl: async () => ({
      ok: false,
      status: 423,
      json: async () => ({ code: 'security_step_up_required' }),
    }),
  })
  const pending = client.request('/api/admin/test')
  while (!stepUpRequest.value) await new Promise((resolve) => setImmediate(resolve))
  stepUpRequest.value(false)
  await assert.rejects(pending, (error) => error.status === 423)
})

test('security messages have matching nonempty translations in all four locales', () => {
  const keys = Object.keys(LANGUAGE_CATALOG[0].messages.security).sort()
  for (const { messages } of LANGUAGE_CATALOG) {
    assert.deepEqual(Object.keys(messages.security).sort(), keys)
    assert.ok(
      Object.values(messages.security).every((value) => typeof value === 'string' && value.trim()),
    )
  }
})
