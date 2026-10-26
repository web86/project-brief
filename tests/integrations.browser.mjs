import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer as createNetServer } from 'node:net'
const portProbe = createNetServer()
await new Promise((resolve) => portProbe.listen(0, '127.0.0.1', resolve))
const backendPort = portProbe.address().port
await new Promise((resolve) => portProbe.close(resolve))
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = new URL('../docs/milestone-5-0/', import.meta.url)
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone50-e2e-'))
const database = path.join(scratch, 'test.sqlite')
await writeFile(database, '')
const origin = 'http://127.0.0.1:5196'
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
    port: 5196,
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
  results = []
let backend,
  browser,
  backendLog = ''
async function nav(page, target) {
  await page.evaluate(async (target) => {
    const { default: router } = await import('/src/router/index.js')
    await router.push(target)
  }, target)
}
async function language(page, lang) {
  await page.evaluate(async (lang) => {
    const { setManualLocale } = await import('/src/i18n/index.js')
    setManualLocale(lang)
  }, lang)
}
async function shot(page, name) {
  if (!name.includes('navigation')) await page.evaluate(() => window.scrollTo(0, 0))
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    true,
    'No horizontal overflow',
  )
  await page.screenshot({ path: new URL(name + '.png', output).pathname })
  results.push(name)
}
async function login(page, email) {
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill(email)
  await page.locator('#admin-password').fill('Local-e2e-password!')
  await page.locator('form [type=submit]').click()
  await page.waitForURL(origin + '/admin')
}
try {
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(run(['tests/milestone50-fixture.php']))
  backend = spawn(
    'php',
    [
      '-S',
      `127.0.0.1:${backendPort}`,
      root + '/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    { cwd: root + '/api/public', env, stdio: ['ignore', 'ignore', 'pipe'] },
  )
  backend.stderr.on('data', (bytes) => {
    backendLog += bytes.toString()
  })
  for (let i = 0; i < 60; i++) {
    try {
      if ((await fetch(`http://127.0.0.1:${backendPort}/api/health`)).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100))
    if (i === 59) throw new Error('Backend failed: ' + backendLog)
  }
  await vite.listen()
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1080 },
    permissions: ['clipboard-read', 'clipboard-write'],
    reducedMotion: 'reduce',
    locale: 'ru-RU',
  })
  const page = await context.newPage()
  page.on('pageerror', (e) => errors.push(e.message))
  await login(page, 'owner50@example.test')
  await page.locator('.admin-sidebar a[href="/admin/settings/integrations"]').click()
  await page.locator('#token-name').waitFor()
  await language(page, 'ru')
  await page.locator('#token-name').fill('CRM · n8n')
  await page
    .locator('#token-expires')
    .fill(new Date(Date.now() + 365 * 86400000).toISOString().slice(0, 10))
  await page.locator('#integration-panel-tokens [type=submit]').click()
  await page.locator('dialog[open] .integration-secret-value').waitFor()
  const secret = await page.locator('.integration-secret-value').textContent()
  assert.match(secret, /^pb_[a-f0-9]{64}$/)
  await page.getByRole('button', { name: 'Копировать', exact: true }).click()
  assert.equal(await page.evaluate(() => navigator.clipboard.readText()), secret)
  await page.getByRole('button', { name: 'Закрыть', exact: true }).click()
  assert.equal(await page.locator('.integration-secret-value').count(), 0)
  assert.equal(
    await page.evaluate((value) => JSON.stringify(localStorage).includes(value), secret),
    false,
  )
  await page.getByRole('heading', { name: 'CRM · n8n' }).waitFor()
  await page.evaluate(async () => {
    const { theme } = await import('/src/services/theme.js')
    theme.select('light')
  })
  await shot(page, 'desktop-tokens-ru-light')
  const apiResponse = await context.request.get(origin + '/api/v1/projects', {
    headers: { Authorization: 'Bearer ' + secret },
  })
  assert.equal(apiResponse.status(), 200)
  await page.getByRole('tab', { name: 'Webhooks', exact: true }).click()
  await page.locator('#hook-name').fill('CRM · approval events')
  await page.locator('#hook-url').fill('https://example.com/projectbrief-webhook')
  await page.locator('#integration-panel-webhooks [type=submit]').click()
  await page.locator('dialog[open] .integration-secret-value').waitFor()
  assert.match(
    await page.locator('.integration-secret-value').textContent(),
    /^whsec_[a-f0-9]{64}$/,
  )
  await page.getByRole('button', { name: 'Закрыть', exact: true }).click()
  await page.getByRole('button', { name: 'Тестовая доставка', exact: true }).click()
  await page
    .getByText('Доставка добавлена в очередь. Её отправит cron-процессор.', { exact: true })
    .waitFor()
  await page.getByRole('button', { name: 'История доставки', exact: true }).click()
  await page.getByRole('heading', { name: 'Проверка webhook', exact: true }).waitFor()
  run(['tests/milestone50-fixture.php', 'fail-delivery'])
  await page.getByRole('button', { name: 'Обновить', exact: true }).click()
  await page.getByRole('button', { name: 'Повторить', exact: true }).click()
  await page.getByText('В очереди · Попытки: 0', { exact: false }).waitFor()
  await language(page, 'en')
  await page.evaluate(async () => {
    const { theme } = await import('/src/services/theme.js')
    theme.select('dark')
  })
  await shot(page, 'desktop-webhooks-en-dark')
  await page.locator('.integration-list').last().scrollIntoViewIfNeeded()
  await page.screenshot({ path: new URL('desktop-deliveries-en-dark.png', output).pathname })
  results.push('desktop-deliveries-en-dark')
  await page.setViewportSize({ width: 320, height: 900 })
  await shot(page, 'mobile-webhooks-en-dark')
  await page.locator('.admin-menu-trigger').click()
  await page.locator('#admin-drawer[open]').waitFor()
  await shot(page, 'mobile-navigation-en-dark')
  await page.locator('#admin-drawer a[href="/admin/settings/integrations"]').click()
  await language(page, 'ru')
  await page.evaluate(async () => {
    const { theme } = await import('/src/services/theme.js')
    theme.select('light')
  })
  await page.getByRole('tab', { name: 'API-ключи', exact: true }).click()
  await shot(page, 'mobile-tokens-ru-light')
  page.once('dialog', (d) => d.accept())
  await page.getByRole('button', { name: 'Отозвать', exact: true }).click()
  await page.getByText('Отозван', { exact: false }).waitFor()
  assert.equal(
    (
      await context.request.get(origin + '/api/v1/projects', {
        headers: { Authorization: 'Bearer ' + secret },
      })
    ).status(),
    401,
  )
  await page.setViewportSize({ width: 1440, height: 1080 })
  await page
    .locator('.admin-sidebar .sidebar-workspace select')
    .selectOption(fixture.otherWorkspace)
  await page.waitForURL(origin + '/admin')
  assert.equal(await page.locator('a[href="/admin/settings/integrations"]').count(), 0)
  await nav(page, '/admin/settings/integrations')
  assert.ok(page.url().includes('/admin/unavailable'))
  assert.equal((await context.request.get(origin + '/api/admin/integrations')).status(), 403)
  const adminContext = await browser.newContext()
  const adminPage = await adminContext.newPage()
  adminPage.on('pageerror', (e) => errors.push(e.message))
  await login(adminPage, 'admin50@example.test')
  assert.equal(await adminPage.locator('a[href="/admin/settings/integrations"]').count(), 0)
  await nav(adminPage, '/admin/settings/integrations')
  assert.ok(adminPage.url().includes('/admin/unavailable'))
  const clientContext = await browser.newContext()
  const clientPage = await clientContext.newPage()
  await clientPage.goto(origin + '/access/' + fixture.token)
  await clientPage.waitForURL(origin + '/project/' + fixture.project)
  assert.equal((await clientContext.request.get(origin + '/api/admin/integrations')).status(), 401)
  assert.equal(await clientPage.locator('a[href="/admin/settings/integrations"]').count(), 0)
  const guest = await browser.newContext()
  assert.equal((await guest.request.get(origin + '/api/admin/integrations')).status(), 401)
  assert.deepEqual(errors, [])
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify(
      {
        passed: true,
        screenshots: results,
        checks: [
          'owner create/copy/revoke',
          'one-time secret cleared and not persisted',
          'webhook create/test/history/manual retry',
          '320px overflow',
          'RU/EN light/dark',
          'mobile navigation',
          'workspace switch clears owner access',
          'admin/client/guest denied',
        ],
        errors,
      },
      null,
      2,
    ) + '\n',
  )
  console.log('PASS: integrations browser smoke and screenshots', results)
} catch (error) {
  console.error(backendLog)
  throw error
} finally {
  await browser?.close()
  backend?.kill()
  await vite.close()
  await rm(scratch, { recursive: true, force: true })
}
