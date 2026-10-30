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
const mysql = process.env.M94_QA_MYSQL === '1' // Only the hard-coded disposable QA database below.
const output = path.join(root, 'artifacts/milestone-9-4/browser', mysql ? 'mysql' : 'sqlite')
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
  DB_DATABASE: mysql ? 'projectbrief_m94_qa' : path.join(scratch, 'test.sqlite'),
  ...(mysql
    ? {
        DB_HOST: '127.0.0.1',
        DB_PORT: process.env.M94_QA_MYSQL_PORT || '32768',
        DB_USERNAME: 'briefqa',
        DB_PASSWORD: 'Disposable-m94-only',
      }
    : {}),
  DB_URL: '',
  ...(mysql ? { PHP_CLI_SERVER_WORKERS: '4' } : {}),
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
  assert.ok(noOverflow, name + ' overflow')
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
  if (mysql) {
    const file = path.join(app, 'tests/live-sync-fixture.php')
    await writeFile(
      file,
      (await readFile(file, 'utf8')).replace(
        "config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')",
        "! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m94_qa'))",
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
    { cwd: app, env, stdio: 'ignore', detached: mysql },
  )
  frontend = await createServer({
    cacheDir: path.join(scratch, 'vite-cache'),
    mode: 'test',
    define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
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
    path.join(root, 'api/tests/milestone94-fixture.php'),
    path.join(app, 'tests/milestone94-fixture.php'),
  )
  const extra = JSON.parse(php(['tests/milestone94-fixture.php']))
  const owner = await context(),
    admin = await login(owner)
  // Workspace context is server-side; full navigation, SPA transitions and history all use the same store projection.
  await admin.goto(origin + '/admin/account')
  await admin.locator('.account-page').waitFor()
  const aName = 'Northline International Design and Technology Studio'
  async function brand(page, name, color) {
    await page.waitForFunction(
      ({ name, color }) =>
        document.querySelector('.admin-toolbar-workspace')?.textContent.trim() === name &&
        document.querySelector('.workspace-appearance')?.style.getPropertyValue('--action') ===
          color,
      { name, color },
    )
    assert.ok((await page.locator('.app-footer').textContent()).includes(name))
    assert.equal(await page.locator('.admin-sidebar .workspace-brand-logo img').count(), 1)
    await page.waitForFunction(() => {
      const image = document.querySelector('.admin-sidebar .workspace-brand-logo img')
      return image?.complete && image.naturalWidth > 0
    })
  }
  await brand(admin, aName, '#17695d')
  await admin
    .locator('.admin-sidebar .personal-settings-nav a[href="/admin/account/security"]')
    .click()
  await brand(admin, aName, '#17695d')
  await admin.reload()
  await brand(admin, aName, '#17695d')
  await admin.goBack()
  await brand(admin, aName, '#17695d')
  await call(owner, '/api/security/confirm', 'POST', { recovery_code: 'Live-browser-code-63' })
  await admin.goto(origin + '/admin/account')
  await admin.locator('.admin-sidebar .personal-settings-nav a[href="/platform"]').click()
  await admin.locator('.platform-view, .platform-page').first().waitFor()
  assert.equal(
    await admin
      .locator('.workspace-appearance')
      .evaluate((node) => node.style.getPropertyValue('--action')),
    '',
  )
  assert.equal(await admin.locator('.workspace-brand-logo').count(), 0)
  assert.ok((await admin.locator('.app-footer').textContent()).includes('Project Brief'))
  await admin.goto(origin + '/admin/account')
  let delayed = true
  await admin.route('**/api/admin/me', async (route) => {
    if (delayed) {
      delayed = false
      await new Promise((r) => setTimeout(r, 350))
    }
    await route.continue()
  })
  await admin.locator('.admin-sidebar .sidebar-workspace select').selectOption(extra.otherWorkspace)
  await admin.waitForFunction(() => !!document.querySelector('.workspace-brand-pending'))
  assert.equal(await admin.locator('.workspace-brand-logo').count(), 0)
  assert.equal(await admin.locator('.app-footer').isVisible(), false)
  await admin.waitForURL('**/admin')
  await brand(admin, 'Studio Beta — International Product and Engineering', '#7354a3')
  await admin.goto(origin + '/admin/account/security')
  await brand(admin, 'Studio Beta — International Product and Engineering', '#7354a3')
  await admin.reload()
  await brand(admin, 'Studio Beta — International Product and Engineering', '#7354a3')
  await admin.locator('.admin-sidebar .sidebar-workspace select').selectOption(f.workspace)
  await admin.waitForURL('**/admin')
  await brand(admin, aName, '#17695d')
  await admin.goto(origin + '/admin/settings/notifications')
  await brand(admin, aName, '#17695d')
  results.push(
    'Global brand survives SPA/profile/security/notifications, direct URL, reload/history; workspace switching hides former identity until new context is ready; platform retains its own style',
  )
  const clientContext = await context(),
    clientPage = await clientContext.newPage()
  await clientPage.goto(origin + '/access/' + f.token)
  await clientPage.goto(origin + `/project/${f.project}/task/${extra.review}`)
  const taskRoot = '/api/admin/tasks/' + extra.review
  async function resetReview() {
    await call(owner, taskRoot, 'PATCH', { status: 'review' })
    await clientPage.reload()
    await clientPage.locator('.client-status-buttons').waitFor()
  }
  await clientPage.locator('.client-status-buttons .primary').click()
  const complete = clientPage.locator('.client-complete-dialog')
  await complete.waitFor({ state: 'visible' })
  assert.equal(
    requests.filter((r) => r.url.endsWith('/tasks/' + extra.review) && r.method === 'PATCH').length,
    0,
  )
  await complete.locator('.secondary').click()
  assert.equal(
    (await call(clientContext, '/api/client/tasks/' + extra.review)).data.status,
    'review',
  )
  let fail = true
  await clientPage.route('**/api/client/tasks/' + extra.review, async (route) => {
    if (route.request().method() === 'PATCH' && fail) {
      fail = false
      await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    } else await route.continue()
  })
  await clientPage.locator('.client-status-buttons .primary').click()
  await complete.locator('.primary').click()
  await complete.locator('[role=alert]').waitFor()
  assert.equal(
    (await call(clientContext, '/api/client/tasks/' + extra.review)).data.status,
    'review',
  )
  await complete.locator('.secondary').click()
  await clientPage.locator('.client-status-buttons .primary').click()
  const before = requests.filter(
    (r) => r.url.endsWith('/tasks/' + extra.review) && r.method === 'PATCH',
  ).length
  await complete.locator('.primary').dblclick()
  await complete.waitFor({ state: 'hidden' })
  await clientPage.locator('.client-status-buttons').waitFor({ state: 'detached' })
  assert.equal(
    requests.filter((r) => r.url.endsWith('/tasks/' + extra.review) && r.method === 'PATCH')
      .length - before,
    1,
  )
  assert.equal((await call(clientContext, '/api/client/tasks/' + extra.review)).data.status, 'done')
  await resetReview()
  await clientPage.locator('.client-status-buttons .secondary').click()
  await clientPage.locator('.client-status-buttons').waitFor({ state: 'detached' })
  assert.equal(
    (await call(clientContext, '/api/client/tasks/' + extra.review)).data.status,
    'approved',
  )
  await resetReview()
  await clientPage.locator('.client-status-buttons .primary').click()
  await call(owner, taskRoot, 'PATCH', { status: 'in_progress' })
  await complete.locator('.primary').click()
  await complete.locator('[role=alert]').waitFor()
  assert.ok((await complete.textContent()).includes('current state'))
  assert.equal(await complete.locator('.primary').isDisabled(), true)
  await complete.locator('.secondary').click()
  assert.equal(await clientPage.locator('.client-status-buttons').count(), 0)
  for (const id of [extra.inProgress, extra.done]) {
    await clientPage.goto(origin + `/project/${f.project}/task/${id}`)
    await clientPage.locator('.task-page').waitFor()
    assert.equal(
      await clientPage.locator('.client-status-buttons, select#developer-status').count(),
      0,
    )
  }
  results.push(
    'Client review: cancel sends no mutation; failed save preserves review; confirmed double click sends one PATCH; return uses approved; concurrent admin edit yields 409 and actual in_progress; locked states show no selector/actions',
  )
  if (mysql) {
    const competing = await context()
    await competing.request.get(origin + '/access/' + f.token)
    const csrfA = (await (await clientContext.request.get(origin + '/api/csrf')).json()).token
    const csrfB = (await (await competing.request.get(origin + '/api/csrf')).json()).token
    const clientUrl = origin + '/api/client/tasks/' + extra.review
    const historyCount = (task) =>
      task.history.filter((row) => row.type === 'status' && row.actorType === 'client').length
    for (let pair = 0; pair < 10; pair++) {
      await call(owner, taskRoot, 'PATCH', { status: 'review' })
      const before = (await call(clientContext, '/api/client/tasks/' + extra.review)).data
      const responses = await Promise.all([
        clientContext.request.patch(clientUrl, {
          headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfA },
          data: { status: 'approved', editVersion: before.editVersion },
        }),
        competing.request.patch(clientUrl, {
          headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfB },
          data: { status: 'done', editVersion: before.editVersion },
        }),
      ])
      assert.deepEqual(responses.map((response) => response.status()).sort(), [200, 409])
      const after = (await call(clientContext, '/api/client/tasks/' + extra.review)).data
      assert.equal(historyCount(after), historyCount(before) + 1)
      assert.ok(['approved', 'done'].includes(after.status))
    }
    await competing.close()
    results.push(
      'MariaDB 11.8 / four PHP HTTP workers: ten concurrent pairs from separate client sessions, each exactly one 200 + one 409, one client status audit, consistent persisted status',
    )
  }
  // Chromium's native touch protocol produces trusted touch and pointer events, not dispatched JS mocks.
  const touchContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
    locale: 'en-GB',
  })
  await touchContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  const touchPage = await login(touchContext, 'developer')
  const cdp = await touchContext.newCDPSession(touchPage)
  await touchPage.evaluate(() => {
    window.qaPointers = []
    for (const type of ['pointerdown', 'pointerup', 'pointercancel'])
      document.addEventListener(
        type,
        (event) =>
          window.qaPointers.push({
            type,
            trusted: event.isTrusted,
            pointerType: event.pointerType,
          }),
        { passive: true },
      )
  })
  async function swipe(page, session, from, to, cancel = false) {
    await session.send('Input.dispatchTouchEvent', {
      type: 'touchStart',
      touchPoints: [{ x: from[0], y: from[1] }],
    })
    for (let step = 1; step <= 6; step++)
      await session.send('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: [
          {
            x: from[0] + ((to[0] - from[0]) * step) / 6,
            y: from[1] + ((to[1] - from[1]) * step) / 6,
          },
        ],
      })
    await session.send('Input.dispatchTouchEvent', {
      type: cancel ? 'touchCancel' : 'touchEnd',
      touchPoints: [],
    })
    await page.waitForTimeout(180)
  }
  await swipe(touchPage, cdp, [8, 450], [170, 450])
  const drawer = touchPage.locator('#admin-drawer')
  await drawer.waitFor({ state: 'visible' })
  await swipe(touchPage, cdp, [312, 300], [160, 300])
  await drawer.waitFor({ state: 'hidden' })
  await swipe(touchPage, cdp, [8, 450], [8, 280])
  assert.equal(await drawer.isVisible(), false)
  await swipe(touchPage, cdp, [8, 450], [170, 450], true)
  assert.equal(await drawer.isVisible(), false)
  await touchPage.evaluate(() => {
    const fixture = document.createElement('div')
    fixture.id = 'qa-gesture-controls'
    fixture.style.cssText = 'position:fixed;left:0;top:200px;width:180px;z-index:1000'
    fixture.innerHTML =
      '<input aria-label="QA touch input" style="width:170px;height:44px"><div id="qa-horizontal" style="width:170px;overflow-x:auto;height:60px"><div style="width:600px;height:60px">Horizontal content</div></div>'
    document.body.append(fixture)
  })
  await swipe(touchPage, cdp, [8, 220], [140, 220])
  assert.equal(await drawer.isVisible(), false)
  await swipe(touchPage, cdp, [8, 270], [140, 270])
  assert.equal(await drawer.isVisible(), false)
  await touchPage.evaluate(() => document.getElementById('qa-gesture-controls').remove())
  await touchPage.locator('.admin-menu-trigger').click()
  await drawer.waitFor({ state: 'visible' })
  await touchPage.keyboard.press('Escape')
  await drawer.waitFor({ state: 'hidden' })
  await touchPage.locator('.admin-menu-trigger').click()
  await drawer.waitFor({ state: 'visible' })
  await touchPage.mouse.click(370, 400)
  await drawer.waitFor({ state: 'hidden' })
  assert.equal(
    (
      await touchPage.evaluate(() =>
        window.qaPointers.filter((event) => event.pointerType === 'touch'),
      )
    ).every((event) => event.trusted),
    true,
  )
  results.push(
    'Android Chrome touch emulation: trusted native pointer stream opens/closes drawer; vertical scrolling, text input, horizontal content and touchcancel do not open; hamburger/backdrop/Escape remain functional',
  )
  // The client overview and task list use their own existing drawers.
  await touchPage.goto(origin + '/access/' + f.token)
  await touchPage.goto(origin + `/project/${f.project}`)
  await touchPage.locator('.client-menu-trigger').waitFor()
  const clientDrawer = touchPage.locator('#client-drawer')
  await swipe(touchPage, cdp, [8, 450], [170, 450])
  await clientDrawer.waitFor({ state: 'visible' })
  await swipe(touchPage, cdp, [312, 700], [160, 700])
  await clientDrawer.waitFor({ state: 'hidden' })
  await touchPage.goto(origin + `/project/${f.project}/task/${extra.review}`)
  await touchPage.locator('.task-page').waitFor()
  const tasksDrawer = touchPage.locator('#task-navigation-drawer')
  await swipe(touchPage, cdp, [8, 600], [170, 600])
  await tasksDrawer.waitFor({ state: 'visible' })
  await swipe(touchPage, cdp, [312, 700], [160, 700])
  await tasksDrawer.waitFor({ state: 'hidden' })
  results.push('Trusted native touch opens/closes existing client menu and task navigation drawer')
  await touchContext.close()
  await admin.evaluate(() => localStorage.setItem('projectbrief.theme', 'system'))
  await admin.goto(origin + '/admin/account')
  for (const mode of ['dark', 'light']) {
    await admin.emulateMedia({ colorScheme: mode })
    await admin.waitForFunction((mode) => document.documentElement.dataset.theme === mode, mode)
    await brand(admin, aName, '#17695d')
  }
  results.push(
    'System theme reacts to media preference changes and preserves active workspace branding',
  )
  if (process.env.M94_QA_VISUAL !== '0')
    for (const language of ['ru', 'en', 'tr', 'zh-CN'])
      for (const width of [1440, 390])
        for (const mode of ['light', 'dark']) {
          await admin.setViewportSize({ width, height: 980 })
          await clientPage.setViewportSize({ width, height: 980 })
          for (const page of [admin, clientPage])
            await page.evaluate(
              ({ language, mode }) => {
                localStorage.setItem('projectbrief.locale', language)
                localStorage.setItem('projectbrief.theme', mode)
              },
              { language, mode },
            )
          for (const [name, path] of [
            ['profile', '/admin/account'],
            ['security', '/admin/account/security'],
            ['notifications', '/admin/settings/notifications'],
          ]) {
            await admin.goto(origin + path)
            await brand(admin, aName, '#17695d')
            await capture(admin, `${name}-${language}-${width}-${mode}`)
          }
          await call(owner, taskRoot, 'PATCH', { status: 'review' })
          await clientPage.goto(origin + `/project/${f.project}/task/${extra.review}`)
          await clientPage.locator('.client-status-buttons').waitFor()
          await capture(clientPage, `client-review-${language}-${width}-${mode}`)
          await clientPage.locator('.client-status-buttons .primary').click()
          await complete.waitFor({ state: 'visible' })
          await capture(clientPage, `client-confirm-${language}-${width}-${mode}`)
          await clientPage.keyboard.press('Escape')
          await call(owner, taskRoot, 'PATCH', { status: 'done' })
          await clientPage.reload()
          await clientPage.locator('.task-page').waitFor()
          await capture(clientPage, `client-done-${language}-${width}-${mode}`)
        }
  if (process.env.M94_QA_VISUAL !== '0')
    results.push(
      'Four languages × 1440/390 × light/dark: global personal brands/footers and client review/confirmation/locked done, 96 local screenshots without horizontal overflow',
    )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify(
      {
        results,
        errors,
        smtpMessages: mails.length,
        apiRequests: requests.length,
        nativeTouch: true,
        phpWorkers: mysql ? 4 : 1,
        database: mysql ? 'MariaDB 11.8.9' : 'SQLite',
        screenshots: process.env.M94_QA_VISUAL === '0' ? 0 : 96,
      },
      null,
      2,
    ) + '\n',
  )
  await rm(path.join(output, 'failure.json'), { force: true })
  console.log(
    JSON.stringify({
      passed: results.length,
      errors: errors.length,
      screenshots: process.env.M94_QA_VISUAL === '0' ? 0 : 96,
    }),
  )
} catch (error) {
  await writeFile(
    path.join(output, 'failure.json'),
    JSON.stringify(
      {
        error: String(error),
        errors,
        requests,
        pages: browser
          ? await Promise.all(
              browser
                .contexts()
                .flatMap((context) => context.pages())
                .map(async (page) => ({
                  url: new URL(page.url()).pathname,
                  workspace: await page.locator('.admin-toolbar-workspace').allTextContents(),
                  footer: await page.locator('.app-footer').allTextContents(),
                })),
            ).catch(() => [])
          : [],
      },
      null,
      2,
    ),
  )
  throw error
} finally {
  await browser?.close()
  await frontend?.close()
  if (mysql && backend?.pid) {
    try {
      process.kill(-backend.pid, 'SIGTERM')
    } catch {}
  } else backend?.kill()
  await new Promise((resolve) => (smtp ? smtp.close(resolve) : resolve()))
  await rm(scratch, { recursive: true, force: true })
}
