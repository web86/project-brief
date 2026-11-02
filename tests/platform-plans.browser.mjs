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
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-platform-plans-'))
const app = path.join(scratch, 'app'),
  output = path.join(root, 'artifacts/milestone-10-0-phase-2/browser')
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
    path.join(root, 'api/tests/platform-plans-fixture.php'),
    path.join(app, 'tests/platform-plans-fixture.php'),
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
  const fixture = JSON.parse(php(['tests/platform-plans-fixture.php']))
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
  assert.equal((await call(guest, '/api/platform/plans')).status(), 401)
  const secondary = await call(guest, '/api/admin/login', 'POST', {
    email: fixture.other,
    password: fixture.password,
  })
  assert.equal(secondary.status(), 200)
  assert.equal(
    (
      await call(guest, '/api/security/challenge', 'POST', { recovery_code: 'test-recovery-code' })
    ).status(),
    200,
  )
  assert.equal((await call(guest, '/api/platform/overview')).status(), 200)
  assert.equal((await call(guest, '/api/platform/plans')).status(), 403)
  assert.equal(
    (await guest.request.get(`http://127.0.0.1:${backendPort}/platform/plans`)).status(),
    403,
  )
  const deniedPage = await guest.newPage()
  await deniedPage.goto(origin + '/platform/plans')
  await deniedPage.waitForURL('**/admin/unavailable?reason=permission')
  await guest.close()
  let created
  for (const language of ['en', 'ru'])
    for (const theme of ['light', 'dark', 'system']) {
      const mobile = theme !== 'light'
      const context = await browser.newContext({
        viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 },
        colorScheme: theme === 'light' ? 'light' : 'dark',
      })
      await context.addInitScript(
        ({ language, theme }) => {
          localStorage.setItem('projectbrief.locale', language)
          localStorage.setItem('projectbrief.theme', theme)
        },
        { language, theme },
      )
      await login(context, fixture)
      const page = await context.newPage()
      page.on('pageerror', (e) => errors.push(String(e)))
      page.on('response', (r) => {
        if (r.status() >= 400 && new URL(r.url()).pathname.startsWith('/api/platform/'))
          console.error('QA response', r.status(), new URL(r.url()).pathname)
      })
      await page.goto(origin + '/platform/plans')
      await page.locator('.plans-card').first().waitFor()
      assert.equal((await page.locator('.plans-card').count()) >= 3, true)
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      )
      await page.goto(origin + '/platform/plans/new/versions/new')
      await page.locator('.plans-editor input[maxlength="64"]').waitFor()
      assert.equal(
        await page.locator('.plans-feature').count(),
        (await (await call(context, '/api/platform/entitlement-definitions')).json()).data.length,
      )
      if (language === 'en' && theme === 'light') {
        const form = page.locator('form.plans-editor').first()
        await form.locator('input[maxlength="64"]').fill('browser-fourth')
        for (const lang of ['ru', 'en', 'tr', 'zh-CN'])
          await form.getByLabel(`Name (${lang})`, { exact: true }).fill('Browser Fourth')
        await form.getByRole('button', { name: 'Save draft', exact: true }).click()
        await page.waitForURL(/\/platform\/plans\/[a-f0-9-]+\/versions\/[a-f0-9-]+$/)
        created = page.url().slice(origin.length)
        await page.getByRole('button', { name: 'Check and preview', exact: true }).click()
        await page.locator('dialog[open]').waitFor()
        await page.getByRole('button', { name: 'Confirm publication', exact: true }).click()
        await page.locator('dialog[open]').waitFor({ state: 'hidden' })
        await page.getByText('Published version: read-only', { exact: true }).waitFor()
        assert.equal(await form.getByLabel('Name (en)', { exact: true }).isDisabled(), true)
        await capture(page, 'en-light-published.png')
        await page.getByLabel('Display order', { exact: true }).fill('7')
        page.once('dialog', (dialog) => dialog.dismiss())
        await page.getByRole('button', { name: 'Create a new draft', exact: true }).click()
        assert.equal(page.url().slice(origin.length), created)
        await page.getByLabel('Display order', { exact: true }).fill('0')
        await page.getByRole('button', { name: 'Create a new draft', exact: true }).click()
        await page.waitForURL(
          new RegExp(
            created.split('/versions/')[0] + '/versions/(?!' + created.split('/versions/')[1] + ')',
          ),
        )
        await page
          .locator('form.plans-editor')
          .first()
          .getByLabel('Name (en)', { exact: true })
          .fill('Unsaved browser revision')
        page.once('dialog', (dialog) => dialog.dismiss())
        await page.getByRole('link', { name: 'Global policies', exact: true }).click()
        assert.equal(page.url().includes('/versions/'), true)
        const url = new URL(page.url())
        const versionUrl =
          '/api/platform/plans/' +
          url.pathname.split('/')[3] +
          '/versions/' +
          url.pathname.split('/')[5]
        const original = await (await call(context, versionUrl)).json()
        const revision = original.data.revision
        const payload = original.data.configuration
        payload.translations.en.name = 'Server revised concurrently'
        const csrf = (await (await call(context, '/api/csrf')).json()).token
        const changed = await context.request.put(origin + versionUrl, {
          data: payload,
          headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
            'If-Match': `"revision:${revision}"`,
            'Idempotency-Key': 'browser-concurrent',
          },
        })
        assert.equal(changed.status(), 200, await changed.text())
        await form.getByRole('button', { name: 'Save draft', exact: true }).click()
        await page.getByRole('alert').waitFor()
        assert.equal(
          await form.getByLabel('Name (en)', { exact: true }).inputValue(),
          'Unsaved browser revision',
        )
        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('link', { name: 'Global policies', exact: true }).click()
        await page.waitForURL('**/platform/plans/policies')
        await page.locator('.plans-card').first().waitFor()
        await page.getByRole('button', { name: 'New policy draft', exact: true }).click()
        await page.getByLabel('Trial duration (days)', { exact: true }).waitFor()
        assert.equal(
          await page.getByLabel('Trial duration (days)', { exact: true }).inputValue(),
          '7',
        )

        php(['artisan', 'cache:clear', '--no-interaction'])
        const mutate = async (url, method, data, revision = '1') => {
          const token = (await (await call(context, '/api/csrf')).json()).token
          const response = await context.request.fetch(origin + url, {
            method,
            data,
            headers: {
              Accept: 'application/json',
              'X-CSRF-TOKEN': token,
              'If-Match': `"revision:${revision}"`,
              'Idempotency-Key': randomBytes(16).toString('hex'),
            },
          })
          assert.ok(response.ok(), `${response.status()} ${await response.text()}`)
          return (await response.json()).data
        }
        const plans = (await (await call(context, '/api/platform/plans')).json()).data
        const published = {}
        for (const code of ['basic', 'studio']) {
          const row = plans.find((p) => p.code === code)
          const path = `/api/platform/plans/${row.id}/versions/${row.versions[0].id}`
          const pv = await mutate(path + '/previews', 'POST', { policy: 'keep_existing' })
          published[code] = await mutate(path + '/publish', 'POST', {
            policy: 'keep_existing',
            previewId: pv.id,
            contentHash: pv.contentHash,
          })
        }
        await page.reload()
        await page.getByRole('button', { name: 'New policy draft', exact: true }).click()
        await page
          .getByRole('combobox', { name: /^Trial: published paid version/ })
          .selectOption(published.studio.id)
        await page
          .getByRole('combobox', { name: /^Fallback: published free version/ })
          .selectOption(published.basic.id)
        await page.getByRole('button', { name: 'Save draft', exact: true }).click()
        await page.getByRole('button', { name: 'Check and preview', exact: true }).click()
        await page.locator('dialog[open]').waitFor()
        await page.getByRole('button', { name: 'Confirm publication', exact: true }).click()
        await page.locator('dialog[open]').waitFor({ state: 'hidden' })
        await page.getByText('Published version: read-only', { exact: true }).waitFor()
        await capture(page, 'en-light-policies.png')
        php(['artisan', 'cache:clear', '--no-interaction'])
        await page.getByRole('link', { name: 'Transition rules', exact: true }).click()
        await page.getByRole('button', { name: 'New rule', exact: true }).click()
        await page
          .getByRole('combobox', { name: /^Source version/ })
          .selectOption(published.studio.id)
        await page
          .getByRole('combobox', { name: /^Target version/ })
          .selectOption(published.basic.id)
        await page.getByRole('button', { name: 'Save rule', exact: true }).click()
        await page.locator('.plans-card').first().waitFor()
        const next = await mutate(
          `/api/platform/plans/${published.studio.planId}/draft`,
          'POST',
          {},
          '2',
        )
        const nextUrl = `/api/platform/plans/${next.planId}/versions/${next.id}`
        const nextPv = await mutate(nextUrl + '/previews', 'POST', { policy: 'keep_existing' })
        await mutate(nextUrl + '/publish', 'POST', {
          policy: 'keep_existing',
          previewId: nextPv.id,
          contentHash: nextPv.contentHash,
        })
        await page.reload()
        await page
          .getByRole('combobox', { name: /^Previous version/ })
          .selectOption(published.studio.id)
        await page.getByRole('combobox', { name: /^New published version/ }).selectOption(next.id)
        await page.getByRole('button', { name: 'Preview rule copies', exact: true }).click()
        await page.locator('dialog[open]').waitFor()
        await page.getByRole('button', { name: 'Confirm copies (disabled)', exact: true }).click()
        await page.locator('dialog[open]').waitFor({ state: 'hidden' })
        await page.waitForFunction(() => document.querySelectorAll('.plans-card').length === 2)
        assert.equal(
          (await (await call(context, '/api/platform/plan-transition-rules')).json()).data.every(
            (r) => !r.enabled,
          ),
          true,
        )
        await capture(page, 'en-light-transitions.png')
      } else {
        await capture(page, `${language}-${theme}-editor.png`)
        assert.equal(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
          true,
        )
      }
      await page.goto(origin + '/platform/plans/transitions')
      await page.locator('form.plans-editor').last().waitFor()
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      )
      results.push({ language, theme, mobile })
      await context.close()
      php(['artisan', 'cache:clear', '--no-interaction'])
    }
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: results.length, scenarios: results, errors }))
} finally {
  await browser?.close()
  await vite?.close()
  backend?.kill()
  await rm(scratch, { recursive: true, force: true })
}
