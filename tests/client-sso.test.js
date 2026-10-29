import { before, after, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createRouter, createMemoryHistory } from 'vue-router'
import { spawnSync } from 'node:child_process'
let server, ErrorView, i18n
before(async () => {
  server = await createServer({
    configFile: false,
    envFile: false,
    plugins: [(await import('@vitejs/plugin-vue')).default()],
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    logLevel: 'error',
  })
  ErrorView = (await server.ssrLoadModule('/src/views/AccessErrorView.vue')).default
  i18n = (await server.ssrLoadModule('/src/i18n/index.js')).i18n
})
after(async () => await server?.close())
async function html(query, locale = 'en') {
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/access-error', component: ErrorView }],
  })
  await router.push({ path: '/access-error', query })
  return renderToString(createSSRApp(ErrorView).use(router).use(i18n))
}
for (const locale of ['ru', 'en', 'tr', 'zh-CN'])
  test(`SSO failure retains localized Magic Link fallback: ${locale}`, async () => {
    const body = await html(
      { reason: 'sso', project: '018f214d-baf1-7000-8000-000000000001' },
      locale,
    )
    assert.ok(body.includes(i18n.global.t('sso.failed')))
    assert.ok(body.includes(i18n.global.t('sso.magic')))
    assert.ok(body.includes('href="/project/018f214d-baf1-7000-8000-000000000001"'))
    assert.ok(!body.includes('askTheDeveloper'))
  })
test('SSO fallback cannot become an arbitrary redirect', async () => {
  for (const project of [
    'https://evil.example/path',
    '//evil.example',
    '<script>alert(1)</script>',
    '../../admin',
  ]) {
    const body = await html({ reason: 'sso', project })
    assert.ok(!body.includes('href="'))
    assert.ok(!body.includes('<script>'))
  }
})
test('ordinary expired Magic Link keeps its original error text', async () => {
  const body = await html({ reason: 'expired' })
  assert.ok(body.includes(i18n.global.t('ui.thisLinkHasExpired')))
  assert.ok(!body.includes(i18n.global.t('sso.failed')))
})
function php(action, input) {
  const script =
    'require "docs/milestone-8-2/examples/ProjectBriefClientSso.php"; $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); try { $api=new ProjectBriefClientSso($input["origin"],"pb_".str_repeat("a",64)); if ($argv[1]==="render") { $api->transferPage($input["handoff"],$input["label"]); } else { echo "accepted"; } } catch (Throwable $error) { echo "refused"; }'
  return spawnSync('php', ['-r', script, action], {
    encoding: 'utf8',
    input: JSON.stringify(input),
  })
}
test('server helper emits only a fixed POST target, a nonce script and no-JS button', () => {
  const code = 'b'.repeat(64)
  const result = php('render', {
    origin: 'https://brief.example.test',
    handoff: { code, postUrl: 'https://brief.example.test/client-sso/enter' },
    label: 'Continue <script>alert(1)</script>',
  })
  assert.equal(result.status, 0)
  assert.ok(result.stdout.includes('method="post"'))
  assert.ok(result.stdout.includes('action="https://brief.example.test/client-sso/enter"'))
  assert.ok(result.stdout.includes('<noscript><button'))
  assert.ok(result.stdout.includes('<script nonce="'))
  assert.ok(!result.stdout.includes('Continue <script>'))
  assert.ok(!result.stdout.includes('pb_' + 'a'.repeat(64)))
  assert.ok(!result.stdout.includes('?code='))
  assert.ok(result.stdout.includes('name="code" value="' + code + '"'))
})
test('server helper rejects insecure origins and arbitrary transfer targets', () => {
  for (const origin of [
    'http://brief.example.test',
    'https://user:password@brief.example.test',
    'https://brief.example.test/path',
    'https://brief.example.test?target=evil',
  ])
    assert.equal(php('config', { origin }).stdout, 'refused')
  const result = php('render', {
    origin: 'https://brief.example.test',
    handoff: { code: 'b'.repeat(64), postUrl: 'https://evil.example/client-sso/enter' },
    label: 'Continue',
  })
  assert.equal(result.stdout, 'refused')
})
function savedOrder(order) {
  return spawnSync(
    'php',
    [
      '-r',
      'require "docs/milestone-8-2/examples/order-project.php"; try { echo projectBriefOrderProject(json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR), []); } catch (Throwable $error) { echo "refused"; }',
    ],
    { input: JSON.stringify(order), encoding: 'utf8' },
  )
}
test('order entry uses each persisted project UUID instead of a one-project user mapping', () => {
  for (const id of ['018f214d-baf1-7000-8000-000000000001', '018f214d-baf1-7000-8000-000000000002'])
    assert.equal(savedOrder({ pb_project_id: id }).stdout, id)
})
test('pending requests never fall through into direct provisioning and missing projects fail closed', () => {
  for (const order of [
    { pb_request_id: 'pending-request' },
    {},
    { pb_project_id: 'https://evil.example/' },
  ])
    assert.equal(savedOrder(order).stdout, 'refused')
})
