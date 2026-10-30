import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, cp, rm, readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = fileURLToPath(new URL('..', import.meta.url))
const baseline = false
const mysql = !!process.env.PROVISIONING_MYSQL_PORT
const output = path.join(root, 'artifacts/milestone-8-5', mysql ? 'mysql' : 'sqlite')
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-')),
  app = path.join(scratch, 'app')
await mkdir(app)
await mkdir(output, { recursive: true })
let backend,
  frontend,
  browser,
  loginCounter = 0
const results = [],
  errors = [],
  requests = []
async function port() {
  const s = net.createServer()
  await new Promise((r) => s.listen(0, '127.0.0.1', r))
  const p = s.address().port
  await new Promise((r) => s.close(r))
  return p
}
const apiPort = await port(),
  webPort = await port(),
  origin = `http://127.0.0.1:${webPort}`
const env = {
  PATH: process.env.PATH,
  TMPDIR: scratch,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: mysql ? 'mysql' : 'sqlite',
  DB_DATABASE: mysql ? 'projectbrief_m80_qa' : path.join(scratch, 'test.sqlite'),
  ...(mysql
    ? {
        DB_HOST: '127.0.0.1',
        DB_PORT: process.env.PROVISIONING_MYSQL_PORT,
        DB_USERNAME: 'briefqa',
        DB_PASSWORD: 'Disposable-m80-only',
      }
    : {}),
  DB_URL: '',
  M84_QA_METRICS: path.join(scratch, 'sql-measurements.jsonl'),
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'live_sync_session',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'array',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'null',
  QUEUE_CONNECTION: 'sync',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args) {
  const r = spawnSync('php', args, { cwd: app, env, encoding: 'utf8' })
  assert.equal(r.status, 0, r.stdout + r.stderr)
  return r.stdout
}
async function call(context, url, method = 'GET', body) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET') {
    const csrf = await (await context.request.get(origin + '/api/csrf')).json()
    headers['X-CSRF-TOKEN'] = csrf.token
  }
  const response = await context.request.fetch(origin + url, {
    method,
    headers,
    ...(body ? { data: body } : {}),
  })
  assert.ok(response.ok(), url + ' ' + response.status() + ' ' + (await response.text()))
  return response.json()
}
async function context(width = 1440, mode = 'light', locale = 'en') {
  const c = await browser.newContext({
    viewport: { width, height: 980 },
    reducedMotion: 'reduce',
    colorScheme: mode,
    permissions: ['notifications', 'clipboard-read', 'clipboard-write'],
  })
  await c.addInitScript(
    ({ mode, locale }) => {
      try {
        if (!localStorage.getItem('projectbrief.theme'))
          localStorage.setItem('projectbrief.theme', mode)
        if (!localStorage.getItem('projectbrief.locale'))
          localStorage.setItem('projectbrief.locale', locale)
      } catch {}
    },
    { mode, locale },
  )
  c.on('page', (p) => {
    p.on('pageerror', (e) => errors.push(String(e)))
    p.on('request', (r) => {
      if (new URL(r.url()).pathname.startsWith('/api/'))
        requests.push({ url: new URL(r.url()).pathname, method: r.method() })
    })
  })
  return c
}
async function login(c, actor = 'owner', clockTime) {
  const p = await c.newPage()
  if (clockTime !== undefined) {
    await p.clock.install({ time: clockTime })
    await p.clock.pauseAt(clockTime + 1000)
    p.qaPaused = true
  }
  await p.goto(origin + '/admin/login')
  await p.locator('#admin-email').fill(actor + '@example.test')
  await p.locator('#admin-password').fill('Local-test-password!')
  await p.locator('form button[type=submit]').click()
  await p.waitForURL('**/admin/security')
  await call(c, '/api/security/challenge', 'POST', {
    recovery_code: 'Live-browser-code-' + loginCounter++,
  })
  await p.goto(origin + '/admin')
  await p.locator('.admin-project-card').first().waitFor()
  return p
}
async function settled(page) {
  if (page.qaPaused) {
    for (let n = 0; n < 60 && (await page.locator('#bootstrap-splash').count()); n++) {
      await page.clock.runFor(100)
      await new Promise((resolve) => setTimeout(resolve, 15))
    }
  }
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.waitForTimeout(220)
  await page.evaluate(() => document.fonts.ready)
}
async function capture(page, name) {
  await settled(page)
  // Full-page captures must start at the top: focused lower fields otherwise
  // displace fixed headers and the off-screen accessibility link in Chromium.
  await page.evaluate(() => {
    document.activeElement?.blur()
    window.scrollTo(0, 0)
  })
  await page.waitForTimeout(150)
  const noOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth <= innerWidth + 1,
  )
  if (!noOverflow && baseline) results.push({ test: 'baseline-known-overflow', screenshot: name })
  else assert.ok(noOverflow, name + ' overflow')
  await page.screenshot({
    path: path.join(output, name + '.png'),
    fullPage: !name.endsWith('-menu'),
  })
}
try {
  for (const entry of [
    'app',
    'config',
    'lang',
    'resources',
    'routes',
    'vendor',
    'database',
    'bootstrap',
    'public',
    'artisan',
    'composer.json',
    'composer.lock',
  ])
    await cp(path.join(root, 'api', entry), path.join(app, entry), {
      recursive: true,
      filter: (f) => !/(?:^|\/)(?:\.DS_Store|cache\/[^/]+|[^/]*\.sqlite[^/]*)$/.test(f),
    })
  await mkdir(path.join(app, 'tests'))
  await cp(
    path.join(root, 'api/tests/live-sync-fixture.php'),
    path.join(app, 'tests/live-sync-fixture.php'),
  )
  for (const dir of [
    'bootstrap/cache',
    'storage/framework/views',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/logs',
  ])
    await mkdir(path.join(app, dir), { recursive: true })
  await writeFile(path.join(app, '.env'), '')
  if (!mysql) await writeFile(env.DB_DATABASE, '')
  else php(['artisan', 'db:wipe', '--force', '--no-interaction'])
  await cp(
    path.join(root, 'api/tests/milestone84-fixture.php'),
    path.join(app, 'tests/milestone84-fixture.php'),
  )
  if (mysql) {
    const file = path.join(app, 'tests/live-sync-fixture.php')
    await writeFile(
      file,
      (await readFile(file, 'utf8')).replace(
        "config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')",
        "! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m80_qa'))",
      ),
    )
  }
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const f = JSON.parse(php(['tests/live-sync-fixture.php']))
  backend = spawn(
    'php',
    [
      '-d',
      'memory_limit=128M',
      '-d',
      'opcache.enable=0',
      '-S',
      `127.0.0.1:${apiPort}`,
      '-t',
      'public',
      'public/index.php',
    ],
    { cwd: app, env, stdio: 'ignore' },
  )
  frontend = await createServer({
    cacheDir: path.join(scratch, 'vite-cache'),
    mode: 'test',
    define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
    plugins: baseline
      ? [
          {
            name: 'baseline-77-ui',
            enforce: 'pre',
            load(id) {
              const relative = path.relative(root, id.split('?')[0])
              if (
                !relative.startsWith('src/') ||
                id.includes('?') ||
                !['.vue', '.js', '.css'].includes(path.extname(relative))
              )
                return
              const r = spawnSync('git', ['show', '4de88df:' + relative], {
                cwd: root,
                encoding: 'utf8',
              })
              if (r.status === 0) return r.stdout
            },
          },
        ]
      : [],
    server: {
      host: '127.0.0.1',
      port: webPort,
      strictPort: true,
      hmr: false,
      proxy: {
        '/branding-assets': { target: `http://127.0.0.1:${apiPort}` },
        '/api': { target: `http://127.0.0.1:${apiPort}` },
        '/access/': { target: `http://127.0.0.1:${apiPort}` },
      },
    },
    logLevel: 'error',
  })
  await frontend.listen()
  browser = await chromium.launch({ headless: true })
  for (let n = 0; n < 100; n++) {
    try {
      if ((await fetch(`http://127.0.0.1:${apiPort}/api/health`)).ok) break
    } catch {}
    await new Promise((r) => setTimeout(r, 50))
  }
  await cp(
    path.join(root, 'api/tests/administrators-fixture.php'),
    path.join(app, 'tests/administrators-fixture.php'),
  )
  const extra = JSON.parse(php(['tests/administrators-fixture.php']))
  const owner = await context(),
    admin = await login(owner)
  const globalRoot = '/api/admin/administrators'
  const teamRoot = `/api/admin/projects/${f.project}/team`
  await admin.goto(origin + '/admin/administrators')
  await admin.locator('.administrator-card').first().waitFor()
  assert.equal(await admin.locator('.administrator-card').count(), 3)
  assert.equal(
    await admin.locator('.administrator-manage').count(),
    3,
    'Owner is not a manageable administrator',
  )
  await admin.locator('#administrator-search').fill('developer@example.test')
  await admin.waitForTimeout(350)
  assert.equal(await admin.locator('.administrator-card').count(), 1)
  await admin.locator('#administrator-search').fill('')
  await admin.waitForTimeout(350)
  const cards = () => admin.locator('.administrator-card')
  await admin.getByRole('button', { name: 'Add administrator', exact: true }).click()
  await admin.locator('#administrator-name').fill('Draft Administrator')
  await admin.locator('#administrator-email').fill('draft@example.test')
  await admin.locator('.administrator-check input').uncheck()
  await admin.locator('.invitation-form button[type=submit]').click()
  await admin.locator('.administrator-card').filter({ hasText: 'draft@example.test' }).waitFor()
  assert.equal((await call(owner, globalRoot)).invitations[0].projects.length, 0)
  await admin.getByRole('button', { name: 'Add administrator', exact: true }).click()
  await admin.locator('#administrator-name').fill('Duplicate')
  await admin.locator('#administrator-email').fill('DRAFT@example.test')
  await admin.locator('.invitation-form button[type=submit]').click()
  await admin.locator('.administrator-highlight').waitFor()
  assert.equal((await call(owner, globalRoot)).invitations.length, 1)
  await admin.getByRole('button', { name: 'Add administrator', exact: true }).click()
  let developer = (await call(owner, globalRoot)).members.find(
    (m) => m.user.email === 'developer@example.test',
  )
  await cards().filter({ hasText: developer.user.email }).locator('.administrator-manage').click()
  let editor = cards().filter({ hasText: developer.user.email }).locator('form')
  for (const id of [f.project, f.reviewed])
    await editor.locator(`.project-choices input[value="${id}"]`).check()
  await editor.locator('select').selectOption(f.reviewed)
  await editor.locator('.member-permissions [role=switch]').nth(1).check()
  await editor.locator('button[type=submit]').click()
  await editor.waitFor({ state: 'hidden' })
  developer = (await call(owner, globalRoot)).members.find((m) => m.id === developer.id)
  assert.deepEqual([...developer.projects].sort(), [f.project, f.reviewed].sort())
  assert.equal(developer.projectPermissions[f.reviewed].manage_clients, true)
  await admin.goto(origin + `/admin/projects/${f.project}/team`)
  await admin.locator('.project-team-member').waitFor()
  assert.equal(await admin.locator('.project-team-member').count(), 1)
  assert.equal(await admin.locator('.member-permissions').count(), 0)
  admin.once('dialog', (d) => d.accept())
  await admin.locator('.project-team-member button').click()
  await admin.locator('.project-team-member').waitFor({ state: 'hidden' })
  let after = (await call(owner, globalRoot)).members.find((m) => m.id === developer.id)
  assert.deepEqual(after.projects, [f.reviewed])
  assert.equal(after.projectPermissions[f.reviewed].manage_clients, true)
  assert.equal(after.status, 'active')
  await admin.getByRole('button', { name: 'Add to team', exact: true }).click()
  for (const email of ['developer@example.test', 'alex@example.test'])
    await admin
      .locator('.project-choices label')
      .filter({ hasText: email })
      .locator('input')
      .check()
  await admin.locator('form button[type=submit]').click()
  await admin.waitForFunction(() => document.querySelectorAll('.project-team-member').length === 2)
  assert.equal(
    (await call(owner, globalRoot)).members.find((m) => m.id === developer.id).projectPermissions[
      f.project
    ].manage_clients,
    false,
  )
  results.push(
    'Global administrators: debounce search, draft without project, duplicate invitation, independent permissions; team multi-assignment and narrow removal',
  )
  await admin.goto(origin + '/admin/settings/integrations')
  await admin.getByRole('tab', { name: 'SSO', exact: true }).click()
  for (const [name, hostname] of [
    ['Portal A', 'https://portal-a.example.test'],
    ['Portal B', 'https://portal-b.example.test'],
  ]) {
    await admin.getByRole('button', { name: 'Add source', exact: true }).click()
    await admin.locator('#sso-new-name').fill(name)
    await admin.locator('#sso-new-origin').fill(hostname)
    await admin
      .locator('form')
      .filter({ has: admin.locator('#sso-new-name') })
      .locator('button[type=submit]')
      .click()
    await admin.locator('.sso-source-card').filter({ hasText: name }).waitFor()
  }
  assert.equal(
    (await call(owner, '/api/admin/integrations')).sources.every((s) => !s.ssoEnabled),
    true,
  )
  await admin.locator('.sso-source-card').filter({ hasText: 'Portal A' }).locator('button').click()
  await admin
    .locator('.sso-settings')
    .getByRole('button', { name: 'Create API key', exact: true })
    .click()
  assert.equal(
    await admin.locator('#token-source').inputValue(),
    (await call(owner, '/api/admin/integrations')).sources[0].id,
  )
  assert.equal(await admin.locator('#create-tokens input[value="clients:sso"]').isChecked(), true)
  const createResponse = admin.waitForResponse(
    (r) => r.url().endsWith('/tokens') && r.request().method() === 'POST',
  )
  await admin.locator('#create-tokens button[type=submit]').click()
  const token = await (await createResponse).json()
  await admin.locator('.integration-secret').waitFor({ state: 'visible' })
  assert.match(await admin.locator('.integration-secret-value').innerText(), /^pb_[a-f0-9]{64}$/)
  await admin.locator('.integration-secret .button.secondary').click()
  assert.equal(await admin.locator('.integration-secret-value').count(), 0)
  await admin.getByRole('tab', { name: 'SSO', exact: true }).click()
  await admin.locator('#sso-enabled').check()
  await admin.locator('#sso-project-search').fill('Completed')
  await admin.locator(`.sso-projects input[value="${f.reviewed}"]`).check()
  await admin.locator('.sso-settings button[type=submit]').click()
  await admin.locator('.toast-success').first().waitFor()
  let integrations = await call(owner, '/api/admin/integrations')
  assert.equal(integrations.sources[0].ssoEnabled, true)
  assert.deepEqual(integrations.sources[0].ssoProjectIds, [f.reviewed])
  assert.deepEqual(integrations.sources[1].ssoProjectIds, [])
  assert.equal(JSON.stringify(integrations).includes(token.secret), false)
  results.push(
    'SSO source creation starts disabled; clients:sso key form is preselected; secret shown once; manual projects are isolated per source',
  )
  for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
    php([
      'tests/live-sync-fixture.php',
      'configure',
      JSON.stringify({ name: 'Northline Lab', locale }),
    ])
    for (const width of [1440, 390])
      for (const theme of ['light', 'dark']) {
        await admin.setViewportSize({ width, height: 980 })
        await admin.evaluate(
          ({ locale, theme }) => {
            localStorage.setItem('projectbrief.locale', locale)
            localStorage.setItem('projectbrief.theme', theme)
          },
          { locale, theme },
        )
        await admin.goto(origin + '/admin/administrators')
        await admin.locator('.administrator-card').first().waitFor()
        await capture(admin, `administrators-${locale}-${width}-${theme}`)
        await admin
          .locator('.administrator-card')
          .filter({ hasText: developer.user.email })
          .locator('.administrator-manage')
          .click()
        await capture(admin, `administrator-manage-${locale}-${width}-${theme}`)
        await admin.goto(origin + `/admin/projects/${f.project}/team`)
        await admin.locator('.project-team-member').first().waitFor()
        await capture(admin, `team-${locale}-${width}-${theme}`)
        await admin.goto(origin + '/admin/settings/integrations')
        await admin.locator('#integration-tab-sso').click()
        await admin
          .locator('.sso-source-card')
          .filter({ hasText: 'Portal A' })
          .locator('button')
          .click()
        await capture(admin, `sso-${locale}-${width}-${theme}`)
      }
  }
  results.push(
    'RU EN TR ZH-CN × desktop mobile × light dark: administrators, permissions, team, SSO; no horizontal overflow',
  )
  php([
    'tests/live-sync-fixture.php',
    'configure',
    JSON.stringify({ name: 'Northline Lab', locale: 'en' }),
  ])
  await admin.evaluate(() => localStorage.setItem('projectbrief.locale', 'en'))
  await admin.setViewportSize({ width: 390, height: 980 })
  await admin.goto(origin + '/admin/administrators')
  await admin.locator('.administrator-card').first().waitFor()
  await admin.locator('.admin-menu-trigger').click()
  await admin.locator('#admin-drawer a[href="/admin/administrators"]').click()
  await admin.locator('#admin-drawer').waitFor({ state: 'hidden' })
  await admin.setViewportSize({ width: 1440, height: 980 })
  await admin.goto(origin + '/admin/administrators')
  const sam = cards().filter({ hasText: 'sam@example.test' })
  await sam.locator('.administrator-manage').click()
  admin.once('dialog', (d) => d.accept())
  await sam.getByRole('button', { name: 'Deactivate administrator', exact: true }).click()
  await admin.locator('#administrator-status').selectOption('inactive')
  await sam.getByRole('button', { name: 'Invite again', exact: true }).waitFor()
  assert.equal(
    (await call(owner, globalRoot)).members.find((m) => m.user.email === 'sam@example.test').status,
    'inactive',
  )
  await admin.locator('.admin-sidebar .sidebar-workspace select').selectOption(extra.otherWorkspace)
  await admin.waitForURL('**/admin')
  assert.equal(await admin.locator('a[href="/admin/administrators"]').count(), 0)
  await admin.goto(origin + '/admin/administrators')
  await admin.waitForURL('**/admin/unavailable?reason=permission')
  results.push(
    'Mobile drawer closes on navigation; deactivation is explicit; workspace switch removes owner navigation and blocks global page',
  )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify({ results, errors, requests }, null, 2) + '\n',
  )
  await rm(path.join(output, 'failure.json'), { force: true })
  console.log(JSON.stringify({ passed: results.length, errors: errors.length }))
} catch (error) {
  await writeFile(
    path.join(output, 'failure.json'),
    JSON.stringify({ error: String(error), errors, requests }, null, 2),
  )
  throw error
} finally {
  await browser?.close()
  await frontend?.close()
  backend?.kill()
  await rm(scratch, { recursive: true, force: true })
}
