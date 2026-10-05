import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '../src/api/client.js'
import { useProjectManagementStore } from '../src/stores/projectManagement.js'

test('management API scopes links by client and never retains plaintext in the store', async () => {
  setActivePinia(createPinia())
  api.resetCsrf()
  const previous = globalThis.fetch
  const calls = []
  const people = [
    { id: 'john', name: 'John', accessLinks: [{ id: 1, active: true }] },
    { id: 'anna', name: 'Anna', accessLinks: [{ id: 2, active: true }] },
  ]
  globalThis.fetch = async (url, options) => {
    calls.push([url, options.method])
    const payload =
      url === '/api/csrf'
        ? { token: 'csrf' }
        : url.endsWith('/access-links')
          ? { url: 'https://example.com/access/one-time-secret' }
          : url.endsWith('/clients')
            ? { data: people }
            : url.endsWith('/sections')
              ? { data: [{ id: 'main', name: 'Главная', position: 1 }] }
              : { data: { id: 'project' } }
    return new Response(JSON.stringify(payload), {
      headers: { 'Content-Type': 'application/json' },
    })
  }
  try {
    const store = useProjectManagementStore()
    await store.load('project')
    const link = await store.createAccessLink('john')
    assert.equal(link, 'https://example.com/access/one-time-secret')
    assert.ok(
      calls.some(
        ([url, method]) =>
          url === '/api/admin/projects/project/clients/john/access-links' && method === 'POST',
      ),
    )
    assert.equal(JSON.stringify(store.$state).includes('one-time-secret'), false)
    await store.revokeAccess('anna')
    assert.ok(
      calls.some(
        ([url, method]) =>
          url === '/api/admin/projects/project/clients/anna/access-links/2' && method === 'DELETE',
      ),
    )
    assert.equal(
      calls.some(([url, method]) => url.endsWith('/john/access-links/1') && method === 'DELETE'),
      false,
    )
  } finally {
    globalThis.fetch = previous
    api.resetCsrf()
  }
})
