import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-platform-'))
const app = path.join(scratch, 'app'),
  output = path.join(root, 'artifacts/milestone-9-0/browser')
await mkdir(app)
await mkdir(output, { recursive: true })
async function port() {
  const server = net.createServer()
  await new Promise((r) => server.listen(0, '127.0.0.1', r))
  const value = server.address().port
  await new Promise((r) => server.close(r))
  return value
}
const backendPort = await port(),
  webPort = await port(),
  origin = `http://127.0.0.1:${webPort}`
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_URL: origin,
  FRONTEND_URL: origin,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(scratch, 'test.sqlite'),
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'platform_qa',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'file',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'null',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args) {
  const result = spawnSync('php', args, { cwd: app, env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
let backend,
  vite,
  browser,
  counter = 0
const errors = [],
  results = []
async function call(context, url, method = 'GET', data) {
  let headers = { Accept: 'application/json' }
  if (method !== 'GET')
    headers['X-CSRF-TOKEN'] = (await (await context.request.get(origin + '/api/csrf')).json()).token
  return context.request.fetch(origin + url, { method, data, headers })
}
async function capture(page, name) {
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.locator('.loading-overlay').waitFor({ state: 'hidden' })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(220)
  await page.screenshot({ path: path.join(output, name), fullPage: true })
}
async function login(context, fixture, owner = false) {
  const response = await call(context, '/api/admin/login', 'POST', {
    email: owner ? fixture.owner : fixture.admin,
    password: fixture.password,
  })
  assert.equal(response.status(), 200, await response.text())
  const challenge = await call(context, '/api/security/challenge', 'POST', {
    recovery_code: owner ? 'test-recovery-code' : `qa-recovery-${++counter}`,
  })
  assert.equal(challenge.status(), 200, await challenge.text())
}
try {
  for (const item of [
    'app',
    'config',
    'lang',
    'resources',
    'routes',
    'database',
    'bootstrap',
    'public',
    'artisan',
    'composer.json',
    'composer.lock',
  ])
    await cp(path.join(root, 'api', item), path.join(app, item), {
      recursive: true,
      filter: (f) => !/(?:\.sqlite[^/]*|\/cache\/[^/]+)$/.test(f),
    })
  await cp(path.join(root, 'api/vendor'), path.join(app, 'vendor'), { recursive: true })
  await mkdir(path.join(app, 'tests'))
  await cp(
    path.join(root, 'api/tests/platform-fixture.php'),
    path.join(app, 'tests/platform-fixture.php'),
  )
  for (const dir of [
    'bootstrap/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
  ])
    await mkdir(path.join(app, dir), { recursive: true })
  await writeFile(path.join(app, '.env'), '')
  await writeFile(env.DB_DATABASE, '')
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/platform-fixture.php']))
  backend = spawn(
    'php',
    ['artisan', 'serve', '--host=127.0.0.1', `--port=${backendPort}`, '--no-reload'],
    { cwd: app, env, stdio: 'ignore' },
  )
  vite = await createServer({
    root,
    mode: 'test',
    define: {
      'import.meta.env.VITE_DATA_SOURCE': JSON.stringify('api'),
      'import.meta.env.VITE_API_BASE_URL': JSON.stringify(''),
    },
    server: {
      host: '127.0.0.1',
      port: webPort,
      strictPort: true,
      proxy: {
        '/api': `http://127.0.0.1:${backendPort}`,
        '/access': `http://127.0.0.1:${backendPort}`,
      },
    },
  })
  await vite.listen()
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`http://127.0.0.1:${backendPort}/api/health`)).ok) break
    } catch {}
    await new Promise((r) => setTimeout(r, 100))
  }
  browser = await chromium.launch({ headless: true })
  const guest = await browser.newContext()
  assert.equal((await call(guest, '/api/platform/overview')).status(), 401)
  await login(guest, fixture, true)
  assert.equal((await call(guest, '/api/platform/audit')).status(), 403)
  const csrfDenied = await guest.request.post(origin + '/api/admin/logout', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': 'invalid-qa-token' },
  })
  assert.equal(csrfDenied.status(), 419)
  await guest.close()
  for (const locale of ['ru', 'en', 'tr', 'zh-CN'])
    for (const theme of ['light', 'dark']) {
      const mobile = theme === 'dark'
      const context = await browser.newContext({
        viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 },
        colorScheme: theme,
      })
      await context.addInitScript(
        ({ locale, theme }) => {
          localStorage.setItem('projectbrief.locale', locale)
          localStorage.setItem('projectbrief.theme', theme)
        },
        { locale, theme },
      )
      await login(context, fixture)
      const page = await context.newPage()
      page.on('pageerror', (e) => errors.push(String(e)))
      await page.goto(origin + '/platform')
      await page.locator('.platform-stats').waitFor()
      if (locale === 'en' && theme === 'light') await capture(page, 'en-light-overview.png')
      await page.locator('.platform-tabs button').nth(1).click()
      await page.locator('.platform-table tbody tr').first().waitFor()
      assert.equal(await page.locator('.platform-table tbody tr').count(), 25)
      await page.locator('.platform-pagination button').last().click()
      await page.waitForFunction(() =>
        document.querySelector('.platform-pagination span')?.textContent.includes('2 /'),
      )
      await page.locator('#platform-search').fill('North workspace')
      await page.locator('.platform-controls form').evaluate((form) => form.requestSubmit())
      await page.waitForFunction(
        () => document.querySelectorAll('.platform-table tbody tr').length === 1,
      )
      await page.locator('.platform-table tbody button').click()
      await page.locator('#workspace-details').waitFor()
      if (locale === 'en' && theme === 'light') await capture(page, 'en-light-detail.png')
      assert.ok(
        !(await page.locator('.platform-page').innerText()).includes('PRIVATE PROJECT BODY'),
      )
      await page.locator('.platform-tabs button').nth(2).click()
      await page.locator('.platform-table code').waitFor()
      assert.ok(
        (await page.locator('.platform-table').innerText()).includes('/api/v1/projects/{uuid}'),
      )
      assert.ok(!(await page.locator('.platform-page').innerText()).includes('SHOULD-NOT-APPEAR'))
      await page.evaluate(() => document.fonts.ready)
      await page.waitForTimeout(200)
      assert.ok(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
        `${locale}/${theme}: horizontal page overflow`,
      )
      await capture(page, `${locale}-${theme}-usage.png`)
      await page.locator('.platform-tabs button').nth(3).click()
      await page.locator('.platform-table tbody tr').first().waitFor()
      results.push({ locale, theme, viewport: mobile ? 'mobile' : 'desktop', passed: true })
      if (locale === 'en' && theme === 'light') {
        php(['tests/platform-fixture.php', 'expire'])
        assert.equal((await call(context, '/api/platform/overview')).status(), 423)
        // A stale proof invokes the existing shared confirmation dialog for GET diagnostics.
        await page.locator('.platform-tabs button').nth(0).click()
        await page
          .locator('.security-step-up-dialog, .security-step-up, dialog[open]')
          .first()
          .waitFor()
        results.push({ expiredProofDialog: true })
      }
      await context.close()
      // Existing login throttle is deliberately preserved: reset only disposable QA cache.
      php(['artisan', 'cache:clear', '--no-interaction'])
    }
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify({ results, errors }, null, 2) + '\n',
  )
  console.log(JSON.stringify({ passed: results.length, results, errors }))
} finally {
  await browser?.close()
  await vite?.close()
  backend?.kill()
  await rm(scratch, { recursive: true, force: true })
}
