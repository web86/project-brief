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
const output = new URL('../docs/milestone-5-0-3/', import.meta.url)
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
  await login(page, 'owner501@example.test')
  await page.locator('.admin-sidebar a[href="/admin/settings/integrations"]').click()
  await page.locator('#token-name').waitFor()
  const scopes = page.locator('#integration-panel-tokens fieldset')
  assert.equal(await scopes.locator('input').count(), 7)
  for (const value of ['comments:read', 'attachments:read']) {
    assert.equal(await scopes.locator(`input[value="${value}"]`).isChecked(), false)
    await scopes.locator(`input[value="${value}"]`).check()
  }
  await page.locator('#integration-panel-tokens [role=note]').waitFor()
  await page.locator('#token-name').fill('Trusted CRM · Read')
  await page.locator('#integration-panel-tokens [type=submit]').click()
  await page.locator('dialog[open]').waitFor()
  const secret = await page.locator('.integration-secret-value').innerText()
  await page.locator('dialog[open] .button.secondary').click()
  const client = await browser.newContext()
  const clientPage = await client.newPage()
  await clientPage.goto(origin + '/access/' + fixture.projects[0].token)
  await clientPage.waitForURL(origin + '/project/' + fixture.projects[0].id)
  await clientPage.evaluate(async (project) => {
    const { api } = await import('/src/api/client.js')
    for (const endpoint of [
      '/api/client/tasks/' + project.task + '/comments',
      '/api/client/project/' + project.id + '/discussions',
    ]) {
      const form = new FormData()
      form.append('text', 'Synthetic CRM comment')
      form.append('submissionId', crypto.randomUUID())
      form.append(
        'attachments[]',
        new File(['Synthetic private file\n'], 'report.txt', { type: 'text/plain' }),
      )
      await api.request(endpoint, { method: 'POST', body: form })
    }
  }, fixture.projects[0])
  const headers = { Authorization: 'Bearer ' + secret }
  const base = origin + '/api/v1/projects/' + fixture.projects[0].id + '/comments'
  const listed = await context.request.get(base, { headers })
  assert.equal(listed.status(), 200)
  const data = (await listed.json()).data
  assert.equal(data.length, 2)
  for (const comment of data) {
    assert.equal(comment.text, 'Synthetic CRM comment')
    assert.equal(comment.attachments.length, 1)
    assert.deepEqual(Object.keys(comment.attachments[0]).sort(), ['id', 'mimeType', 'name', 'size'])
    const direct = await context.request.get(base + '/' + comment.id, { headers })
    assert.equal((await direct.json()).data.id, comment.id)
    const file = await context.request.get(
      origin + '/api/v1/attachments/' + comment.attachments[0].id + '/download',
      { headers },
    )
    assert.equal(file.status(), 200)
    assert.match(file.headers()['content-disposition'], /^attachment;/)
    assert.equal((await file.body()).toString(), 'Synthetic private file\n')
    assert.equal(
      (
        await client.request.get(
          origin + '/api/v1/attachments/' + comment.attachments[0].id + '/download',
        )
      ).status(),
      401,
    )
  }
  await page.evaluate(async () => (await import('/src/services/toast.js')).toast.clear())
  for (const locale of ['ru', 'en'])
    for (const mode of ['light', 'dark', 'system']) {
      await page.emulateMedia({ colorScheme: mode === 'system' ? 'dark' : 'light' })
      await language(page, locale)
      await theme(page, mode)
      for (const width of [1440, 320]) {
        await page.setViewportSize({ width, height: width === 320 ? 1100 : 1080 })
        await shot(page, `${width === 320 ? 'mobile' : 'desktop'}-scopes-${locale}-${mode}`, scopes)
      }
    }
  assert.deepEqual(errors, [])
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify(
      {
        passed: true,
        screenshots,
        checks: [
          'seven scope choices, new read scopes opt-in',
          'RU/EN trusted CRM privacy notice',
          'actual UI token creation with read scopes',
          'real client general/task comment with private file',
          'bearer comment list/direct lookup and safe metadata',
          'authenticated file bytes without public URL',
          'browser client session cannot download v1 without bearer',
          'RU/EN light/dark/system desktop/320px no overflow',
        ],
        errors,
      },
      null,
      2,
    ) + '\n',
  )
  console.log('PASS: Milestone 5.0.3 read scopes/comments/files browser regression', screenshots)
} catch (error) {
  console.error(backendLog.slice(-1500))
  throw error
} finally {
  await browser?.close()
  backend?.kill()
  await vite.close()
  await rm(scratch, { recursive: true, force: true })
}
