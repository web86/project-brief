import test from 'node:test'
import assert from 'node:assert/strict'
import { createStaticDemoClient, querySnapshot } from '../src/api/staticDemo.js'
import { validateSchema } from '../deployment/static-demo-schema.mjs'
class ApiError extends Error {
  constructor(status, message, errors, code) {
    super(message)
    Object.assign(this, { status, code })
  }
}
test('static transport rejects every mutation and native credential contract before fetch', async () => {
  const calls = []
  const client = createStaticDemoClient({
    ApiError,
    fetchImpl: async (...args) => {
      calls.push(args)
      throw Error('No network expected')
    },
    storage: null,
  })
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
    await assert.rejects(client.request('/api/admin/account', { method }), {
      status: 403,
      code: 'demo_read_only',
    })
  for (const path of [
    '/api/security/passkey/register/options',
    '//evil.test/api/foo',
    'https://evil.test/api/foo',
    '/not-api',
  ])
    await assert.rejects(client.request(path))
  assert.equal(calls.length, 0)
})
test('static transport fetches role snapshots only, keeps cached data immutable and rejects foreign IDs', async () => {
  const calls = [],
    values = new Map()
  const file = 'snapshots/developer-' + 'a'.repeat(64) + '.json'
  const clientFile = 'snapshots/client-' + 'b'.repeat(64) + '.json'
  const manifest = {
    schemaVersion: 1,
    dataset: 'harborline-v1',
    clientProject: 'client-project',
    roles: {
      developer: { routes: { '/api/admin/tasks/own': { file, task: 'own' } } },
      client: { routes: { '/api/client/tasks': { file: clientFile } } },
    },
  }
  const payloads = {
    '/manifest.json': manifest,
    ['/' + file]: { data: [{ id: 'own', title: 'Safe fictional task' }] },
    ['/' + clientFile]: { data: [] },
  }
  let current = '/admin'
  const client = createStaticDemoClient({
    ApiError,
    pathname: () => current,
    storage: { getItem: (key) => values.get(key), setItem: (key, value) => values.set(key, value) },
    fetchImpl: async (url) => {
      calls.push(url)
      return { ok: true, json: async () => payloads[url] }
    },
  })
  const first = await client.request('/api/admin/tasks/own')
  first.data.title = 'Local edit'
  assert.equal((await client.request('/api/admin/tasks/own')).data.title, 'Safe fictional task')
  assert.equal(calls.filter((url) => url === '/' + file).length, 1)
  await assert.rejects(client.request('/api/admin/tasks/foreign'), { status: 404 })
  await client.request('/api/demo/enter/client')
  current = '/project/client-project'
  await client.request('/api/client/tasks')
  await assert.rejects(client.request('/api/admin/tasks/own'), { status: 404 })
  assert.ok(calls.every((url) => !url.startsWith('/api/')))
})
test('static read queries search, filter, sort and paginate real response shapes', () => {
  const rows = Array.from({ length: 30 }, (_, id) => ({
    id,
    name: 'Project ' + String(id).padStart(2, '0'),
    status: id % 2 ? 'active' : 'inactive',
    createdAt: `2026-10-${String(id + 1).padStart(2, '0')}`,
    updatedAt: '2026-10-31',
  }))
  const value = { data: rows, meta: { current_page: 1, last_page: 2, total: 30 } }
  assert.equal(
    querySnapshot('/api/admin/projects', value, new URLSearchParams('page=2')).data.length,
    10,
  )
  const filtered = querySnapshot(
    '/api/admin/projects',
    value,
    new URLSearchParams('q=Project&status=active&sort=name'),
  )
  assert.equal(filtered.meta.total, 15)
  assert.equal(filtered.data[0].name, 'Project 01')
  assert.equal(value.data.length, 30)
})
test('pinned schemas reject new fields, wrong types, malformed UUIDs and unreviewed empty lists', () => {
  const schema = {
    type: 'object',
    properties: { id: { type: 'string', format: 'uuid' }, items: { type: 'array', maxItems: 0 } },
    required: ['id', 'items'],
    additionalProperties: false,
  }
  const value = { id: '58217ba8-2afd-54dc-8941-f2428446b128', items: [] }
  validateSchema(value, schema)
  for (const bad of [
    { ...value, password: 'secret' },
    { ...value, id: 'bad' },
    { ...value, items: [{}] },
  ])
    assert.throws(() => validateSchema(bad, schema))
})
