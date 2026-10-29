import assert from 'node:assert/strict'
import { test, before, after } from 'node:test'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { appDestination } from '../src/services/session.js'
let vite, createRouter
before(async () => {
  vite = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  createRouter = (await vite.ssrLoadModule('/src/router/index.js')).createProjectRouter
})
after(async () => vite?.close())
test('platform login destination uses the shared session and preserves MFA enrollment', async () => {
  assert.equal(
    await appDestination(async () => ({ authenticated: true, type: 'platform' })),
    '/platform',
  )
  assert.equal(
    await appDestination(async () => ({ authenticated: false, state: 'enrollment_required' })),
    '/admin/security',
  )
})
test('platform-only navigation never bootstraps a workspace or reads project content', async () => {
  setActivePinia(createPinia())
  const calls = []
  const router = createRouter({
    apiMode: true,
    request: async (path) => {
      calls.push(path)
      assert.equal(path, '/api/security/status')
      return { signedIn: true, state: 'authenticated', platformAdmin: true }
    },
  })
  await router.push('/platform')
  assert.equal(router.currentRoute.value.path, '/platform')
  assert.deepEqual(calls, ['/api/security/status'])
})
test('an unauthenticated direct platform navigation ends at existing login', async () => {
  setActivePinia(createPinia())
  const calls = []
  const router = createRouter({
    apiMode: true,
    request: async (path) => {
      calls.push(path)
      return { signedIn: false, state: 'guest' }
    },
  })
  await router.push('/platform')
  assert.equal(router.currentRoute.value.path, '/admin/login')
  assert.ok(calls.every((path) => path === '/api/security/status'))
})
