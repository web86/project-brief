import test from 'node:test'
import assert from 'node:assert/strict'
import { createTelegramHandler } from '../deployment/telegram-bridge.mjs'

const secret = 'synthetic-diagnostics-secret-' + 's'.repeat(32)
const endpoint = 'https://projectbrief-telegram-bridge.web86.deno.net/telegram/diagnostics'
const targets = [
  'https://brief.web86.site/api/health',
  'https://brief.web86.site/api/telegram/webhook',
]
function handler({ flag = '1', credential = secret, request } = {}) {
  return createTelegramHandler({
    env: (name) => ({ TELEGRAM_DIAGNOSTICS: flag, TELEGRAM_BRIDGE_SECRET: credential })[name],
    fetch:
      request ||
      (() => {
        throw new Error('Network must not be used')
      }),
  })
}
function input({
  method = 'POST',
  authorization = 'Bearer ' + secret,
  body = '{}',
  query = '',
} = {}) {
  return new Request(endpoint + query, {
    method,
    headers: { Authorization: authorization, 'Content-Type': 'application/json' },
    ...(method === 'POST' ? { body } : {}),
  })
}

test('diagnostics is absent unless explicitly enabled and rejects unsupported methods', async () => {
  for (const flag of [undefined, '', '0', 'true', 'yes']) {
    const disabled = createTelegramHandler({
      env: (key) => (key === 'TELEGRAM_BRIDGE_SECRET' ? secret : flag),
      fetch: () => assert.fail('Disabled endpoint cannot make requests'),
    })
    const response = await disabled(input())
    assert.equal(response.status, 404)
  }
  assert.equal((await handler()(input({ method: 'GET' }))).status, 404)
})

test('diagnostics requires the existing full Bearer secret before reading input or sending probes', async () => {
  for (const authorization of ['', 'Bearer wrong', secret, 'Basic ' + secret])
    assert.equal((await handler()(input({ authorization }))).status, 401)
  assert.equal((await handler({ credential: 'short' })(input())).status, 401)
  assert.equal((await handler({ credential: undefined })(input({ authorization: '' }))).status, 401)
})

test('diagnostics rejects caller URLs, arguments, malformed JSON and oversized bodies', async () => {
  for (const body of [
    '{"url":"https://evil.example"}',
    '{"method":"POST"}',
    '[]',
    'null',
    '{',
    '"value"',
  ])
    assert.equal((await handler()(input({ body }))).status, 400)
  assert.equal((await handler()(input({ query: '?url=https://evil.example' }))).status, 400)
  assert.equal((await handler()(input({ body: ' '.repeat(1025) }))).status, 413)
})

test('diagnostics makes exactly two fixed requests with independent eight-second signals and no secrets', async (context) => {
  const timeouts = [],
    calls = []
  context.mock.method(AbortSignal, 'timeout', (duration) => {
    timeouts.push(duration)
    return new AbortController().signal
  })
  const response = await handler({
    request: async (url, options) => {
      calls.push({ url, options })
      return new Response('PRIVATE_SERVER_CONTENT ' + secret, {
        status: url.endsWith('/health') ? 200 : 403,
        headers: { 'X-Private': 'PRIVATE_SERVER_HEADER' },
      })
    },
  })(input())
  assert.equal(response.status, 200)
  assert.equal(response.headers.get('Cache-Control'), 'no-store')
  const result = await response.json()
  assert.equal(result.ok, true)
  assert.deepEqual(timeouts, [8000, 8000])
  assert.deepEqual(
    calls.map(({ url }) => url),
    targets,
  )
  assert.equal(calls[0].options.method, 'GET')
  assert.equal(calls[0].options.headers, undefined)
  assert.equal(calls[0].options.body, undefined)
  assert.equal(calls[1].options.method, 'POST')
  assert.equal(calls[1].options.body, '{}')
  assert.deepEqual(calls[1].options.headers, { 'Content-Type': 'application/json' })
  for (const { options } of calls) {
    assert.equal(options.redirect, 'manual')
    assert.ok(options.signal instanceof AbortSignal)
    assert.equal(JSON.stringify(options).includes(secret), false)
  }
  assert.deepEqual(
    result.checks.map(({ name, status, errorCode }) => ({ name, status, errorCode })),
    [
      { name: 'health', status: 200, errorCode: null },
      { name: 'webhook', status: 403, errorCode: null },
    ],
  )
  for (const row of result.checks) {
    assert.deepEqual(Object.keys(row).sort(), ['name', 'status', 'durationMs', 'errorCode'].sort())
    assert.ok(Number.isInteger(row.durationMs) && row.durationMs >= 0)
  }
  assert.doesNotMatch(JSON.stringify(result), /PRIVATE_SERVER|synthetic-diagnostics|https:/)
})

test('diagnostics never reads response content and does not wait for a stalled response body', async () => {
  let cancelled = 0
  const response = await handler({
    request: async (url) => ({
      status: url.endsWith('/health') ? 200 : 403,
      body: {
        cancel() {
          cancelled++
          return new Promise(() => {})
        },
      },
      text() {
        assert.fail('Response body cannot be read')
      },
      json() {
        assert.fail('Response body cannot be parsed')
      },
    }),
  })(input({ body: '' }))
  assert.equal((await response.json()).ok, true)
  assert.equal(cancelled, 2)
})

test('diagnostics returns redirect status and never follows it', async () => {
  const calls = []
  const response = await handler({
    request: async (url, options) => {
      calls.push({ url, options })
      return new Response(null, {
        status: 302,
        headers: { Location: 'https://evil.example/private' },
      })
    },
  })(input())
  const result = await response.json()
  assert.equal(result.ok, false)
  assert.deepEqual(
    result.checks.map(({ status, errorCode }) => [status, errorCode]),
    [
      [302, null],
      [302, null],
    ],
  )
  assert.equal(calls.length, 2)
  assert.equal(
    calls.every(({ options }) => options.redirect === 'manual'),
    true,
  )
  assert.doesNotMatch(JSON.stringify(result), /evil|private|Location/)
})

test('diagnostics reports sanitized errors independently and never retries', async () => {
  for (const [error, code] of [
    [new DOMException('PRIVATE_TIMEOUT ' + secret, 'TimeoutError'), 'timeout'],
    [
      Object.assign(new Error('PRIVATE_DETAILS ' + secret), { cause: { code: 'ETIMEDOUT' } }),
      'timeout',
    ],
    [
      Object.assign(new Error('PRIVATE_DETAILS ' + secret), { cause: { code: 'ENOTFOUND' } }),
      'dns_error',
    ],
    [
      Object.assign(new Error('PRIVATE_DETAILS ' + secret), {
        cause: { code: 'CERT_HAS_EXPIRED' },
      }),
      'tls_error',
    ],
    [
      Object.assign(new Error('PRIVATE_DETAILS ' + secret), { cause: { code: 'ECONNREFUSED' } }),
      'connection_error',
    ],
    [new Error('PRIVATE_UNKNOWN ' + secret), 'network_error'],
  ]) {
    let calls = 0
    const response = await handler({
      request: async (url) => {
        calls++
        if (url.endsWith('/health')) throw error
        return new Response(null, { status: 403 })
      },
    })(input())
    const result = await response.json()
    assert.equal(result.ok, false)
    assert.equal(calls, 2)
    assert.equal(result.checks[0].status, null)
    assert.equal(result.checks[0].errorCode, code)
    assert.equal(result.checks[1].status, 403)
    assert.equal(result.checks[1].errorCode, null)
    assert.doesNotMatch(JSON.stringify(result), /PRIVATE_|synthetic-diagnostics/)
  }
})

test('diagnostics can be fully switched off after use without affecting the existing send endpoint', async () => {
  let flag = '1',
    calls = 0
  const bridge = createTelegramHandler({
    env: (key) =>
      ({
        TELEGRAM_DIAGNOSTICS: flag,
        TELEGRAM_BRIDGE_SECRET: secret,
        TELEGRAM_BOT_TOKEN: '12345:abcdefghijklmnopqrstuvwxyzABCDEFG',
      })[key],
    fetch: async () => {
      calls++
      return Response.json({ ok: true })
    },
  })
  flag = '0'
  assert.equal((await bridge(input())).status, 404)
  assert.equal(calls, 0)
  const response = await bridge(
    new Request(endpoint.replace('/diagnostics', '/send'), {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + secret, 'Content-Type': 'application/json' },
      body: JSON.stringify({ method: 'getMe', payload: {} }),
    }),
  )
  assert.deepEqual(await response.json(), { ok: true })
  assert.equal(calls, 1)
})
