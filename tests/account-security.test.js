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

test('step-up requires explicit resubmission and never automatically replays a denied mutation', async () => {
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
  await assert.rejects(pending, (error) => error.code === 'security_retry_required')
  assert.equal(mutations, 1)
  await client.request('/api/admin/integrations/tokens', { method: 'POST', body: { name: 'CI' } })
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

// Exercise the installed serializer and the application transport, including old browser API shapes.
const { createPasskeyClient } = await import('../src/services/accountSecurity.js')
const { startRegistration } = await import('@simplewebauthn/browser')
const { formatApiError, locale } = await import('../src/i18n/index.js')

for (const status of [400, 401, 403, 419, 422, 423, 429, 500]) {
  test(`Passkey HTTP ${status} retains diagnostic details and never replays a submission`, async () => {
    const calls = []
    const id = '12345678-1234-1234-1234-123456789abc'
    const ceremony = createPasskeyClient({
      register: async () => ({ id: 'created-only-in-device' }),
      client: {
        resetCsrf() {},
        async request(url, options) {
          calls.push({ url, options })
          if (!options) return { options: { challenge: 'fresh' } }
          throw {
            status,
            code: 'PK_PERSIST_FAILED',
            diagnosticId: id,
            stage: 'persist',
            credential: 'PRIVATE',
          }
        },
      },
    })
    await assert.rejects(ceremony('register', 'Mac'), (error) => {
      assert.equal(error.status, status)
      assert.equal(error.code, 'PK_PERSIST_FAILED')
      assert.equal(error.diagnosticId, id)
      assert.equal(error.stage, 'persist')
      assert.equal(error.credentialCreated, true)
      assert.equal(JSON.stringify(error).includes('PRIVATE'), false)
      return true
    })
    assert.equal(calls.length, 2)
    assert.equal(calls[1].options.securityRetry, false)
  })
}

test('failed options do not invoke the authenticator; retry always fetches a new challenge', async () => {
  let optionsCalls = 0,
    createCalls = 0
  const seen = []
  const ceremony = createPasskeyClient({
    client: {
      resetCsrf() {},
      async request(url, body) {
        if (!body) {
          if (++optionsCalls === 1) throw { status: 401 }
          return { options: { challenge: String(optionsCalls) } }
        }
        if (createCalls === 1) throw { status: 500 }
        return { state: 'authenticated' }
      },
    },
    register: async ({ optionsJSON }) => {
      createCalls++
      seen.push(optionsJSON.challenge)
      return { rawId: String(createCalls) }
    },
  })
  await assert.rejects(ceremony('register', 'Mac'), (error) => !error.credentialCreated)
  assert.equal(createCalls, 0)
  await assert.rejects(ceremony('register', 'Mac'), (error) => error.credentialCreated)
  assert.deepEqual(await ceremony('register', 'Mac'), { state: 'authenticated' })
  assert.deepEqual(seen, ['2', '3'])
})

for (const shape of ['Safari', 'Firefox', 'Chromium']) {
  test(`${shape} API shape: binary registration fields are Base64URL even without native toJSON`, async () => {
    const descriptors = Object.fromEntries(
      ['navigator', 'PublicKeyCredential'].map((key) => [
        key,
        Object.getOwnPropertyDescriptor(globalThis, key),
      ]),
    )
    const buffer = (...bytes) => new Uint8Array(bytes).buffer
    let publicKey
    const response = {
      attestationObject: buffer(251, 255, 1),
      clientDataJSON: buffer(2, 3, 4),
      ...(shape !== 'Firefox' ? { getTransports: () => ['internal'] } : {}),
      ...(shape === 'Chromium'
        ? {
            getPublicKey: () => buffer(5),
            getPublicKeyAlgorithm: () => -7,
            getAuthenticatorData: () => buffer(6),
          }
        : {}),
    }
    Object.defineProperty(globalThis, 'PublicKeyCredential', {
      configurable: true,
      value: function PublicKeyCredential() {},
    })
    Object.defineProperty(globalThis, 'navigator', {
      configurable: true,
      value: {
        credentials: {
          create: async (input) => {
            publicKey = input.publicKey
            return {
              id: '-_8B',
              rawId: buffer(251, 255, 1),
              type: 'public-key',
              response,
              getClientExtensionResults: () => ({}),
              toJSON() {
                throw new Error('native toJSON must not be required')
              },
            }
          },
        },
      },
    })
    try {
      const credential = await startRegistration({
        optionsJSON: {
          challenge: 'AQID',
          rp: { id: 'brief.web86.site', name: 'Project Brief' },
          user: { id: 'BAUG', name: 'owner', displayName: 'Owner' },
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
          authenticatorSelection: { userVerification: 'required', residentKey: 'required' },
          excludeCredentials: [{ type: 'public-key', id: 'BwgJ' }],
        },
      })
      const json = JSON.parse(JSON.stringify(credential))
      assert.equal(json.rawId, '-_8B')
      assert.equal(json.response.attestationObject, '-_8B')
      assert.equal(json.response.clientDataJSON, 'AgME')
      assert.deepEqual([...new Uint8Array(publicKey.challenge)], [1, 2, 3])
      assert.deepEqual([...new Uint8Array(publicKey.user.id)], [4, 5, 6])
      assert.deepEqual([...new Uint8Array(publicKey.excludeCredentials[0].id)], [7, 8, 9])
      assert.equal(publicKey.authenticatorSelection.userVerification, 'required')
      assert.equal(json.response.transports?.[0], shape === 'Firefox' ? undefined : 'internal')
    } finally {
      for (const [key, descriptor] of Object.entries(descriptors)) {
        if (descriptor) Object.defineProperty(globalThis, key, descriptor)
        else delete globalThis[key]
      }
    }
  })
}

test('safe diagnostic codes have specific localized user messages', () => {
  const previous = locale.value
  try {
    for (const { code } of LANGUAGE_CATALOG) {
      locale.value = code
      for (const diagnostic of [
        'PK_CHALLENGE_EXPIRED',
        'PK_ORIGIN_MISMATCH',
        'PK_RP_MISMATCH',
        'PK_UV_REQUIRED',
        'PK_PAYLOAD_INVALID',
        'PK_PERSIST_FAILED',
        'SEC_FACTOR_INVALID',
      ]) {
        const text = formatApiError({ code: diagnostic, status: 422 })
        assert.ok(text.length > 12)
        assert.equal(text.includes('security.'), false)
        assert.notEqual(text, formatApiError({ status: 422 }))
      }
    }
  } finally {
    locale.value = previous
  }
})

test('optional security recommendation routes once without changing authentication', async () => {
  assert.equal(
    await appDestination(async () => ({
      authenticated: true,
      type: 'admin',
      securityRecommended: true,
    })),
    '/admin/security',
  )
  assert.equal(
    await appDestination(async () => ({
      authenticated: true,
      type: 'admin',
      securityRecommended: false,
    })),
    '/admin',
  )
})
