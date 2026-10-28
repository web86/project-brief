import { beforeEach } from 'node:test'
import { locale } from '../src/i18n/index.js'
import test from 'node:test'
import assert from 'node:assert/strict'
import { createApiClient, ApiError, taskBody } from '../src/api/client.js'
beforeEach(() => {
  locale.value = 'ru'
})
const response = (status, data) => ({ ok: status < 400, status, json: async () => data })

test('session mutations acquire CSRF, include cookies and never use bearer credentials', async () => {
  const calls = []
  const client = createApiClient({
    fetchImpl: async (url, options) => {
      calls.push({ url, options })
      return response(200, url === '/api/csrf' ? { token: 'csrf-token' } : { data: {} })
    },
  })
  await client.request('/api/admin/login', {
    method: 'POST',
    body: { email: 'admin@example.test', password: 'test' },
  })
  await client.request('/api/admin/projects', { method: 'POST', body: { title: 'Сайт' } })
  assert.equal(calls.filter((call) => call.url === '/api/csrf').length, 1)
  assert.equal(calls[1].options.credentials, 'same-origin')
  assert.equal(calls[1].options.headers['X-CSRF-TOKEN'], 'csrf-token')
  assert.equal(calls[1].options.headers.Authorization, undefined)
})

test('validation errors remain available and server details from 500 are hidden', async () => {
  const client = createApiClient({
    fetchImpl: async () => response(422, { errors: { title: ['Введите название.'] } }),
  })
  await assert.rejects(
    client.request('/api/test'),
    (error) => error instanceof ApiError && error.errors.title[0] === 'Введите название.',
  )
  const failing = createApiClient({
    fetchImpl: async () => response(500, { message: 'Secret SQL connection' }),
  })
  await assert.rejects(failing.request('/api/test'), (error) => !error.message.includes('Secret'))
})

test('renderer capability survives safe security replay without overriding session headers', async () => {
  const calls = []
  const client = createApiClient({
    securityFlow: { preflight: () => null, observe() {}, ensure: async () => true },
    fetchImpl: async (_url, options) => {
      calls.push(options)
      return calls.length === 1
        ? response(423, { code: 'security_step_up_required', mutationNotStarted: true })
        : response(200, {})
    },
  })
  await client.request('/api/test', {
    headers: { 'X-ProjectBrief-Pdf-Renderer': '2', accept: 'bad', 'x-csrf-token': 'bad' },
  })
  assert.equal(calls.length, 2)
  for (const { headers } of calls) {
    assert.equal(headers['X-ProjectBrief-Pdf-Renderer'], '2')
    assert.equal(headers.Accept, 'application/json')
    assert.equal(headers.accept, undefined)
    assert.equal(headers['x-csrf-token'], undefined)
  }
})

test('expired CSRF does not automatically replay a mutation and resets for next attempt', async () => {
  let mutations = 0
  let tokens = 0
  const client = createApiClient({
    fetchImpl: async (url) =>
      url === '/api/csrf'
        ? response(200, { token: String(++tokens) })
        : response(++mutations === 1 ? 419 : 200, {}),
  })
  await assert.rejects(
    client.request('/api/change', { method: 'POST' }),
    (error) => error.status === 419,
  )
  assert.equal(mutations, 1)
  await client.request('/api/change', { method: 'POST' })
  assert.equal(tokens, 2)
})

test('multipart sends original files and lets the browser set the content boundary', async () => {
  let options
  const client = createApiClient({
    fetchImpl: async (url, value) => {
      if (url === '/api/csrf') return response(200, { token: 'csrf' })
      options = value
      return response(201, {})
    },
  })
  const body = taskBody({
    title: 'Идея',
    location: 'Главная',
    description: 'Описание',
    priority: 'normal',
    attachments: [{ name: 'example.txt', file: new Blob(['original']) }],
  })
  await client.request('/api/client/tasks', { method: 'POST', body })
  assert.equal(options.headers['Content-Type'], undefined)
  assert.equal(await body.get('attachments[]').text(), 'original')
  assert.equal(body.get('status'), null)
  assert.equal(body.get('developerNotes'), null)
})

test('network failure is actionable without losing form data', async () => {
  const client = createApiClient({
    fetchImpl: async () => {
      throw new Error('offline')
    },
  })
  await assert.rejects(
    client.request('/api/test'),
    (error) => error.status === 0 && error.message.includes('Нет связи'),
  )
})

test('stalled requests abort with a stable timeout error instead of leaving loading active', async (context) => {
  context.mock.timers.enable({ apis: ['setTimeout'] })
  const client = createApiClient({
    timeoutMs: 200,
    fetchImpl: async (_url, options) =>
      new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true })
      }),
  })
  const result = assert.rejects(
    client.request('/api/client/me'),
    (error) => error instanceof ApiError && error.code === 'timeout',
  )
  context.mock.timers.tick(201)
  await result
})

test('caller cancellation aborts a pending request without converting it to a network error', async () => {
  const controller = new AbortController()
  const client = createApiClient({
    fetchImpl: (_url, options) =>
      new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(options.signal.reason), {
          once: true,
        })
      }),
  })
  const pending = assert.rejects(
    client.request('/api/export-data', { signal: controller.signal }),
    (error) => error.name === 'AbortError',
  )
  controller.abort()
  await pending
})

test('cancellation while acquiring CSRF returns immediately without sending the mutation', async () => {
  const controller = new AbortController()
  let mutate = false,
    complete
  const client = createApiClient({
    fetchImpl: (url) => {
      if (url !== '/api/csrf') mutate = true
      return new Promise((resolve) => {
        complete = () => resolve(response(200, { token: 'csrf' }))
      })
    },
  })
  const pending = assert.rejects(
    client.request('/api/export-data', { method: 'POST', body: {}, signal: controller.signal }),
    (error) => error.name === 'AbortError',
  )
  controller.abort()
  await pending
  assert.equal(mutate, false)
  complete()
})

test('bootstrap 429 stops before a mutation and never retries automatically', async () => {
  const calls = []
  const client = createApiClient({
    fetchImpl: async (url) => {
      calls.push(url)
      return response(429, { code: 'rate_limited' })
    },
  })
  await assert.rejects(
    client.request('/api/admin/login', { method: 'POST', body: {} }),
    (error) => error instanceof ApiError && error.status === 429,
  )
  assert.deepEqual(calls, ['/api/csrf'])
})
