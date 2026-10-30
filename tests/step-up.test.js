import test from 'node:test'
import assert from 'node:assert/strict'
import {
  createStepUpFlow,
  isSensitiveMutation,
  isSecurityConfirmationScreen,
} from '../src/services/stepUp.js'
import { createApiClient } from '../src/api/client.js'
import { createPasskeyClient } from '../src/services/accountSecurity.js'
const deferred = () => {
  let resolve, reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
const tick = () => new Promise((resolve) => setImmediate(resolve))
const response = (status, data) => ({ status, ok: status < 400, json: async () => data })
function fixture(overrides = {}) {
  let time = 0,
    native = 0,
    failures = 0,
    changed = 0,
    active = true
  const approval = deferred()
  const initial = {
    signedIn: true,
    owner: true,
    totp: true,
    passkeys: [{ id: 1 }],
    fresh: false,
    freshFor: 0,
    confirmationContext: 'account-context',
  }
  const flow = createStepUpFlow({ now: () => time })
  flow.configure({
    status: async () => initial,
    options: async () => ({ options: { challenge: 'one-use' } }),
    supported: () => true,
    activated: () => active,
    passkey: () => {
      native++
      return approval.promise
    },
    error: () => failures++,
    changed: () => changed++,
    ...overrides,
  })
  return {
    flow,
    initial,
    approval,
    advance: (value) => (time += value),
    deactivate: () => (active = false),
    get native() {
      return native
    },
    get failures() {
      return failures
    },
    get changed() {
      return changed
    },
    approved: (extra) => ({ ...initial, fresh: true, freshFor: 300, ...extra }),
  }
}

test('prepared Passkey invokes the authenticator synchronously without an application dialog', async () => {
  const f = fixture()
  await f.flow.prepare()
  const result = f.flow.preflight('/api/admin/projects/project/team/members/2', 'PATCH')
  assert.equal(f.native, 1)
  assert.equal(f.flow.state.value.mode, 'native')
  f.approval.resolve(f.approved())
  assert.equal(await result, true)
  assert.equal(f.flow.request.value, null)
  assert.equal(f.flow.preflight('/api/admin/integrations/tokens', 'POST'), null)
})

test('the installed Passkey client skips every await before native get when options are prepared', async () => {
  let called = false,
    optionsRequests = 0
  const ceremony = createPasskeyClient({
    client: {
      resetCsrf() {},
      async request(url, input) {
        if (!input) optionsRequests++
        return { fresh: true }
      },
    },
    authenticate: () => {
      called = true
      return Promise.resolve({ rawId: 'signed' })
    },
  })
  const pending = ceremony('confirm', undefined, { challenge: 'prepared' })
  assert.equal(called, true)
  await pending
  assert.equal(optionsRequests, 0)
})

for (const [totp, owner] of [
  [true, true],
  [true, false],
  [false, false],
]) {
  test(`no Passkey uses the existing compact alternative (totp=${totp}, owner=${owner})`, async () => {
    const f = fixture({
      status: async () => ({
        signedIn: true,
        passkeys: [],
        owner,
        totp,
        freshFor: 0,
        confirmationContext: 'account-context',
      }),
    })
    await f.flow.prepare()
    const result = f.flow.ensure(true)
    await tick()
    assert.equal(f.native, 0)
    assert.equal(f.flow.state.value.mode, 'alternative')
    assert.equal(f.flow.state.value.status.totp, totp)
    f.flow.accept(f.approved())
    assert.equal(await result, true)
  })
}

test('cancelled native confirmation offers retry and alternatives without a critical toast', async () => {
  const f = fixture()
  await f.flow.prepare()
  const result = f.flow.ensure()
  f.approval.reject({ cancelled: true })
  await tick()
  assert.equal(f.flow.state.value.mode, 'retry')
  assert.equal(f.flow.state.value.error, null)
  assert.equal(f.failures, 0)
  f.flow.alternative()
  assert.equal(f.flow.state.value.mode, 'alternative')
  f.flow.cancel()
  assert.equal(await result, false)
})

test('lost browser activation uses a genuine one-button fallback, without invoking WebAuthn', async () => {
  const f = fixture()
  await f.flow.prepare()
  f.deactivate()
  const result = f.flow.ensure()
  assert.equal(f.native, 0)
  assert.equal(f.flow.state.value.mode, 'retry')
  f.flow.cancel()
  assert.equal(await result, false)
})

test('server verification failures retain safe diagnostics and cannot authorize the mutation', async () => {
  const f = fixture()
  await f.flow.prepare()
  const result = f.flow.ensure()
  f.approval.reject({ status: 422, code: 'PK_UV_REQUIRED', diagnosticId: 'reference' })
  await tick()
  assert.equal(f.failures, 1)
  assert.equal(f.flow.state.value.error.code, 'PK_UV_REQUIRED')
  f.flow.cancel()
  assert.equal(await result, false)
})

test('account/workspace/version/policy changes invalidate a pending intent', async () => {
  const f = fixture()
  await f.flow.prepare()
  const result = f.flow.ensure()
  f.approval.resolve(f.approved({ confirmationContext: 'different-context' }))
  assert.equal(await result, false)
  assert.equal(f.changed, 1)
})

test('expiry, session invalidation and registration never extend the proof', async () => {
  const f = fixture()
  await f.flow.prepare()
  const first = f.flow.ensure()
  f.approval.resolve(f.approved())
  await first
  f.advance(300000)
  const expired = f.flow.preflight('/api/admin/integrations/tokens', 'POST')
  assert.ok(expired)
  f.flow.cancel()
  await expired
  f.flow.observe('/api/security/passkey/register', 'POST', f.approved(), 200)
  assert.equal(f.flow.preflight('/api/admin/integrations/tokens', 'POST'), null) // unknown cache: server gate decides
  f.deactivate()
  const pending = f.flow.ensure(true)
  await tick()
  f.flow.invalidate(true)
  assert.equal(await pending, false)
})

test('ordinary work, client sessions and bearer APIs are never proactively gated', () => {
  for (const path of [
    '/api/admin/tasks/1',
    '/api/admin/tasks/1/comments',
    '/api/admin/notifications',
    '/api/v1/projects',
    '/api/client/tasks',
  ])
    assert.equal(isSensitiveMutation(path, 'POST'), false)
  for (const path of [
    '/api/admin/account/email',
    '/api/admin/account/password',
    '/api/admin/projects/1/team/members/2',
    '/api/admin/projects/1/clients/2/access-links',
    '/api/admin/integrations/webhooks/1',
    '/api/admin/projects/trash/confirmation',
    '/api/admin/projects/1/permanent',
  ])
    assert.equal(isSensitiveMutation(path, 'PATCH'), true)
})

test('proactive confirmation snapshots the intent and deduplicates a double click', async () => {
  const f = fixture()
  await f.flow.prepare()
  const sent = []
  const client = createApiClient({
    securityFlow: f.flow,
    fetchImpl: async (path, input) =>
      path === '/api/csrf'
        ? response(200, { token: 'fresh' })
        : (sent.push(JSON.parse(input.body)), response(200, { saved: true })),
  })
  const body = { permissions: { manage_clients: true }, projects: ['original'] }
  const original = client.request('/api/admin/projects/1/team/members/2', { method: 'PATCH', body })
  const duplicate = client.request('/api/admin/projects/1/team/members/2', {
    method: 'PATCH',
    body,
  })
  assert.equal(original, duplicate)
  assert.equal(sent.length, 0)
  body.permissions.manage_clients = false
  body.projects.push('later-edit')
  f.approval.resolve(f.approved())
  assert.deepEqual(await original, { saved: true })
  assert.deepEqual(sent, [{ permissions: { manage_clients: true }, projects: ['original'] }])
})

for (const status of [0, 400, 401, 403, 419, 422, 500]) {
  test(`HTTP/network ${status} never replays a sensitive mutation`, async () => {
    let calls = 0
    const client = createApiClient({
      fetchImpl: async (path) => {
        if (path === '/api/csrf') return response(200, { token: 'test' })
        calls++
        if (!status) throw new Error('uncertain result')
        return response(status, { code: 'security_step_up_required', mutationNotStarted: true })
      },
    })
    await assert.rejects(
      client.request('/api/admin/integrations/tokens', { method: 'POST', body: { name: 'draft' } }),
    )
    assert.equal(calls, 1)
  })
}

test('only a typed pre-mutation denial may replay once, with a fresh CSRF token', async () => {
  const flow = createStepUpFlow()
  let calls = 0,
    tokens = 0,
    sent
  const client = createApiClient({
    securityFlow: flow,
    fetchImpl: async (path, input) => {
      if (path === '/api/csrf') return response(200, { token: String(++tokens) })
      calls++
      sent = input
      return calls === 1
        ? response(423, { code: 'security_step_up_required', mutationNotStarted: true })
        : response(200, { saved: true })
    },
  })
  const body = { projects: ['original'] }
  const pending = client.request('/api/admin/integrations/tokens', { method: 'POST', body })
  while (!flow.request.value) await tick()
  body.projects.push('later-edit')
  flow.request.value(true)
  assert.deepEqual(await pending, { saved: true })
  assert.equal(calls, 2)
  assert.equal(tokens, 2)
  assert.deepEqual(JSON.parse(sent.body), { projects: ['original'] })
})

test('background preparation never replaces the challenge of an active ceremony', async () => {
  const ready = deferred(),
    approval = deferred()
  let options = 0,
    native = 0
  const snapshot = {
    signedIn: true,
    owner: true,
    totp: true,
    passkeys: [{ id: 1 }],
    freshFor: 0,
    confirmationContext: 'same-account',
  }
  const flow = createStepUpFlow()
  flow.configure({
    status: () => ready.promise,
    options: async () => {
      options++
      return { options: { challenge: 'single' } }
    },
    supported: () => true,
    activated: () => true,
    passkey: () => {
      native++
      return approval.promise
    },
  })
  const warming = flow.prepare()
  const confirming = flow.ensure()
  ready.resolve(snapshot)
  await warming
  await tick()
  assert.equal(options, 1)
  assert.equal(native, 1)
  await flow.prepare()
  assert.equal(options, 1)
  approval.resolve({ ...snapshot, fresh: true, freshFor: 300 })
  assert.equal(await confirming, true)
})

test('an unknown 423 is never replayed and a second typed refusal stops after one continuation', async () => {
  for (const marker of [false, true]) {
    const flow = createStepUpFlow()
    let calls = 0
    const client = createApiClient({
      securityFlow: flow,
      fetchImpl: async (path) =>
        path === '/api/csrf'
          ? response(200, { token: 'fresh' })
          : (calls++,
            response(423, {
              code: 'security_step_up_required',
              ...(marker ? { mutationNotStarted: true } : {}),
            })),
    })
    const pending = client.request('/api/admin/integrations/tokens', {
      method: 'POST',
      body: { name: 'intent' },
    })
    while (!flow.request.value) await tick()
    flow.request.value(true)
    await assert.rejects(pending)
    assert.equal(calls, marker ? 2 : 1)
  }
})

test('preparation follows actual sensitive UI routes without warming ordinary task or notification screens', () => {
  for (const path of [
    '/admin',
    '/admin/',
    '/admin/account',
    '/admin/account/security',
    '/admin/settings/integrations',
    '/admin/projects/id/team',
    '/admin/administrators',
    '/admin/projects/id/clients',
  ])
    assert.equal(isSecurityConfirmationScreen(path), true, path)
  for (const path of [
    '/admin/integrations',
    '/admin/settings/notifications',
    '/admin/projects/id/brief',
    '/admin/projects/id/tasks/1',
    '/project/id',
    '/access/token',
  ])
    assert.equal(isSecurityConfirmationScreen(path), false, path)
})

test('Passkey client submits the exact attempt id alongside its own signed options', async () => {
  const calls = []
  const client = {
    resetCsrf() {},
    async request(path, options) {
      calls.push({ path, ...options })
      return path.endsWith('/options')
        ? { options: { challenge: 'attempt-one' }, attemptId: 'attempt-id-one' }
        : { fresh: true }
    },
  }
  const ceremony = createPasskeyClient({
    client,
    authenticate: async ({ optionsJSON }) => {
      assert.equal(optionsJSON.challenge, 'attempt-one')
      return { rawId: 'signed' }
    },
  })
  await ceremony('confirm')
  assert.equal(calls.at(-1).body.attemptId, 'attempt-id-one')
  assert.equal(calls.at(-1).body.credential.rawId, 'signed')
  await ceremony('confirm', undefined, {
    options: { challenge: 'attempt-one' },
    attemptId: 'prepared-attempt',
  })
  assert.equal(calls.at(-1).body.attemptId, 'prepared-attempt')
  assert.equal(calls.filter((c) => c.path.endsWith('/options')).length, 1)
})

test('independent tab flows retain their own prepared attempts and duplicate warming is coalesced', async () => {
  const gate = deferred(),
    seen = []
  let preparations = 0
  const a = fixture({
    options: async () => {
      preparations++
      await gate.promise
      return { options: { challenge: 'A' }, attemptId: 'A-id' }
    },
    passkey: async (envelope) => {
      seen.push(envelope)
      return a.approved()
    },
  })
  const b = fixture({
    options: async () => ({ options: { challenge: 'B' }, attemptId: 'B-id' }),
    passkey: async (envelope) => {
      seen.push(envelope)
      return b.approved()
    },
  })
  const first = a.flow.prepare(),
    duplicate = a.flow.prepare()
  await tick()
  assert.equal(preparations, 1)
  gate.resolve()
  await Promise.all([first, duplicate, b.flow.prepare()])
  const operation = a.flow.ensure()
  assert.equal(seen[0].attemptId, 'A-id')
  await operation
  await b.flow.ensure()
  assert.equal(seen[1].attemptId, 'B-id')
})
