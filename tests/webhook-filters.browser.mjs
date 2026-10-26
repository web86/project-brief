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
const output = new URL('../docs/milestone-5-0-1/', import.meta.url)
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
  page.on('pageerror', (e) => errors.push(e.message))
  await login(page, 'owner501@example.test')
  let initialFailure = true
  await page.route('**/api/admin/integrations', async (route) => {
    if (initialFailure && route.request().method() === 'GET') {
      initialFailure = false
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Synthetic test failure' }),
      })
    } else await route.continue()
  })
  await page.locator('.admin-sidebar a[href="/admin/settings/integrations"]').click()
  await page.locator('.integrations-page [role="alert"]').waitFor()
  await page.locator('.integrations-page > button').click()
  await page.locator('#token-name').waitFor()
  assert.equal(await page.locator('.integrations-page [role=alert]').count(), 0)
  await language(page, 'ru')
  await theme(page, 'light')
  await page.getByRole('tab', { name: 'Webhooks', exact: true }).click()
  const form = page.locator('#integration-panel-webhooks form')
  await page.locator('#hook-name').fill('CRM · комментарии клиентов')
  await page.locator('#hook-url').fill('https://example.com/receiver')
  await form.locator('input[type=radio][value=selected]').check()
  assert.equal(await form.locator('[type=submit]').isDisabled(), true)
  await page.locator('#hook-project-search').fill('несуществующий проект')
  await page.getByText('По вашему запросу проекты не найдены.', { exact: true }).waitFor()
  await page.locator('#hook-project-search').fill('')
  for (const project of fixture.projects.slice(0, 2))
    await form.locator(`input[type=checkbox][value="${project.id}"]`).check()
  await page.getByText('Выбрано: 2', { exact: true }).waitFor()
  await form.locator('input[type=checkbox][value="task.approved"]').uncheck()
  for (const type of ['task.client_comment_created', 'project.client_comment_created'])
    await form.locator(`input[value="${type}"]`).check()
  await shot(page, 'desktop-create-filter-ru-light', page.locator('.integration-project-filter'))
  await form.locator('[type=submit]').click()
  await page.locator('dialog[open]').waitFor()
  await page.getByRole('button', { name: 'Закрыть', exact: true }).click()
  let state = await request(page, '/api/admin/integrations')
  const hook = state.webhooks[0]
  assert.equal(hook.projectFilter, 'selected')
  assert.equal(hook.projectIds.length, 2)
  // Real client sessions submit through the shared task/general endpoints, including an excluded project.
  for (const [index, project] of fixture.projects.entries()) {
    const client = await browser.newContext()
    const clientPage = await client.newPage()
    await clientPage.goto(origin + '/access/' + project.token)
    await clientPage.waitForURL(origin + '/project/' + project.id)
    for (const url of [
      '/api/client/tasks/' + project.task + '/comments',
      '/api/client/project/' + project.id + '/discussions',
    ]) {
      const submissionId = randomBytes(16)
        .toString('hex')
        .replace(/(.{8})(.{4})(.{4})(.{4})(.{12})/, '$1-$2-$3-$4-$5')
      for (let i = 0; i < 2; i++)
        await request(clientPage, url, {
          method: 'POST',
          body: { text: 'Private comment text omitted from webhook', submissionId },
        })
    }
    assert.equal((await client.request.get(origin + '/api/admin/integrations')).status(), 401)
    await client.close()
  }
  assert.equal(JSON.parse(run(['tests/milestone501-fixture.php', 'stats'])).events, 4)
  await page.getByRole('button', { name: 'История доставки', exact: true }).click()
  await page
    .getByRole('heading', { name: 'Клиент прокомментировал задачу', exact: true })
    .first()
    .waitFor()
  run(['tests/milestone501-fixture.php', 'process'])
  await page.getByRole('button', { name: 'Обновить', exact: true }).click()
  await page.getByText('Доставлено · Попытки: 1', { exact: false }).first().waitFor()
  await language(page, 'en')
  await theme(page, 'dark')
  await shot(page, 'desktop-comment-deliveries-en-dark', page.locator('.integration-list').last())
  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  for (const p of fixture.projects.slice(0, 2))
    assert.equal(await form.locator(`input[value="${p.id}"]`).isChecked(), true)
  await page.setViewportSize({ width: 320, height: 900 })
  await shot(page, 'mobile-edit-filter-en-dark', page.locator('.integration-project-filter'))
  await form.locator('input[type=radio][value=all]').check()
  await form.locator('[type=submit]').click()
  await page.getByRole('button', { name: 'Edit', exact: true }).waitFor()
  state = await request(page, '/api/admin/integrations')
  assert.equal(state.webhooks[0].projectFilter, 'all')
  assert.deepEqual(state.webhooks[0].projectIds, [])
  await page.getByRole('button', { name: 'Edit', exact: true }).click()
  await form.locator('input[type=radio][value=selected]').check()
  assert.equal(await form.locator('[type=submit]').isDisabled(), true)
  await form.locator(`input[value="${fixture.projects[2].id}"]`).check()
  await form.locator('[type=submit]').click()
  await page.getByRole('button', { name: 'Test delivery', exact: true }).click()
  await page.getByRole('button', { name: 'Refresh', exact: true }).click()
  await page.getByRole('heading', { name: 'Webhook test', exact: true }).waitFor()
  run(['tests/milestone501-fixture.php', 'process'])
  await page.getByRole('button', { name: 'Refresh', exact: true }).click()
  await language(page, 'ru')
  await theme(page, 'light')
  await shot(page, 'mobile-selected-summary-ru-light', page.locator('.webhook-item').first())
  await page.locator('.admin-menu-trigger').click()
  await page.locator('#admin-drawer[open]').waitFor()
  await shot(page, 'mobile-owner-navigation-ru-light')
  await page.locator('#admin-drawer a[href="/admin/settings/integrations"]').click()
  await page.setViewportSize({ width: 1440, height: 1080 })
  await page
    .locator('.admin-sidebar .sidebar-workspace select')
    .selectOption(fixture.emptyWorkspace)
  await page.waitForURL(origin + '/admin')
  await page.locator('.admin-sidebar a[href="/admin/settings/integrations"]').click()
  await page.locator('#token-name').waitFor()
  await page.getByRole('tab', { name: 'Webhooks', exact: true }).click()
  await page.locator('input[type=radio][value=selected]').check()
  await page.getByText('В этом рабочем пространстве пока нет проектов.', { exact: true }).waitFor()
  assert.equal(await page.locator('#integration-panel-webhooks [type=submit]').isDisabled(), true)
  await page
    .locator('.admin-sidebar .sidebar-workspace select')
    .selectOption(fixture.otherWorkspace)
  await page.waitForURL(origin + '/admin')
  assert.equal(await page.locator('a[href="/admin/settings/integrations"]').count(), 0)
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
          'selected empty blocked',
          'search and multiple selection',
          'initial load error and retry',
          'empty workspace blocks selected save',
          'explicit new event opt-in',
          'edit and switch all/selected',
          'client task/general submission replay',
          'excluded third project has no event/delivery',
          'signed fake deliveries displayed',
          'test bypasses selected',
          'RU/EN light/dark desktop/320px',
          'owner/admin/client/guest and workspace switch',
        ],
        errors,
      },
      null,
      2,
    ) + '\n',
  )
  console.log('PASS: Milestone 5.0.1 browser smoke', screenshots)
} catch (error) {
  console.error(backendLog.slice(-1500))
  throw error
} finally {
  await browser?.close()
  backend?.kill()
  await vite.close()
  await rm(scratch, { recursive: true, force: true })
}
