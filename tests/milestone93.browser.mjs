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
const output = path.join(root, 'artifacts/milestone-9-3/agreements', mysql ? 'mysql' : 'sqlite')
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
  if (actor === 'developer') {
    await p.waitForURL('**/admin/agreement')
    await p.locator('.agreement-actions .primary').click()
    await p.waitForURL('**/admin')
  }
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
  await admin.goto(origin + '/admin/settings/agreement')
  await admin.locator('.agreement-settings-form').waitFor()
  await admin.locator('.agreement-option input').first().check()
  await admin.locator('input[type=date]').fill('2026-10-30')
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    await admin
      .locator('.agreement-language button')
      .filter({ hasText: language.toUpperCase() })
      .click()
    await admin
      .locator('.editor-content')
      .fill(
        'Terms ' +
          language +
          ' <img src=x onerror=alert(1)>. Read and accept these workspace rules.',
      )
    await admin.locator('.editor-content').press('ControlOrMeta+A')
    await admin.locator('.editor-tools button').nth(1).click()
  }
  await admin.locator('.agreement-settings-actions .primary').click()
  await admin.locator('.agreement-settings [role=status]').waitFor()
  let settings = await call(owner, '/api/admin/settings/agreement')
  assert.equal(settings.data.enabled, true)
  assert.equal(settings.data.published.texts['zh-CN'][0].type, 'heading')
  assert.equal(settings.publicUrl, `/w/${f.workspace}/agreement`)
  settings.data.draft.texts.en.push({
    type: 'paragraph',
    content: [
      { text: 'Before ' },
      { text: 'bold', bold: true },
      { text: ' and ' },
      { text: 'italic', italic: true },
      { text: ' after.' },
    ],
  })
  settings = await call(owner, '/api/admin/settings/agreement', 'PUT', {
    enabled: true,
    publish: true,
    requireAgain: false,
    baseRevision: settings.data.published.id,
    draft: settings.data.draft,
  })

  assert.equal(
    await admin.locator('.app-footer a').filter({ hasText: 'Rules and agreement' }).count(),
    1,
  )
  await call(owner, `/api/admin/workspaces/${f.workspace}/branding`, 'PATCH', {
    section: 'contact',
    values: {
      email: 'support@example.test',
      website: 'https://company.example.test',
      address: 'PRIVATE ADDRESS',
    },
  })
  await admin.goto(origin + '/admin/settings/branding')
  await admin.locator('.branding-tabs button').filter({ hasText: 'Application footer' }).click()
  await admin.locator('.branding-toggles [role=switch]').first().check()
  await admin.locator('.branding-toggles [role=switch]').nth(1).check()
  await admin.locator('.branding-editor form button[type=submit]').click()
  await admin.waitForFunction(() =>
    document.querySelector('.app-footer a[href="mailto:support@example.test"]'),
  )
  await admin.goto(origin + '/admin/settings/agreement')
  await admin.locator('.agreement-settings-form').waitFor()
  await admin.locator('#agreement-tab-ru').focus()
  await admin.keyboard.press('ArrowRight')
  assert.equal(await admin.locator('#agreement-tab-en').getAttribute('aria-selected'), 'true')
  assert.equal(
    await admin.locator('#agreement-language-panel').getAttribute('aria-labelledby'),
    'agreement-tab-en',
  )
  results.push(
    'Owner publishes structured text in four languages using the WYSIWYG editor; heading formatting and footer public link are persisted',
  )
  const guest = await context(),
    publicDoc = await guest.newPage()
  await publicDoc.goto(origin + settings.publicUrl)
  await publicDoc.locator('.agreement-document').waitFor()
  assert.ok(
    (await publicDoc.locator('.agreement-document').textContent()).includes(
      'Before bold and italic after.',
    ),
  )
  assert.equal(
    await publicDoc.locator('.agreement-document img, .agreement-document script').count(),
    0,
  )
  assert.ok((await publicDoc.locator('.agreement-document').textContent()).includes('<img src=x'))
  assert.equal(await publicDoc.locator('.legal-page .agreement-actions').count(), 1)
  const users = []
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    const link = (
      await call(owner, '/api/admin/administrators/invitations', 'POST', {
        email: `agreement-${language}@example.test`,
        sendEmail: false,
        generateLink: true,
      })
    ).link
    const invitee = await context(1440, 'light', language),
      page = await invitee.newPage()
    await page.goto(link)
    await page.locator('.app-footer').waitFor()
    await page.waitForFunction(() =>
      document.querySelector('.app-footer')?.textContent.includes('Northline'),
    )
    await page.locator('.invitation-entry-actions button').first().click()
    await page.locator('#invited-name').fill('Agreement ' + language)
    await page.locator('#invited-password').fill('Agreement-password-123!')
    await page.locator('#invited-confirmation').fill('Agreement-password-123!')
    await page.locator('form button[type=submit]').click()
    await page.waitForURL('**/admin/agreement')
    await page.locator('.agreement-document').waitFor()
    assert.equal(await page.locator('.admin-sidebar, .client-sidebar').count(), 0)
    const bypass = await invitee.request.get(origin + '/api/admin/projects')
    assert.equal(bypass.status(), 403)
    assert.equal((await bypass.json()).code, 'administrator_agreement_required')
    assert.ok(
      (await page.locator('.agreement-document').textContent()).includes('Terms ' + language),
    )
    users.push({ page, invitee, language })
  }
  results.push(
    'Four real invitation registrations redirect to the agreement; business API and direct admin navigation are denied before acceptance',
  )
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    const item = users.find((user) => user.language === language)
    for (const width of [1440, 390])
      for (const theme of ['light', 'dark']) {
        for (const page of [admin, publicDoc, item.page]) {
          await page.setViewportSize({ width, height: 980 })
          await page.evaluate(
            ({ language, theme }) => {
              localStorage.setItem('projectbrief.locale', language)
              localStorage.setItem('projectbrief.theme', theme)
            },
            { language, theme },
          )
        }
        await admin.goto(origin + '/admin/settings/agreement')
        await admin.locator('.agreement-settings-form').waitFor()
        await capture(admin, `settings-${language}-${width}-${theme}`)
        await publicDoc.goto(origin + settings.publicUrl + '?lang=' + language)
        await publicDoc.locator('.agreement-document').waitFor()
        await capture(publicDoc, `public-${language}-${width}-${theme}`)
        await item.page.reload()
        await item.page.locator('.agreement-document').waitFor()
        await capture(item.page, `acceptance-${language}-${width}-${theme}`)
      }
  }
  for (const item of users) {
    let fail = true
    await item.page.route('**/api/admin/agreement/accept', async (route) => {
      if (fail) {
        fail = false
        await route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ errors: { locale: ['Try again'] } }),
        })
      } else await route.continue()
    })
    await item.page.locator('.agreement-actions .primary').click()
    await item.page.locator('[role=alert]').waitFor()
    assert.ok(item.page.url().endsWith('/admin/agreement'))
    await item.page.locator('.agreement-actions .primary').click()
    await item.page.waitForURL('**/admin/security')
    const status = await call(item.invitee, '/api/session')
    assert.equal(!!status.agreementRequired, false)
    await item.page.goto(origin + '/admin')
    await item.page.locator('.admin-content').waitFor()
    assert.equal(await item.page.locator('.app-footer').count(), 1)
  }
  await admin.goto(origin + '/admin/administrators')
  await admin.locator('.administrator-card').first().waitFor()
  await admin.evaluate(() => {
    localStorage.setItem('projectbrief.locale', 'ru')
    localStorage.setItem('projectbrief.theme', 'light')
  })
  await admin.setViewportSize({ width: 1440, height: 980 })
  await admin.reload()
  await admin.locator('.administrator-card').first().waitFor()
  await capture(admin, 'agreement-cards-1440-light')
  const cards = admin.locator('.administrator-card').filter({ hasText: 'agreement-' })
  assert.equal(await cards.locator('.agreement-status.accepted').count(), 4)
  await cards.first().locator('.administrator-manage').click()
  await admin.locator('.administrator-dialog .agreement-status').waitFor()
  assert.ok(
    (await admin.locator('.administrator-dialog .agreement-status').textContent()).includes(
      '2026-10-30',
    ),
  )
  await capture(admin, 'agreement-status-modal-1440-light')
  await admin.keyboard.press('Escape')
  await admin.evaluate(() => localStorage.setItem('projectbrief.theme', 'dark'))
  await admin.setViewportSize({ width: 390, height: 980 })
  await admin.reload()
  await admin.locator('.administrator-card').first().waitFor()
  await capture(admin, 'agreement-cards-390-dark')
  await admin
    .locator('.administrator-card')
    .filter({ hasText: 'agreement-' })
    .first()
    .locator('.administrator-manage')
    .click()
  await capture(admin, 'agreement-status-modal-390-dark')
  await admin.keyboard.press('Escape')
  results.push(
    'All four languages accepted; simulated failed acceptance retains the page; recommendations follow acceptance; cards and edit modal expose server acceptance evidence',
  )
  // Editing a draft never changes the public document. Publishing corrections leaves accepted administrators working.
  const update = {
    enabled: true,
    publish: false,
    requireAgain: false,
    baseRevision: settings.data.published.id,
    draft: structuredClone(settings.data.draft),
  }
  update.draft.texts.en[0].content[0].text = 'PRIVATE DRAFT'
  await call(owner, '/api/admin/settings/agreement', 'PUT', update)
  assert.ok(
    !JSON.stringify(await call(guest, `/api/public/workspaces/${f.workspace}/agreement`)).includes(
      'PRIVATE DRAFT',
    ),
  )
  update.publish = true
  settings = await call(owner, '/api/admin/settings/agreement', 'PUT', update)
  for (const item of users)
    assert.equal(!!(await call(item.invitee, '/api/session')).agreementRequired, false)
  update.baseRevision = settings.data.published.id
  update.draft.date = '2026-10-31'
  update.requireAgain = true
  settings = await call(owner, '/api/admin/settings/agreement', 'PUT', update)
  for (const item of users) {
    await item.page.goto(origin + '/admin')
    await item.page.waitForURL('**/admin/agreement')
    await item.page.locator('.agreement-actions .primary').waitFor()
  }
  const statuses = await call(owner, '/api/admin/administrators')
  assert.equal(
    statuses.members
      .filter((m) => m.user.email.startsWith('agreement-'))
      .every((m) => m.agreement.status === 'renewal'),
    true,
  )
  const first = users[0]
  await first.page.locator('.agreement-actions .secondary').click()
  await first.page.waitForURL('**/admin/login')
  assert.equal((await call(first.invitee, '/api/session')).authenticated, false)
  const second = users[1]
  await second.page.locator('.agreement-actions .primary').click()
  await second.page.waitForURL('**/admin/security')
  assert.equal(!!(await call(second.invitee, '/api/session')).agreementRequired, false)
  await publicDoc.emulateMedia({ media: 'print' })
  assert.equal(await publicDoc.locator('.app-footer').isVisible(), false)
  await publicDoc.emulateMedia({ media: 'screen' })
  results.push(
    'Private drafts, ordinary corrections, explicit reacceptance, renewal card state, logout without acceptance and printable public document verified',
  )
  const client = await context(),
    clientPage = await client.newPage()
  await clientPage.goto(origin + '/access/' + f.token)
  await clientPage.locator('.client-sidebar').waitFor()
  await clientPage.locator('.app-footer').waitFor()
  assert.ok((await clientPage.locator('.app-footer').textContent()).includes('Northline'))
  await capture(clientPage, 'client-footer-1440-light')
  await clientPage.setViewportSize({ width: 390, height: 980 })
  await capture(clientPage, 'client-footer-390-light')
  const developer = await context(),
    devPage = await login(developer, 'developer')
  assert.equal(await devPage.locator('.app-footer').count(), 1)
  results.push(
    'Shared footer renders for owner, developer, client, invitation and public/acceptance pages; safe structured text is inert',
  )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify({ results, errors, smtpMessages: mails.length, requests }, null, 2) + '\n',
  )
  await rm(path.join(output, 'failure.json'), { force: true })
  console.log(JSON.stringify({ passed: results.length, errors: errors.length, screenshots: 54 }))
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
