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
const mysql = false // Browser fixtures are always disposable SQLite; MariaDB is tested separately.
const output = path.join(
  root,
  process.env.M93_EVIDENCE_DIRECTORY || 'artifacts/milestone-9-2',
  mysql ? 'mysql' : 'sqlite',
)
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-')),
  app = path.join(scratch, 'app')
await mkdir(app)
await mkdir(output, { recursive: true })
let backend,
  frontend,
  browser,
  smtp,
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
const smtpPort = await port()
const mails = []
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
  MAIL_MAILER: 'smtp',
  MAIL_HOST: '127.0.0.1',
  MAIL_PORT: String(smtpPort),
  MAIL_USERNAME: '',
  MAIL_PASSWORD: '',
  MAIL_SCHEME: 'smtp',
  MAIL_FROM_ADDRESS: 'system@example.test',
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
  smtp = net.createServer((socket) => {
    socket.write('220 localhost ESMTP\r\n')
    let buffer = '',
      data = false,
      message = ''
    socket.on('data', (chunk) => {
      buffer += chunk.toString()
      while (buffer.includes('\r\n')) {
        const n = buffer.indexOf('\r\n'),
          line = buffer.slice(0, n)
        buffer = buffer.slice(n + 2)
        if (data) {
          if (line === '.') {
            mails.push(message)
            message = ''
            data = false
            socket.write('250 queued\r\n')
          } else message += line + '\n'
          continue
        }
        if (/^EHLO|^HELO/.test(line)) socket.write('250-localhost\r\n250 OK\r\n')
        else if (/^DATA/.test(line)) {
          data = true
          socket.write('354 send message\r\n')
        } else if (/^QUIT/.test(line)) socket.end('221 bye\r\n')
        else socket.write('250 OK\r\n')
      }
    })
  })
  await new Promise((resolve) => smtp.listen(smtpPort, '127.0.0.1', resolve))
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
    path.join(root, 'api/tests/milestone92-fixture.php'),
    path.join(app, 'tests/milestone92-fixture.php'),
  )
  php(['tests/milestone92-fixture.php'])
  const owner = await context(),
    admin = await login(owner)
  const globalRoot = '/api/admin/administrators',
    integrationRoot = '/api/admin/integrations'
  const editor = admin.locator('.administrator-dialog'),
    ssoEditor = admin.locator('.sso-source-dialog')
  async function editMember(email) {
    await admin
      .locator('.administrator-card')
      .filter({ hasText: email })
      .getByRole('button', { name: 'Edit', exact: true })
      .click()
    await editor.waitFor({ state: 'visible' })
  }
  async function openAssignments() {
    await editor.locator('.assigned-projects > summary').click()
  }
  async function saveAdministrator() {
    await editor.locator('button[form="administrator-editor"]').click()
    await editor.waitFor({ state: 'hidden' })
  }
  async function saveSource() {
    await ssoEditor.locator('button[form="sso-source-editor"]').click()
    await ssoEditor.waitFor({ state: 'hidden' })
  }
  await admin.goto(origin + '/admin/administrators')
  await admin.locator('.administrator-card').first().waitFor()
  await admin.getByRole('button', { name: 'Add administrator', exact: true }).click()
  await admin.locator('#administrator-name').fill('Invited via modal')
  await admin.locator('#administrator-email').fill('modal@example.test')
  await openAssignments()
  await editor.locator(`input[value="${f.reviewed}"]`).check()
  await editor.locator('.member-creation-permission [role=switch]').check()
  await editor.locator('.project-rights > summary').click()
  await editor.locator('.member-permissions [role=switch]').nth(1).check()
  let failInvitationOnce = true
  await admin.route('**/api/admin/administrators/invitations', async (route) => {
    if (route.request().method() === 'POST' && failInvitationOnce) {
      failInvitationOnce = false
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Validation failed', errors: { email: ['Try again'] } }),
      })
    } else await route.continue()
  })
  await editor.locator('button[form="administrator-editor"]').click()
  await editor.locator('[role=alert]').waitFor()
  assert.equal(
    await editor.locator('[role=alert]').evaluate((el) => el === document.activeElement),
    true,
  )
  assert.equal(await admin.locator('#administrator-name').inputValue(), 'Invited via modal')
  assert.equal(await editor.locator(`input[value="${f.reviewed}"]`).isChecked(), true)
  await saveAdministrator()
  assert.equal(mails.length, 1)
  let pending = (await call(owner, globalRoot)).invitations.find(
    (i) => i.email === 'modal@example.test',
  )
  assert.equal(pending.canCreateProjects, true)
  assert.equal(pending.projectPermissions[f.reviewed].manage_clients, true)
  await editMember('modal@example.test')
  await editor.locator('.administrator-invitations > summary').click()
  await editor.getByRole('button', { name: 'Resend invitation', exact: true }).click()
  await admin.waitForFunction(
    () => !document.querySelector('.administrator-dialog button[disabled]'),
  )
  assert.equal(mails.length, 2)
  await editor.getByRole('button', { name: 'Get invitation link', exact: true }).click()
  const linkDialog = admin.locator('.invitation-link-dialog')
  await linkDialog.waitFor({ state: 'visible' })
  const firstLink = await admin.locator('#administrator-invitation-link').inputValue()
  await linkDialog.getByRole('button', { name: 'Copy', exact: true }).click()
  assert.equal(await admin.evaluate(() => navigator.clipboard.readText()), firstLink)
  await admin.keyboard.press('Escape')
  assert.equal(await admin.locator('#administrator-invitation-link').inputValue(), '')
  await editor.getByRole('button', { name: 'Get invitation link', exact: true }).click()
  await linkDialog.waitFor({ state: 'visible' })
  const link = await admin.locator('#administrator-invitation-link').inputValue()
  assert.notEqual(link, firstLink)
  await linkDialog.getByRole('button', { name: 'Close', exact: true }).click()
  await admin.keyboard.press('Escape')
  const invitee = await context(),
    invitationPage = await invitee.newPage()
  await invitationPage.goto(link)
  await invitationPage.getByRole('button', { name: 'Create account', exact: true }).click()
  await invitationPage.locator('#invited-name').fill('Invited via modal')
  await invitationPage.locator('#invited-password').fill('Modal-password-123!')
  await invitationPage.locator('#invited-confirmation').fill('Modal-password-123!')
  await invitationPage.locator('form button[type=submit]').click()
  await invitationPage.waitForURL('**/admin')
  assert.equal((await call(invitee, '/api/admin/me')).user.canCreateProjects, true)
  await invitee.close()
  await admin.reload()
  await editMember('alex@example.test')
  await admin.locator('#administrator-name').fill('Alex scoped name')
  await openAssignments()
  for (const id of [f.project, f.reviewed]) await editor.locator(`input[value="${id}"]`).check()
  await editor.locator('.project-rights > summary').click()
  await admin.locator('#administrator-permission-project').selectOption(f.reviewed)
  await editor.locator('.member-permissions [role=switch]').nth(1).check()
  await saveAdministrator()
  let alex = (await call(owner, globalRoot)).members.find(
    (m) => m.user.email === 'alex@example.test',
  )
  assert.equal(alex.projectPermissions[f.reviewed].manage_clients, true)
  assert.equal(alex.projectPermissions[f.project].manage_clients, false)
  await editMember('alex@example.test')
  await admin.locator('#administrator-member-status').selectOption('inactive')
  await saveAdministrator()
  assert.equal(
    (await call(owner, globalRoot)).members.find((m) => m.id === alex.id).status,
    'inactive',
  )
  await editMember('sam@example.test')
  admin.once('dialog', (d) => d.dismiss())
  await editor.getByRole('button', { name: 'Delete', exact: true }).click()
  assert.equal(await editor.isVisible(), true)
  admin.once('dialog', (d) => d.accept())
  await editor.getByRole('button', { name: 'Delete', exact: true }).click()
  await editor.waitFor({ state: 'hidden' })
  assert.equal(
    (await call(owner, globalRoot)).members.some((m) => m.user.email === 'sam@example.test'),
    false,
  )
  await admin.getByRole('button', { name: 'Add administrator', exact: true }).click()
  await admin.keyboard.press('Escape')
  await editor.waitFor({ state: 'hidden' })
  await admin.getByRole('button', { name: 'Add administrator', exact: true }).click()
  let box = await editor.boundingBox()
  await admin.mouse.click(box.x - 6, box.y + 20)
  await editor.waitFor({ state: 'hidden' })
  results.push(
    'Administrator modal: create with rights, SMTP send/resend, one-time copy/rotation and browser acceptance, independent permissions/status, final deletion, validation retention, Escape/backdrop',
  )
  await admin.goto(origin + '/admin/settings/integrations')
  await admin.getByRole('tab', { name: 'SSO', exact: true }).click()
  for (const name of ['Portal A', 'Portal B']) {
    await admin.getByRole('button', { name: 'Add source', exact: true }).click()
    await admin.locator('#sso-source-name').fill(name)
    await admin
      .locator('#sso-origin')
      .fill(name === 'Portal A' ? 'https://portal-a.example.test' : 'https://portal-b.example.test')
    await saveSource()
  }
  assert.equal(await admin.locator('.sso-source-card').count(), 2)
  await admin
    .locator('.sso-source-card')
    .filter({ hasText: 'Portal A' })
    .locator('.sso-configure')
    .click()
  await admin.locator('#sso-source-name').fill('Portal A renamed')
  await admin.locator('#sso-origin').fill('https://portal-a.example.test/path')
  await ssoEditor.locator('button[form="sso-source-editor"]').click()
  await ssoEditor.locator('[role=alert]').waitFor()
  assert.equal(
    await ssoEditor.locator('[role=alert]').evaluate((el) => el === document.activeElement),
    true,
  )
  assert.equal(await admin.locator('#sso-source-name').inputValue(), 'Portal A renamed')
  await admin.locator('#sso-origin').fill('https://portal-a.example.test')
  await ssoEditor.locator('.sso-api-key > summary').click()
  const keyResponse = admin.waitForResponse(
    (r) => r.url().endsWith('/tokens') && r.request().method() === 'POST',
  )
  await ssoEditor.getByRole('button', { name: 'Create API key', exact: true }).click()
  const key = await (await keyResponse).json()
  await admin.locator('.integration-secret').waitFor({ state: 'visible' })
  assert.equal(await admin.locator('.integration-secret-value').innerText(), key.secret)
  await admin
    .locator('.integration-secret')
    .getByRole('button', { name: 'Close', exact: true })
    .click()
  assert.equal(await admin.locator('#sso-source-name').inputValue(), 'Portal A renamed')
  await ssoEditor.locator('#sso-enabled input').check()
  await ssoEditor.locator('.sso-projects > summary').click()
  await admin.locator('#sso-project-search').fill('Completed')
  await ssoEditor.locator(`input[value="${f.reviewed}"]`).check()
  await saveSource()
  let integrations = await call(owner, integrationRoot)
  assert.equal(integrations.sources[0].name, 'Portal A renamed')
  assert.equal(integrations.sources[0].ssoEnabled, true)
  assert.deepEqual(integrations.sources[0].ssoProjectIds, [f.reviewed])
  assert.equal(JSON.stringify(integrations).includes(key.secret), false)
  const sourceB = admin.locator('.sso-source-card').filter({ hasText: 'Portal B' })
  admin.once('dialog', (d) => d.dismiss())
  await sourceB.locator('.sso-delete').click()
  assert.equal(await sourceB.count(), 1)
  admin.once('dialog', (d) => d.accept())
  await sourceB.locator('.sso-delete').click()
  await sourceB.waitFor({ state: 'hidden' })
  assert.equal((await call(owner, integrationRoot)).sources.length, 1)
  results.push(
    'SSO modal: two sources, validation retains draft, existing clients:sso issuance/one-time secret, independent manual projects, rename and confirmed deletion',
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
          .filter({ hasText: 'alex@example.test' })
          .locator('.administrator-manage')
          .click()
        await editor.locator('.assigned-projects > summary').click()
        await editor.locator('.project-rights > summary').click()
        await capture(admin, `administrator-modal-${locale}-${width}-${theme}`)
        assert.equal(await editor.evaluate((d) => d.scrollWidth <= d.clientWidth + 1), true)
        await admin.keyboard.press('Escape')
        await admin.goto(origin + '/admin/settings/integrations')
        await admin.locator('#integration-tab-sso').click()
        await capture(admin, `sso-${locale}-${width}-${theme}`)
        await admin.locator('.sso-source-card').locator('.sso-configure').click()
        await ssoEditor.locator('.sso-projects > summary').click()
        await capture(admin, `sso-modal-${locale}-${width}-${theme}`)
        assert.equal(await ssoEditor.evaluate((d) => d.scrollWidth <= d.clientWidth + 1), true)
        await admin.keyboard.press('Escape')
      }
  }
  results.push(
    'Four languages × 1440/390 × light/dark: 64 screenshots, compact grids and accessible scrolling dialogs, no horizontal overflow',
  )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify({ results, errors, smtpMessages: mails.length, requests }, null, 2) + '\n',
  )
  await rm(path.join(output, 'failure.json'), { force: true })
  console.log(JSON.stringify({ passed: results.length, errors: errors.length, screenshots: 64 }))
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
  await new Promise((resolve) => (smtp ? smtp.close(resolve) : resolve()))
  await rm(scratch, { recursive: true, force: true })
}
