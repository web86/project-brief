import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises'
import { createServer as createNetServer } from 'node:net'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = new URL(
  process.env.INTEGRATION_OUTPUT_DIR || '../docs/milestone-5-0-2/',
  import.meta.url,
)
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone501-e2e-'))
const database = path.join(scratch, 'browser.sqlite')
await writeFile(database, '')
async function freePort() {
  const probe = createNetServer()
  await new Promise((resolve) => probe.listen(0, '127.0.0.1', resolve))
  const port = probe.address().port
  await new Promise((resolve) => probe.close(resolve))
  return port
}
const backendPort = await freePort(),
  vitePort = await freePort(),
  origin = `http://127.0.0.1:${vitePort}`
const env = {
  ...process.env,
  PHP_CLI_SERVER_WORKERS: '1',
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_DOMAIN: '',
  CACHE_STORE: 'array',
  LOG_CHANNEL: 'null',
  MAIL_MAILER: 'array',
  TELEGRAM_BOT_TOKEN: '',
  TELEGRAM_BRIDGE_ENABLED: 'false',
  QUEUE_CONNECTION: 'sync',
  ARGON_MEMORY: '1024',
  ARGON_TIME: '1',
}
function run(args) {
  const result = spawnSync('php', args, { cwd: root + '/api', env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
const vite = await createServer({
  envFile: false,
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: {
    host: '127.0.0.1',
    port: vitePort,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/release/**'] },
    proxy: {
      '/api': `http://127.0.0.1:${backendPort}`,
      '/access/': `http://127.0.0.1:${backendPort}`,
    },
  },
  logLevel: 'error',
})
const errors = [],
  screenshots = []
let backend,
  browser,
  backendLog = ''
async function language(page, code) {
  await page.evaluate(async (code) => {
    const { setManualLocale } = await import('/src/i18n/index.js')
    setManualLocale(code)
  }, code)
}
async function theme(page, mode) {
  await page.evaluate(async (mode) => {
    const { theme } = await import('/src/services/theme.js')
    theme.select(mode)
  }, mode)
}
async function shot(page, name, element) {
  if (element) {
    await element.evaluate((el) => el.scrollIntoView({ block: 'start' }))
    await page.evaluate(() => scrollBy(0, -84))
  } else await page.evaluate(() => scrollTo(0, 0))
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
  await page.screenshot({ path: new URL(name + '.png', output).pathname })
  screenshots.push(name)
}
async function login(page, email) {
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill(email)
  await page.locator('#admin-password').fill('Local-e2e-password!')
  await page.locator('form [type=submit]').click()
  await page.waitForURL(origin + '/admin')
}
async function request(page, url, options) {
  return page.evaluate(
    async ({ url, options }) => {
      const { api } = await import('/src/api/client.js')
      return api.request(url, options)
    },
    { url, options },
  )
}
try {
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(run(['tests/milestone501-fixture.php']))
  backend = spawn(
    'php',
    [
      '-S',
      `127.0.0.1:${backendPort}`,
      root + '/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    { cwd: root + '/api/public', env, stdio: ['ignore', 'ignore', 'pipe'] },
  )
  backend.stderr.on('data', (bytes) => (backendLog += bytes))
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`http://127.0.0.1:${backendPort}/api/health`)).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 50))
    if (i === 99) throw new Error(backendLog)
  }
  await vite.listen()
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1080 },
    locale: 'ru-RU',
    reducedMotion: 'reduce',
  })
  const page = await context.newPage()
  page.setDefaultTimeout(10000)
  page.on('pageerror', (e) => errors.push(e.message))
  let fault = null
  const calls = []
  await page.route('**/api/admin/integrations**', async (route) => {
    const req = route.request(),
      url = new URL(req.url())
    calls.push({ path: url.pathname, method: req.method() })
    if (fault && fault.match(req, url)) {
      const current = fault
      fault = null
      if (current.delay) await new Promise((resolve) => setTimeout(resolve, current.delay))
      if (current.abort) return route.abort('internetdisconnected')
      if (current.pass) return route.continue()
      return route.fulfill({
        status: current.status || 200,
        contentType: 'application/json',
        body: JSON.stringify(current.body || { message: 'Synthetic test failure' }),
      })
    }
    return route.continue()
  })
  const once = (method, suffix, options = {}) => {
    fault = {
      match: (req, url) =>
        req.method() === method && url.pathname === '/api/admin/integrations' + suffix,
      ...options,
    }
  }
  async function clear() {
    await page.evaluate(async () => (await import('/src/services/toast.js')).toast.clear())
  }
  async function notified(type, text) {
    await page.locator('.app-toast.toast-' + type).waitFor()
    assert.equal(await page.locator('.app-toast').count(), 1, 'one notification per action')
    if (text) assert.equal(await page.locator('.app-toast p').innerText(), text)
    assert.equal(await page.locator('.integrations-page > .form-message').count(), 0)
  }
  async function failed(method, suffix, trigger, status = 500, body) {
    await clear()
    once(method, suffix, status === 'offline' ? { abort: true } : { status, body })
    await trigger()
    await notified('error')
    assert.equal(await page.locator('.app-toast.toast-success,.app-toast.toast-info').count(), 0)
  }
  await login(page, 'owner501@example.test')
  await language(page, 'ru')
  once('GET', '', { status: 500 })
  await page.locator('.admin-sidebar a[href="/admin/settings/integrations"]').click()
  await notified('error')
  assert.equal(
    await page.locator('.integrations-page [role=alert]').count(),
    0,
    'load errors use global toast',
  )
  await page.locator('.integrations-page > button').click()
  await page.locator('#token-name').waitFor()
  await clear()
  const tokenSubmit = page.locator('#integration-panel-tokens [type=submit]')
  await page.locator('#token-name').fill('CRM QA')
  await failed('POST', '/tokens', () => tokenSubmit.click(), 422, {
    message: 'Invalid',
    errors: { name: ['Invalid name'] },
  })
  assert.equal(await page.locator('#integration-panel-tokens .field-error').count(), 1)
  await failed('POST', '/tokens', () => tokenSubmit.click(), 500)
  await failed('POST', '/tokens', () => tokenSubmit.click(), 403)
  await failed('POST', '/tokens', () => tokenSubmit.click(), 'offline')
  await clear()
  const createsBefore = calls.filter(
    (r) => r.method === 'POST' && r.path.endsWith('/tokens'),
  ).length
  once('POST', '/tokens', { delay: 180, pass: true })
  await tokenSubmit.evaluate((button) => {
    button.form.requestSubmit()
    button.form.requestSubmit()
  })
  await page.locator('dialog[open]').waitFor()
  await notified('success')
  assert.equal(
    calls.filter((r) => r.method === 'POST' && r.path.endsWith('/tokens')).length,
    createsBefore + 1,
  )
  const tokenSecret = await page.locator('.integration-secret-value').innerText()
  assert.match(tokenSecret, /^pb_/)
  await clear()
  await page.evaluate(() =>
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        writeText: async () => {
          throw new Error('clipboard denied')
        },
      },
    }),
  )
  await page.locator('dialog[open] .button.primary').click()
  await notified('error')
  await clear()
  await page.evaluate(() =>
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        writeText: async (value) => {
          window.copiedSecret = value
        },
      },
    }),
  )
  await page.locator('dialog[open] .button.primary').click()
  await notified('success', 'Скопировано')
  await page.locator('dialog[open] .button.primary').click()
  assert.equal(await page.locator('.app-toast').count(), 1)
  assert.equal(await page.evaluate(() => window.copiedSecret), tokenSecret)
  await page.locator('dialog[open] .button.secondary').click()
  const stale = await request(page, '/api/admin/integrations')
  const token = stale.tokens[0]
  const tokenCard = page.locator('#integration-panel-tokens .integration-item', {
    hasText: 'CRM QA',
  })
  page.once('dialog', (d) => d.dismiss())
  await tokenCard.getByRole('button').click()
  assert.equal(await tokenCard.count(), 1)
  await clear()
  once('DELETE', '/tokens/' + token.id, { status: 500 })
  page.once('dialog', (d) => d.accept())
  await tokenCard.getByRole('button').click()
  await notified('error')
  assert.equal(await tokenCard.count(), 1)
  await clear()
  once('DELETE', '/tokens/' + token.id, { delay: 250, pass: true })
  page.once('dialog', (d) => d.accept())
  await tokenCard.getByRole('button').click()
  assert.equal(await tokenCard.count(), 1, 'no optimistic removal before DELETE completes')
  await tokenCard.waitFor({ state: 'detached' })
  await notified('success', 'API-ключ отозван. Для дальнейшей работы создайте новый.')
  assert.equal(
    (
      await context.request.get(origin + '/api/v1/projects', {
        headers: { Authorization: 'Bearer ' + tokenSecret },
      })
    ).status(),
    401,
  )
  await theme(page, 'light')
  await shot(page, 'desktop-revoked-toast-ru-light')
  // A successful later mutation receives an old list with an apparently active revoked token.
  await page.locator('#token-name').fill('Surviving key')
  once('GET', '', {
    body: {
      ...stale,
      tokens: [
        ...stale.tokens,
        {
          ...token,
          id: 'expired-fixture',
          name: 'Expired fixture',
          expiresAt: '2000-01-01T00:00:00Z',
        },
        {
          ...token,
          id: 'revoked-fixture',
          name: 'Revoked fixture',
          revokedAt: '2020-01-01T00:00:00Z',
        },
      ],
    },
  })
  await clear()
  await tokenSubmit.click()
  await page.locator('dialog[open]').waitFor()
  await notified('success')
  await page.locator('dialog[open] .button.secondary').click()
  assert.equal(await tokenCard.count(), 0, 'stale list cannot resurrect revoked token')
  assert.equal(
    await page.locator('#integration-panel-tokens .integration-item').count(),
    0,
    'revoked and expired metadata is excluded by active-only policy',
  )
  await page.reload()
  await page.locator('#token-name').waitFor()
  assert.equal(await tokenCard.count(), 0)
  assert.equal(await page.locator('.integration-secret-value').count(), 0)
  await language(page, 'ru')
  await page.getByRole('tab', { name: 'Webhooks', exact: true }).click()
  const hookForm = page.locator('#integration-panel-webhooks form')
  const hookSubmit = hookForm.locator('[type=submit]')
  await page.locator('#hook-name').fill('CRM cleanup')
  await page.locator('#hook-url').fill('https://example.com/events')
  await failed('POST', '/webhooks', () => hookSubmit.click(), 422, {
    message: 'Invalid URL',
    errors: { url: ['Unsafe destination'] },
  })
  assert.equal(await hookForm.locator('.field-error').count(), 1)
  await clear()
  await hookSubmit.click()
  await page.locator('dialog[open]').waitFor()
  await notified('success')
  await page.locator('dialog[open] .button.secondary').click()
  let state = await request(page, '/api/admin/integrations')
  const hook = state.webhooks[0],
    suffix = '/webhooks/' + hook.id
  const card = page.locator('.webhook-item', {
    has: page.getByRole('heading', { name: 'CRM cleanup', exact: true }),
  })
  await card.getByRole('button', { name: 'Изменить', exact: true }).click()
  await hookForm.locator('input[value=selected]').check()
  assert.equal(await hookSubmit.isDisabled(), true)
  await hookForm.locator(`input[value="${fixture.projects[0].id}"]`).check()
  await failed('PATCH', suffix, () => hookSubmit.click(), 500)
  assert.equal(await hookForm.locator(`input[value="${fixture.projects[0].id}"]`).isChecked(), true)
  await clear()
  await hookSubmit.click()
  await notified('success')
  await failed('PATCH', suffix, () => card.locator('.integration-toggle input').click(), 500)
  assert.equal(await card.locator('.integration-toggle input').isChecked(), true)
  await clear()
  await card.locator('.integration-toggle input').click()
  await notified('success')
  assert.equal(await card.locator('.integration-toggle input').isChecked(), false)
  await clear()
  await card.locator('.integration-toggle input').click()
  await notified('success')
  for (const status of [500, 403, 'offline'])
    await failed(
      'POST',
      suffix + '/test',
      () => card.getByRole('button', { name: 'Тестовая доставка', exact: true }).click(),
      status,
    )
  await clear()
  const beforeTests = calls.filter((r) => r.method === 'POST' && r.path.endsWith('/test')).length
  once('POST', suffix + '/test', { delay: 180, pass: true })
  await card.getByRole('button', { name: 'Тестовая доставка', exact: true }).evaluate((button) => {
    button.click()
    button.click()
  })
  await notified('info')
  assert.equal(
    calls.filter((r) => r.method === 'POST' && r.path.endsWith('/test')).length,
    beforeTests + 1,
  )
  await clear()
  await card.getByRole('button', { name: 'История доставки', exact: true }).click()
  await page.getByRole('heading', { name: 'Проверка webhook', exact: true }).waitFor()
  run(['tests/milestone501-fixture.php', 'reject'])
  await page.getByRole('button', { name: 'Обновить', exact: true }).click()
  await page.getByRole('button', { name: 'Повторить', exact: true }).waitFor()
  state = await request(page, '/api/admin/integrations' + suffix + '/deliveries')
  const delivery = state.data[0]
  await failed(
    'POST',
    suffix + '/deliveries/' + delivery.id + '/retry',
    () => page.getByRole('button', { name: 'Повторить', exact: true }).click(),
    500,
  )
  await clear()
  await page.getByRole('button', { name: 'Повторить', exact: true }).click()
  await notified('info')
  await clear()
  await failed(
    'GET',
    suffix + '/deliveries',
    () => page.getByRole('button', { name: 'Обновить', exact: true }).click(),
    500,
  )
  await clear()
  await failed(
    'POST',
    suffix + '/rotate',
    async () => {
      page.once('dialog', (d) => d.accept())
      await card.getByRole('button', { name: 'Сменить секрет', exact: true }).click()
    },
    500,
  )
  await clear()
  once('GET', '', { status: 500 })
  page.once('dialog', (d) => d.accept())
  await card.getByRole('button', { name: 'Сменить секрет', exact: true }).click()
  await page.locator('dialog[open]').waitFor()
  await notified('error')
  assert.equal(
    await page.locator('.app-toast.toast-success').count(),
    0,
    'failed post-mutation refresh never reports success',
  )
  await page.locator('dialog[open] .button.secondary').click()
  await page.locator('.integrations-page > button').click()
  await page.locator('#hook-name').waitFor()
  await clear()
  page.once('dialog', (d) => d.dismiss())
  await card.getByRole('button', { name: 'Сменить секрет', exact: true }).click()
  assert.equal(await page.locator('dialog[open]').count(), 0)
  page.once('dialog', (d) => d.accept())
  await card.getByRole('button', { name: 'Сменить секрет', exact: true }).click()
  await page.locator('dialog[open]').waitFor()
  await notified('success')
  await page.locator('dialog[open] .button.secondary').click()
  await clear()
  let confirmation = ''
  page.once('dialog', (d) => {
    confirmation = d.message()
    return d.dismiss()
  })
  await card.getByRole('button', { name: 'Удалить', exact: true }).click()
  assert.equal(
    confirmation,
    'Удалить Webhook „CRM cleanup“? Вместе с ним безвозвратно удалится история отправок и попыток доставки. Уже отправленные события отозвать невозможно.',
  )
  assert.equal(await card.count(), 1)
  assert.equal(
    await page
      .getByRole('heading', { name: 'История доставки · CRM cleanup', exact: true })
      .count(),
    1,
  )
  once('DELETE', suffix, { status: 500 })
  page.once('dialog', (d) => d.accept())
  await card.getByRole('button', { name: 'Удалить', exact: true }).click()
  await notified('error')
  assert.equal(await card.count(), 1)
  assert.equal(
    await page
      .getByRole('heading', { name: 'История доставки · CRM cleanup', exact: true })
      .count(),
    1,
  )
  // Hold an old history read until after successful deletion.
  let releaseHistory, historyStarted
  const gate = new Promise((resolve) => {
    releaseHistory = resolve
  })
  const entered = new Promise((resolve) => {
    historyStarted = resolve
  })
  const oldHistory = state
  await page.route(
    '**' + suffix + '/deliveries?*',
    async (route) => {
      historyStarted()
      await gate
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(oldHistory),
      })
    },
    { times: 1 },
  )
  await page.getByRole('button', { name: 'Обновить', exact: true }).click()
  await entered
  await clear()
  once('DELETE', suffix, { delay: 180, pass: true })
  page.once('dialog', (d) => d.accept())
  await card.getByRole('button', { name: 'Удалить', exact: true }).click()
  assert.equal(await card.count(), 1)
  await card.waitFor({ state: 'detached' })
  await notified('success')
  releaseHistory()
  await page.waitForFunction(() => !document.querySelector('.webhook-item'))
  assert.equal(
    await page
      .getByRole('heading', { name: 'История доставки · CRM cleanup', exact: true })
      .count(),
    0,
  )
  assert.equal(
    (
      await context.request.get(origin + '/api/admin/integrations' + suffix + '/deliveries')
    ).status(),
    404,
  )
  // Screenshot matrix: localized existing toast, resolved system themes and no overflow at 320px.
  for (const locale of ['ru', 'en'])
    for (const mode of ['light', 'dark', 'system']) {
      await page.emulateMedia({ colorScheme: mode === 'system' ? 'dark' : 'light' })
      await language(page, locale)
      await theme(page, mode)
      await clear()
      await page.evaluate(async () => {
        const { toast } = await import('/src/services/toast.js')
        const { t } = await import('/src/i18n/index.js')
        toast.success(() => t('integrations.webhookDeleted'), { duration: 60000 })
      })
      for (const width of [1440, 320]) {
        await page.setViewportSize({ width, height: width === 320 ? 900 : 1080 })
        await shot(page, `${width === 320 ? 'mobile' : 'desktop'}-toast-${locale}-${mode}`)
        const box = await page.locator('.app-toast').boundingBox()
        assert.ok(box.x >= 0 && box.x + box.width <= width)
      }
    }
  await page.setViewportSize({ width: 320, height: 900 })
  await language(page, 'ru')
  await theme(page, 'light')
  await clear()
  await page.locator('.admin-menu-trigger').click()
  const drawer = page.locator('#admin-drawer[open]')
  await drawer.waitFor()
  assert.equal(await drawer.locator('.sidebar-project-heading').count(), 1)
  assert.equal(await drawer.getByRole('link', { name: 'Все проекты', exact: true }).count(), 1)
  assert.equal(
    await drawer.locator('.personal-settings-nav a[href="/admin/settings/integrations"]').count(),
    1,
  )
  await shot(page, 'mobile-owner-navigation-ru-light')
  await drawer.locator('.sidebar-project-heading').click()
  await page.waitForURL(origin + '/admin')
  assert.equal(await page.locator('#admin-drawer[open]').count(), 0)
  await page.goto(origin + '/admin/projects/' + fixture.projects[0].id + '/brief')
  await page.locator('.admin-menu-trigger').click()
  await page.locator('#admin-drawer[open] .project-all-projects').waitFor()
  assert.equal(await page.locator('#admin-drawer .project-all-projects').count(), 1)
  assert.equal(
    await page
      .locator('#admin-drawer .project-personal-navigation a[href="/admin/settings/integrations"]')
      .count(),
    1,
  )
  await shot(page, 'mobile-project-navigation-ru-light')
  await page.locator('#admin-drawer .project-all-projects').click()
  await page.waitForURL(origin + '/admin')
  await page.setViewportSize({ width: 1440, height: 1080 })
  await page
    .locator('.admin-sidebar .sidebar-workspace select')
    .selectOption(fixture.otherWorkspace)
  await page.waitForURL(origin + '/admin')
  await page.waitForFunction(
    () => !document.querySelector('a[href="/admin/settings/integrations"]'),
  )
  assert.equal((await context.request.get(origin + '/api/admin/integrations')).status(), 403)
  const admin = await browser.newContext()
  const adminPage = await admin.newPage()
  await login(adminPage, 'admin501@example.test')
  assert.equal(await adminPage.locator('a[href="/admin/settings/integrations"]').count(), 0)
  const guest = await browser.newContext()
  assert.equal((await guest.request.get(origin + '/api/admin/integrations')).status(), 401)
  assert.deepEqual(errors, [])
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify(
      {
        passed: true,
        screenshots,
        checks: [
          'global success/error/info without inline banners',
          '422 inline validation retained',
          '500/403/network failure never succeeds',
          'post-mutation refresh failure reports only error and can retry read',
          'active-only policy excludes revoked and expired metadata',
          'pending duplicate mutation blocked',
          'clipboard failure/success and repeat deduplication',
          'revoke cancel/failure/delayed success/401/reload/stale list',
          'webhook create/filter/edit/toggle/test/history/retry/rotate',
          'named irreversible deletion warning',
          'delete cancel/failure preserves selected history',
          'delayed DELETE and stale history cannot resurrect',
          'RU/EN light/dark/system desktop/320px toast bounds',
          'single return link and personal-settings integration in desktop/mobile/project sidebars',
          'workspace role switch/admin/guest denial',
        ],
        errors,
      },
      null,
      2,
    ) + '\n',
  )
  console.log('PASS: Milestone 5.0.2 UI and browser regression', screenshots)
} catch (error) {
  console.error(backendLog.slice(-1500))
  throw error
} finally {
  await browser?.close()
  backend?.kill()
  await vite.close()
  await rm(scratch, { recursive: true, force: true })
}
