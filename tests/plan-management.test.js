import assert from 'node:assert/strict'
import { test, before, after } from 'node:test'
import { reactive, createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { createServer } from 'vite'
import {
  newPlanConfiguration,
  normalizedPlan,
  setUnlimited,
  createPlanRequests,
} from '../src/services/planManagement.js'
import { isSensitiveMutation, isSecurityConfirmationScreen } from '../src/services/stepUp.js'
import { planMessages } from '../src/locales/planManagement.js'
const definitions = [
  {
    key: 'clients.max',
    kind: 'integer',
    availability: 'available',
    default: { kind: 'integer', value: 0 },
    allow_unlimited: true,
  },
  {
    key: 'api.agents.enabled',
    kind: 'boolean',
    availability: 'available',
    default: { kind: 'boolean', value: false },
  },
  {
    key: 'developers.max',
    kind: 'integer',
    availability: 'available',
    default: { kind: 'integer', value: 0 },
    derived: true,
  },
  { key: 'storage.bytes.max', kind: 'integer', availability: 'planned', default: null },
  {
    key: 'formwatch.enabled',
    kind: 'boolean',
    availability: 'unavailable',
    default: { kind: 'boolean', value: false },
  },
]
let vite, Editor, createRouter, locale
before(async () => {
  vite = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
    mode: 'test',
  })
  Editor = (await vite.ssrLoadModule('/src/components/PlanEntitlements.vue')).default
  createRouter = (await vite.ssrLoadModule('/src/router/index.js')).createProjectRouter
  locale = (await vite.ssrLoadModule('/src/i18n/index.js')).locale
})
after(async () => vite?.close())
test('registry editor creates only supported editable values from reactive definitions', () => {
  const configuration = newPlanConfiguration(reactive(definitions))
  assert.deepEqual(
    configuration.entitlements.map((e) => e.key),
    ['clients.max', 'api.agents.enabled'],
  )
  assert.deepEqual(Object.keys(configuration.translations), ['ru', 'en', 'tr', 'zh-CN'])
  assert.equal(configuration.price.amountMinor, null)
  configuration.entitlements[0].value.value = 42
  assert.equal(definitions[0].default.value, 0)
})
test('explicit unlimited round-trips without magic sentinel and normalization never infers access from price', () => {
  const configuration = reactive(newPlanConfiguration(definitions))
  setUnlimited(configuration.entitlements[0], definitions[0], true)
  assert.deepEqual(configuration.entitlements[0].value, { kind: 'unlimited' })
  setUnlimited(configuration.entitlements[0], definitions[0], false)
  assert.deepEqual(configuration.entitlements[0].value, { kind: 'integer', value: 0 })
  configuration.price.amountMinor = ''
  configuration.price.currency = ''
  configuration.price.interval = ''
  const normalized = normalizedPlan(configuration)
  assert.equal(normalized.accessClass, 'free')
  assert.equal(normalized.price.amountMinor, null)
  assert.equal(configuration.price.amountMinor, '')
})
test('timeout retry retains operation key and expected revision; success ends that intent', async () => {
  let first = true,
    sequence = 0
  const calls = []
  const send = createPlanRequests(
    async (path, options) => {
      calls.push(options)
      if (first) {
        first = false
        throw new Error('unknown timeout')
      }
      return { ok: true }
    },
    () => `key-${++sequence}`,
  )
  await assert.rejects(send('/api/platform/plans', 'POST', { code: 'fourth' }, '9'))
  await send('/api/platform/plans', 'POST', { code: 'fourth' }, '9')
  await send('/api/platform/plans', 'POST', { code: 'fourth' }, '9')
  assert.equal(calls[0].headers['Idempotency-Key'], calls[1].headers['Idempotency-Key'])
  assert.notEqual(calls[1].headers['Idempotency-Key'], calls[2].headers['Idempotency-Key'])
  assert.equal(calls[0].headers['If-Match'], '"revision:9"')
})
for (const language of ['ru', 'en'])
  test(`dynamic rendered registry is localized and cannot edit unavailable/derived values: ${language}`, async () => {
    locale.value = language
    const html = await renderToString(
      createSSRApp(Editor, { definitions, configuration: newPlanConfiguration(definitions) }),
    )
    assert.ok(html.includes(planMessages[language].featureNames.clients.max))
    assert.ok(html.includes(planMessages[language].states.planned))
    assert.ok(html.includes(planMessages[language].states.unavailable))
    assert.equal((html.match(/<input/g) || []).length, 3)
    assert.ok(!html.includes('value="-1"'))
  })
test('platform mutations join the existing step-up flow and direct screens prepare it', () => {
  for (const path of [
    '/api/platform/plans',
    '/api/platform/plans/x/versions/y/publish',
    '/api/platform/plan-settings/drafts',
    '/api/platform/plan-transition-rules/copies',
  ]) {
    assert.equal(isSensitiveMutation(path, 'POST'), true)
    assert.equal(isSensitiveMutation(path, 'GET'), false)
  }
  assert.equal(isSecurityConfirmationScreen('/platform/plans/policies'), true)
})
test('all plan routes verify primary authority without opening any workspace content', async () => {
  for (const path of [
    '/platform/plans',
    '/platform/plans/policies',
    '/platform/plans/transitions',
    '/platform/plans/inventory',
    '/platform/plans/inventory/19fe7520-ef15-51ae-ae94-3a582ed5474e',
    '/platform/plans/p/versions/v',
  ]) {
    setActivePinia(createPinia())
    const calls = []
    const router = createRouter({
      apiMode: true,
      request: async (url) => {
        calls.push(url)
        return url === '/api/security/status'
          ? { signedIn: true, state: 'authenticated', platformAdmin: true }
          : { canManage: true }
      },
    })
    await router.push(path)
    assert.equal(router.currentRoute.value.path, path)
    assert.deepEqual(calls, ['/api/security/status', '/api/platform/plan-access'])
  }
})
