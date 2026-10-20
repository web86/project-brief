import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createTelegramHandler } from '../deployment/telegram-bridge.mjs'

const callback = 'https://brief.web86.site/api/telegram/webhook'
const secrets = {
  TELEGRAM_WEBHOOK_SECRET: 'w'.repeat(40),
  TELEGRAM_FORWARD_SECRET: 'f'.repeat(40),
  TELEGRAM_BRIDGE_SECRET: 'b'.repeat(40),
  TELEGRAM_LARAVEL_WEBHOOK_URL: callback,
}
function setup(fetch, changes = {}) {
  const logs = []
  const handler = createTelegramHandler({
    env: (name) => ({ ...secrets, ...changes })[name],
    fetch,
    log: (entry) => logs.push(entry),
  })
  return { handler, logs }
}
function req(body = { update_id: 123, message: { text: '/start private-code' } }, options = {}) {
  return new Request(options.url || 'https://bridge.deno.net/telegram/webhook', {
    method: options.method || 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Telegram-Bot-Api-Secret-Token': secrets.TELEGRAM_WEBHOOK_SECRET,
      ...options.headers,
    },
    ...(options.method === 'GET'
      ? {}
      : { body: typeof body === 'string' ? body : JSON.stringify(body) }),
  })
}

test('inbound forwards the original update once, using only the separate forward secret', async () => {
  const calls = []
  const { handler, logs } = setup(async (...args) => {
    calls.push(args)
    return Response.json({ ok: true, private: 'NEVER_EXPOSE' })
  })
  const body =
    '{ "update_id": 123, "message": {"text":"/start PRIVATE_CODE"}, "url":"https://evil.test" }'
  const result = await handler(req(body))
  assert.equal(result.status, 200)
  assert.deepEqual(await result.json(), { ok: true })
  assert.equal(result.headers.get('Cache-Control'), 'no-store')
  assert.equal(calls.length, 1)
  const [url, options] = calls[0]
  assert.equal(url, callback)
  assert.equal(options.body, body)
  assert.equal(options.redirect, 'manual')
  assert.equal(options.headers['X-Telegram-Forward-Secret'], secrets.TELEGRAM_FORWARD_SECRET)
  assert.equal(options.headers.Authorization, undefined)
  assert.equal(options.headers['X-Telegram-Bot-Api-Secret-Token'], undefined)
  assert.ok(options.signal instanceof AbortSignal)
  assert.deepEqual(logs[0], {
    event: 'telegram_forward',
    status: 200,
    durationMs: logs[0].durationMs,
    errorCode: null,
  })
  assert.doesNotMatch(JSON.stringify(logs), /PRIVATE_CODE|NEVER_EXPOSE|update_id|ffff|wwww|bbbb/)
})

test('inbound rejects wrong Telegram authentication and outgoing Bearer authorization before fetch', async () => {
  let calls = 0
  const { handler } = setup(async () => {
    calls++
    return Response.json({ ok: true })
  })
  for (const value of [
    '',
    'wrong',
    secrets.TELEGRAM_FORWARD_SECRET,
    secrets.TELEGRAM_BRIDGE_SECRET,
  ]) {
    assert.equal(
      (
        await handler(
          req(
            {},
            {
              headers: {
                'X-Telegram-Bot-Api-Secret-Token': value,
                Authorization: `Bearer ${secrets.TELEGRAM_BRIDGE_SECRET}`,
              },
            },
          ),
        )
      ).status,
      401,
    )
  }
  assert.equal((await handler(req({}, { method: 'GET' }))).status, 404)
  assert.equal(calls, 0)
})

test('inbound fails closed for absent, weak or reused secrets and unapproved callbacks', async () => {
  for (const change of [
    { TELEGRAM_WEBHOOK_SECRET: '' },
    { TELEGRAM_WEBHOOK_SECRET: 'bad secret' },
    { TELEGRAM_FORWARD_SECRET: '' },
    { TELEGRAM_FORWARD_SECRET: secrets.TELEGRAM_WEBHOOK_SECRET },
    { TELEGRAM_FORWARD_SECRET: secrets.TELEGRAM_BRIDGE_SECRET },
    ...[
      '',
      'http://brief.web86.site/api/telegram/webhook',
      callback + '?url=evil',
      callback + '#fragment',
      'https://evil.test/api/telegram/webhook',
      'https://127.0.0.1/api/telegram/webhook',
      'https://brief.web86.site:443/api/telegram/webhook',
      'https://user@brief.web86.site/api/telegram/webhook',
    ].map((url) => ({ TELEGRAM_LARAVEL_WEBHOOK_URL: url })),
  ]) {
    const { handler } = setup(() => assert.fail('unexpected fetch'), change)
    assert.equal((await handler(req())).status, 503)
  }
})

test('inbound bounds actual UTF-8 stream size, rejects malformed updates and query parameters', async () => {
  const { handler } = setup(() => assert.fail('unexpected fetch'))
  assert.equal((await handler(req('x'.repeat(16385)))).status, 413)
  assert.equal((await handler(req('я'.repeat(8193)))).status, 413)
  for (const body of [
    '{',
    'null',
    '[]',
    '{}',
    '{"update_id":-1}',
    '{"update_id":"1"}',
    '{"update_id":1.5}',
    '{"update_id":9007199254740992}',
  ])
    assert.equal((await handler(req(body))).status, 400)
  assert.equal(
    (
      await handler(
        req({}, { url: 'https://bridge.deno.net/telegram/webhook?url=https://evil.test' }),
      )
    ).status,
    400,
  )
})

test('Laravel HTTP errors and redirects never become a successful Telegram acknowledgement or retry locally', async () => {
  for (const [upstream, expected] of [
    [301, 502],
    [302, 502],
    [403, 502],
    [422, 502],
    [429, 503],
    [500, 502],
    [503, 503],
  ]) {
    let calls = 0
    const { handler, logs } = setup(async () => {
      calls++
      return new Response('PRIVATE UPSTREAM BODY', {
        status: upstream,
        headers: { Location: 'https://evil.test' },
      })
    })
    const result = await handler(req())
    assert.equal(result.status, expected)
    assert.deepEqual(await result.json(), { ok: false })
    assert.equal(calls, 1)
    assert.equal(logs[0].status, upstream)
    assert.doesNotMatch(JSON.stringify(logs), /PRIVATE|evil/)
  }
})

test('network failures and timeout return retryable errors without exposing exception data', async () => {
  for (const [name, expected] of [
    ['Error', 502],
    ['TimeoutError', 504],
    ['AbortError', 504],
  ]) {
    let calls = 0
    const { handler, logs } = setup(async () => {
      calls++
      const error = new Error('TOKEN SECRET PRIVATE URL')
      error.name = name
      throw error
    })
    const result = await handler(req())
    assert.equal(result.status, expected)
    assert.deepEqual(await result.json(), { ok: false })
    assert.equal(calls, 1)
    assert.doesNotMatch(JSON.stringify(logs), /TOKEN|SECRET|PRIVATE|URL/)
  }
})

test('Laravel must explicitly acknowledge processing with bounded JSON', async () => {
  for (const body of [
    '{}',
    '{"ok":false}',
    '{"ok":"true"}',
    '<html>PRIVATE</html>',
    'x'.repeat(1025),
  ]) {
    const { handler } = setup(async () => new Response(body))
    assert.equal((await handler(req())).status, 502)
  }
})

test('the forwarding deadline is 8 seconds and also bounds the response body', async () => {
  const timeout = AbortSignal.timeout
  const controller = new AbortController()
  AbortSignal.timeout = (ms) => {
    assert.equal(ms, 8000)
    return controller.signal
  }
  try {
    const { handler } = setup(async () => {
      const response = new Response(
        new ReadableStream({
          start(stream) {
            stream.enqueue(new TextEncoder().encode('{"ok":'))
          },
        }),
      )
      setTimeout(() => controller.abort(new DOMException('PRIVATE', 'TimeoutError')), 0)
      return response
    })
    assert.equal((await handler(req())).status, 504)
  } finally {
    AbortSignal.timeout = timeout
  }
})

test('removed diagnostics endpoint stays unavailable even with the old opt-in flag', async () => {
  const { handler } = setup(() => assert.fail('unexpected fetch'), { TELEGRAM_DIAGNOSTICS: '1' })
  const result = await handler(req({}, { url: 'https://bridge.deno.net/telegram/diagnostics' }))
  assert.equal(result.status, 404)
})

test('incoming authentication does not weaken the existing outgoing endpoint', async () => {
  const { handler } = setup(() => assert.fail('unexpected fetch'))
  assert.equal(
    (
      await handler(
        req({ method: 'getMe', payload: {} }, { url: 'https://bridge.deno.net/telegram/send' }),
      )
    ).status,
    401,
  )
})
