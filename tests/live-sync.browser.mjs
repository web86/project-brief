import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = fileURLToPath(new URL('..', import.meta.url))
const baseline = process.env.LIVE_SYNC_BASELINE === '1'
const output = path.join(root, 'artifacts/live-sync-ui', baseline ? 'before' : 'after')
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
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(scratch, 'test.sqlite'),
  DB_URL: '',
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
    permissions: ['notifications'],
  })
  await c.addInitScript(
    ({ mode, locale }) => {
      try {
        localStorage.setItem('projectbrief.theme', mode)
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
  await writeFile(env.DB_DATABASE, '')
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
  const names = {
    ru: 'Международная студия цифровых продуктов и веб-разработки Северная Линия',
    en: 'Northline International Digital Product Design and Website Development Studio',
    tr: 'Kuzey Çizgisi Uluslararası Dijital Ürün Tasarımı ve Web Geliştirme Stüdyosu',
    'zh-CN': '北线国际数字产品设计与专业网站开发工作室及客户合作中心',
  }
  if (process.env.LIVE_SYNC_IDLE_ONLY === '1') {
    const clockTime = Date.now(),
      idleContext = await context(),
      idlePage = await login(idleContext, 'owner', clockTime)
    await idlePage.clock.fastForward(1000)
    await idlePage.waitForLoadState('networkidle')
    const traffic = [],
      reading = [],
      inflight = new Set()
    const isApi = (url) => new URL(url).pathname.startsWith('/api/')
    idlePage.on('request', (request) => {
      if (isApi(request.url())) inflight.add(request)
    })
    idlePage.on('requestfinished', (request) => inflight.delete(request))
    idlePage.on('requestfailed', (request) => inflight.delete(request))
    idlePage.on('response', (response) => {
      if (isApi(response.url()))
        reading.push(
          response.body().then((body) =>
            traffic.push({
              path: new URL(response.url()).pathname,
              status: response.status(),
              bytes: body.length,
            }),
          ),
        )
    })
    const initialSync = requests.filter((request) => request.url === '/api/admin/sync').length
    assert.equal(initialSync, 0)
    for (let minute = 1; minute <= 15; minute++) {
      await idlePage.clock.fastForward(60000)
      await new Promise((resolve) => setTimeout(resolve, 50))
      for (let attempt = 0; inflight.size && attempt < 100; attempt++)
        await new Promise((resolve) => setTimeout(resolve, 25))
      assert.equal(inflight.size, 0)
      await idlePage.waitForLoadState('networkidle')
    }
    await Promise.all(reading)
    const polls = traffic.filter((row) => row.path === '/api/admin/sync')
    assert.equal(polls.length, baseline ? 0 : 3)
    const byEndpoint = Object.groupBy(traffic, (row) => row.path)
    results.push({
      test: '15-minutes-minute-by-minute-real-HTTP',
      virtualMinutes: 15,
      clockStepSeconds: 60,
      initialExtraSyncRequests: initialSync,
      totalRequests: traffic.length,
      syncRequests: polls.length,
      decodedResponseBytes: traffic.reduce((sum, row) => sum + row.bytes, 0),
      endpoints: Object.fromEntries(
        Object.entries(byEndpoint).map(([endpoint, rows]) => [
          endpoint,
          {
            requests: rows.length,
            decodedResponseBytes: rows.reduce((sum, row) => sum + row.bytes, 0),
          },
        ]),
      ),
      passed: true,
    })
    await idleContext.close()
  }
  let i = 0
  for (const width of process.env.LIVE_SYNC_FUNCTIONAL_ONLY === '1' ||
  process.env.LIVE_SYNC_IDLE_ONLY === '1'
    ? []
    : process.env.LIVE_SYNC_UI_WIDTHS?.split(',').map(Number) || [1440, 1280, 768, 390, 360])
    for (const mode of ['light', 'dark']) {
      const locale = ['ru', 'en', 'tr', 'zh-CN'][i++ % 4]
      php([
        'tests/live-sync-fixture.php',
        'configure',
        JSON.stringify({ locale, name: names[locale] }),
      ])
      const admin = await context(width, mode, locale),
        p = await login(admin)
      await capture(p, `${width}-${mode}-${locale}-projects`)
      await p.goto(origin + '/admin/settings/notifications')
      await p
        .locator('.personal-notifications .notification-channel')
        .first()
        .waitFor()
        .catch(() => {})
      await capture(p, `${width}-${mode}-${locale}-notifications`)
      if (!baseline) assert.equal(await p.locator('.account-tabs').count(), 0)
      await p.goto(origin + '/admin/settings/branding')
      await p.locator('.branding-page').waitFor()
      await capture(p, `${width}-${mode}-${locale}-branding`)
      if (width < 960) {
        await p.locator('.admin-menu-trigger').click()
        await p.locator('#admin-drawer[open]').waitFor()
        await capture(p, `${width}-${mode}-${locale}-menu`)
        await p.keyboard.press('Escape')
      }
      if (!baseline) {
        const brand = p.locator(
          width < 960
            ? '.mobile-toolbar-brand .workspace-brand-logo strong'
            : '.admin-sidebar .workspace-brand-logo strong',
        )
        if (await brand.isVisible())
          assert.ok(
            await brand.evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
            'brand text overflow',
          )
      }
      await p.locator('.branding-tabs button').nth(2).click()
      await p.locator('#brand-website').fill('www.company.com')
      await capture(p, `${width}-${mode}-${locale}-website`)
      const client = await context(width, mode, locale),
        q = await client.newPage()
      await q.goto(origin + '/access/' + f.token)
      await q.goto(origin + `/project/${f.project}/task/${f.task}`)
      await q.locator('.history-panel').waitFor()
      await q.locator('.history-panel > summary').click()
      await capture(q, `${width}-${mode}-${locale}-task`)
      if (!baseline && width >= 1280) {
        const dimensions = await q.locator('.task-layout').evaluate((el) => {
          const h = el.querySelector('.history-panel').getBoundingClientRect(),
            d = el.querySelector('.details-surface').getBoundingClientRect()
          return { history: h.width, details: d.width }
        })
        assert.ok(dimensions.history < dimensions.details * 0.5, JSON.stringify(dimensions))
      }
      if ([1440, 390].includes(width)) {
        await q.goto(origin + `/project/${f.project}/discussions`)
        await q.locator('.discussion-page').waitFor()
        await capture(q, `${width}-${mode}-${locale}-discussions`)
      }
      results.push({ test: 'ui-matrix', width, mode, locale, passed: true })
      await admin.close()
      await client.close()
    }
  if (
    !baseline &&
    process.env.LIVE_SYNC_UI_ONLY !== '1' &&
    process.env.LIVE_SYNC_IDLE_ONLY !== '1'
  ) {
    php([
      'tests/live-sync-fixture.php',
      'configure',
      JSON.stringify({ locale: 'en', name: names.en }),
    ])
    const clockTime = Date.now(),
      owner = await context(),
      developer = await context(),
      client = await context()
    const a = await login(owner, 'owner', clockTime),
      b = await login(developer, 'developer', clockTime),
      c = await client.newPage()
    await c.clock.install({ time: clockTime })
    await c.clock.pauseAt(clockTime + 1000)
    await c.goto(origin + '/access/' + f.token)
    for (const p of [a, b]) {
      await p.goto(origin + `/admin/projects/${f.project}/brief/task/${f.task}`)
      await p.locator('.developer-notes .notes-disclosure > summary').click()
      await p.locator('#developer-notes').waitFor()
    }
    await c.goto(origin + `/project/${f.project}/task/${f.task}`)
    await c.locator('#comment-text').waitFor()
    await c.locator('#comment-text').fill('UNSENT CLIENT CONVERSATION DRAFT')
    await b.locator('#developer-notes').fill('UNSAVED PRIVATE TECHNICAL DRAFT')
    await call(owner, '/api/admin/tasks/' + f.task, 'PATCH', { status: 'in_progress' })
    for (const p of [b, c]) {
      const response = p.waitForResponse((r) => r.url().includes('/sync?'))
      await p.clock.fastForward(300000)
      assert.equal((await response).status(), 200)
      await p.waitForFunction(() =>
        document.querySelector('.status-badge')?.classList.contains('tone-blue'),
      )
    }
    assert.equal(
      await b.locator('#developer-notes').inputValue(),
      'UNSAVED PRIVATE TECHNICAL DRAFT',
    )
    assert.equal(await c.locator('#comment-text').inputValue(), 'UNSENT CLIENT CONVERSATION DRAFT')
    await call(owner, '/api/admin/tasks/' + f.task + '/comments', 'POST', {
      text: 'LIVE OWNER MESSAGE',
      submissionId: crypto.randomUUID(),
    })
    await client.grantPermissions(['notifications'], { origin })
    const permission = await c.evaluate(() => Notification.permission)
    results.push({
      test: 'push-permission-environment',
      permission,
      systemDeliveryVerified: permission === 'granted',
    })
    const registration = await c.evaluate(async () => {
      const r = await navigator.serviceWorker.register('/sw.js')
      await navigator.serviceWorker.ready
      return r.scope
    })
    assert.ok(registration)
    const worker = client.serviceWorkers()[0]
    await worker.evaluate(async (capture) => {
      if (capture) {
        self.__qaNotifications = []
        self.registration.showNotification = async (title, options) =>
          self.__qaNotifications.push({ title, ...options })
      }
      let pending
      const event = new Event('push')
      event.data = {
        json: () => ({
          title: 'Project update',
          body: 'Synthetic browser signal',
          tag: 'live-qa-message',
        }),
      }
      event.waitUntil = (p) => (pending = p)
      self.dispatchEvent(event)
      await pending
    }, permission !== 'granted')
    await c.clock.fastForward(250)
    await c.getByText('LIVE OWNER MESSAGE', { exact: true }).waitFor()
    assert.equal(await c.locator('#comment-text').inputValue(), 'UNSENT CLIENT CONVERSATION DRAFT')
    results.push({
      test: 'real-HTTP-three-personas-poll-push-drafts',
      passed: true,
      phpMemoryMiB: 128,
    })
    await call(owner, '/api/admin/tasks/' + f.task + '/comments', 'POST', {
      text: 'MANUALLY CHECKED MESSAGE',
      submissionId: crypto.randomUUID(),
    })
    const manualPolls = requests.filter((request) => request.url.endsWith('/sync')).length
    await c.locator('.check-messages').click()
    await c.getByText('MANUALLY CHECKED MESSAGE', { exact: true }).waitFor()
    assert.equal(requests.filter((request) => request.url.endsWith('/sync')).length, manualPolls)
    results.push({ test: 'manual-conversation-check-independent-of-poll-timer', passed: true })
    const editable = (
      await call(owner, `/api/admin/projects/${f.project}/tasks`, 'POST', {
        title: 'Editable draft requirement',
        location: 'Website',
        section: 'Website',
        description: 'Original editable content',
        priority: 'normal',
      })
    ).data
    await c.goto(origin + `/project/${f.project}/task/${editable.id}/edit`)
    await c.locator('#idea-title').fill('MY UNSAVED CLIENT IDEA')
    await call(owner, '/api/admin/tasks/' + editable.id, 'PATCH', {
      description: 'REMOTE EDITED CONTENT',
    })
    const editSync = c.waitForResponse((r) => r.url().includes('/sync?'))
    await c.clock.fastForward(300000)
    await editSync
    await c.locator('.idea-form > .field-error').first().waitFor()
    assert.equal(await c.locator('#idea-title').inputValue(), 'MY UNSAVED CLIENT IDEA')
    const conflict = c.waitForResponse(
      (r) => r.request().method() === 'PATCH' && r.url().endsWith('/tasks/' + editable.id),
    )
    await c.locator('.idea-form button[type=submit]').click()
    assert.equal((await conflict).status(), 409)
    assert.equal(await c.locator('#idea-title').inputValue(), 'MY UNSAVED CLIENT IDEA')
    await c.locator('.idea-form > button.secondary').click()
    const saved = c.waitForResponse(
      (r) => r.request().method() === 'PATCH' && r.url().endsWith('/tasks/' + editable.id),
    )
    await c.locator('.idea-form button[type=submit]').click()
    assert.equal((await saved).status(), 200)
    results.push({ test: 'client-idea-draft-retained-conflict-explicit-rebase', passed: true })
    const settingsContext = await context(),
      settingsPage = await login(settingsContext)
    await settingsPage.goto(origin + '/admin/settings/branding')
    await settingsPage.locator('.branding-tabs button').nth(2).click()
    await settingsPage.locator('#brand-website').fill('www.company.com')
    const websiteSaved = settingsPage.waitForResponse(
      (r) => r.request().method() === 'PATCH' && r.url().endsWith('/branding'),
    )
    await settingsPage.locator('.branding-page form button[type=submit]').click()
    assert.equal((await websiteSaved).status(), 200)
    assert.equal(
      await settingsPage.locator('#brand-website').inputValue(),
      'https://www.company.com',
    )
    await settingsPage.locator('#brand-website').fill('javascript:alert(1)')
    assert.equal(await settingsPage.locator('#brand-website').getAttribute('aria-invalid'), 'true')
    await settingsPage.waitForTimeout(5000)
    await capture(settingsPage, 'website-invalid')
    await settingsPage.locator('#brand-website').fill('company.com')
    assert.equal(await settingsPage.locator('#brand-website').getAttribute('aria-invalid'), 'false')
    results.push({ test: 'website-prefix-save-and-visible-dangerous-scheme-error', passed: true })
    await settingsContext.close()
    const reviewer = await context(),
      r = await login(reviewer)
    await r.locator('.feedback-request').filter({ hasText: 'View feedback' }).click()
    await r.locator('dialog[open] .feedback-review').waitFor()
    assert.ok((await r.locator('dialog[open]').innerText()).includes('Clear communication'))
    await capture(r, 'feedback-review')
    results.push({ test: 'owner-review-dialog', passed: true })
    await reviewer.close()
    const follower = await owner.newPage()
    await follower.clock.install({ time: clockTime })
    await follower.clock.pauseAt(clockTime + 1000)
    await follower.goto(origin + `/admin/projects/${f.project}/brief/task/${f.task}`)
    await follower.locator('#comment-text').waitFor()
    const firstPoll = a.waitForResponse((r) => r.url().includes('/sync?'))
    await a.clock.fastForward(300000)
    await firstPoll
    await follower.clock.fastForward(300000)
    await owner.grantPermissions(['notifications'], { origin })
    await a.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js')
      await navigator.serviceWorker.ready
    })
    await call(owner, '/api/admin/tasks/' + f.task + '/comments', 'POST', {
      text: 'MULTITAB PUSH MESSAGE',
      submissionId: crypto.randomUUID(),
    })
    const beforePush = requests.filter((r) => r.url === '/api/admin/sync').length
    await owner.serviceWorkers()[0].evaluate(
      async (capture) => {
        if (capture) {
          self.__qaNotifications = []
          self.registration.showNotification = async (title, options) =>
            self.__qaNotifications.push({ title, ...options })
        }
        let pending
        const event = new Event('push')
        event.data = {
          json: () => ({
            title: 'Project update',
            body: 'Two-tab synthetic signal',
            tag: 'live-qa-multitab',
          }),
        }
        event.waitUntil = (p) => (pending = p)
        self.dispatchEvent(event)
        await pending
      },
      (await a.evaluate(() => Notification.permission)) !== 'granted',
    )
    await Promise.all([a.clock.fastForward(250), follower.clock.fastForward(250)])
    await a.getByText('MULTITAB PUSH MESSAGE', { exact: true }).waitFor()
    await follower.getByText('MULTITAB PUSH MESSAGE', { exact: true }).waitFor()
    assert.equal(requests.filter((r) => r.url === '/api/admin/sync').length - beforePush, 1)
    assert.ok(
      (
        await owner
          .serviceWorkers()[0]
          .evaluate(async () =>
            (self.__qaNotifications || (await self.registration.getNotifications())).map(
              (n) => n.tag,
            ),
          )
      ).includes('live-qa-multitab'),
    )
    results.push({ test: 'real-SW-BroadcastChannel-Web-Locks-two-tabs-one-check', passed: true })
    await follower.close()
    await a.goto(origin + `/admin/projects/${f.project}/brief`)
    await a.locator('.task-card').first().waitFor()
    await call(client, '/api/client/tasks', 'POST', {
      title: 'LIVE NEW CLIENT IDEA',
      location: 'Website',
      section: 'Website',
      description: 'New idea added by the client without reloading other participants.',
      priority: 'normal',
    })
    const ideaSync = a.waitForResponse((response) => response.url().includes('/sync?'))
    await a.clock.fastForward(300000)
    await ideaSync
    await a.locator('.task-card').filter({ hasText: 'LIVE NEW CLIENT IDEA' }).waitFor()
    results.push({ test: 'new-client-idea-appears-in-open-admin-brief', passed: true })
    await c.goto(origin + `/project/${f.project}/discussions`)
    await c.locator('#project-comment').fill('UNSENT GENERAL DISCUSSION DRAFT')
    await call(owner, `/api/admin/projects/${f.project}/discussions`, 'POST', {
      text: 'LIVE GENERAL DISCUSSION',
      submissionId: crypto.randomUUID(),
    })
    const discussionSync = c.waitForResponse((response) => response.url().includes('/sync?'))
    await c.clock.fastForward(300000)
    await discussionSync
    await c.getByText('LIVE GENERAL DISCUSSION', { exact: true }).waitFor()
    assert.equal(
      await c.locator('#project-comment').inputValue(),
      'UNSENT GENERAL DISCUSSION DRAFT',
    )
    await c.locator('#discussion-tab-tasks').click()
    await c.getByText('LIVE OWNER MESSAGE', { exact: true }).waitFor()
    results.push({ test: 'general-and-latest-discussions-live-with-retained-draft', passed: true })
    await a.goto(origin + '/admin')
    await a.locator('.admin-project-card').first().waitFor()
    const notifyBefore = requests.filter((r) => r.url === '/api/admin/sync').length
    const idleStart = requests.length
    for (let k = 0; k < 3; k++) {
      const response = a.waitForResponse((r) => r.url().includes('/sync?'))
      await a.clock.fastForward(300000)
      await response
    }
    assert.equal(requests.filter((r) => r.url === '/api/admin/sync').length - notifyBefore, 3)
    results.push({
      test: '15-minutes-idle',
      polls: 3,
      intervalSeconds: 300,
      initialExtraPolls: 0,
      requests: requests.slice(idleStart),
      passed: true,
    })
    await owner.close()
    await developer.close()
    await client.close()
  }
  assert.deepEqual(errors, [])
  await rm(path.join(output, 'failure.json'), { force: true })
  await writeFile(
    path.join(
      output,
      process.env.LIVE_SYNC_IDLE_ONLY === '1'
        ? 'idle-results.json'
        : process.env.LIVE_SYNC_UI_ONLY === '1'
          ? 'ui-results.json'
          : 'results.json',
    ),
    JSON.stringify({ baseline, results, requests, errors }, null, 2) + '\n',
  )
  console.log('PASS', baseline ? 'before' : 'after', results.length, 'browser scenarios')
} catch (error) {
  await writeFile(
    path.join(output, 'failure.json'),
    JSON.stringify({ message: String(error), requests, errors }, null, 2),
  )
  throw error
} finally {
  await browser?.close()
  await frontend?.close()
  backend?.kill()
  await rm(scratch, { recursive: true, force: true })
}
