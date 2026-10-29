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
const output = path.join(root, 'artifacts/milestone-8-4', mysql ? 'mysql' : 'sqlite')
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
  const bootstrapFile = path.join(app, 'bootstrap/app.php')
  const instrumented =
    (await readFile(bootstrapFile, 'utf8')).replace(
      'return Application::configure',
      '$qaApplication = Application::configure',
    ) +
    String.raw`
$qaApplication->booted(function () use ($qaApplication): void {
    if (!isset($_SERVER['REQUEST_URI']) || !str_contains(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/sync')) { return; }
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $qaApplication['events']->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function ($event): void {
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        $body = json_decode($event->response->getContent(), true);
        file_put_contents(getenv('M84_QA_METRICS'), json_encode(['persona'=>str_contains($_SERVER['REQUEST_URI'],'/admin/')?'admin':'client','queries'=>count($queries),'sqlMs'=>array_sum(array_column($queries,'time')),'events'=>count($body['notifications']??[]),'changed'=>$body['changed']??null,'bytes'=>strlen($event->response->getContent())])."\n", FILE_APPEND|LOCK_EX);
    });
});
return $qaApplication;
`
  await writeFile(bootstrapFile, instrumented)
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
  const clockTime = Date.now(),
    owner = await context(),
    admin = await login(owner, 'owner', clockTime)
  await admin.goto(origin + '/admin/settings/integrations')
  await admin.locator('#token-name').waitFor()
  assert.equal(await admin.locator('#token-name').isVisible(), true)
  const tokenResult = admin.waitForResponse(
    (r) => r.url().endsWith('/api/admin/integrations/tokens') && r.request().method() === 'POST',
  )
  await admin.locator('#token-name').fill('Milestone token')
  await admin.locator('#create-tokens button[type=submit]').click()
  assert.equal((await tokenResult).status(), 201)
  await admin.locator('.integration-secret').waitFor({ state: 'visible' })
  assert.match(await admin.locator('.integration-secret-value').innerText(), /^pb_[a-f0-9]{64}$/)
  assert.equal(
    await admin.locator('#token-name').isVisible(),
    true,
    'Do not fold before one-time secret acknowledgement',
  )
  await admin.locator('.integration-secret .button.primary').click()
  await admin.locator('.integration-secret .button.secondary').click()
  await admin.locator('#token-name').waitFor({ state: 'hidden' })
  await admin.getByRole('button', { name: 'Create a new API token', exact: true }).click()
  await admin.locator('#token-name').fill('Retained draft')
  await admin.getByRole('button', { name: 'Create a new API token', exact: true }).click()
  await admin.getByRole('button', { name: 'Create a new API token', exact: true }).click()
  assert.equal(await admin.locator('#token-name').inputValue(), 'Retained draft')
  await admin.getByRole('tab', { name: 'Webhooks', exact: true }).click()
  assert.equal(await admin.locator('#hook-name').isVisible(), true)
  await admin.locator('#hook-name').fill('Milestone webhook')
  await admin.locator('#hook-url').fill('https://example.com/events')
  const hookResponse = admin.waitForResponse(
    (r) => r.url().endsWith('/api/admin/integrations/webhooks') && r.request().method() === 'POST',
  )
  await admin.locator('#create-webhooks button[type=submit]').click()
  assert.equal((await hookResponse).status(), 201)
  await admin.locator('.integration-secret').waitFor({ state: 'visible' })
  assert.equal(await admin.locator('#hook-name').isVisible(), true)
  await admin.locator('.integration-secret .button.primary').click()
  await admin.locator('.integration-secret .button.secondary').click()
  await admin.locator('#hook-name').waitFor({ state: 'hidden' })
  await admin
    .locator('#integration-panel-webhooks')
    .getByRole('button', { name: 'Edit', exact: true })
    .click()
  assert.equal(await admin.locator('#hook-name').inputValue(), 'Milestone webhook')
  await admin.getByRole('button', { name: 'Cancel', exact: true }).click()
  await admin.locator('#hook-name').waitFor({ state: 'hidden' })
  await admin.getByRole('tab', { name: 'SSO', exact: true }).click()
  assert.equal(await admin.locator('.integration-invitation-mode').count(), 0)
  await capture(admin, 'integrations-sso-desktop-light')
  const operationToken = await call(owner, '/api/admin/integrations/tokens', 'POST', {
    name: 'Operation_' + 'x'.repeat(80),
    scopes: ['projects:provision'],
  })
  const operationResponse = await owner.request.post(origin + '/api/v1/projects/provision', {
    headers: {
      Authorization: 'Bearer ' + operationToken.secret,
      'Idempotency-Key': 'm84-operation',
    },
    data: {
      externalId: 'm84-order',
      client: { name: 'Client', email: 'operation@example.test' },
      project: { name: 'Operation example', language: 'en' },
      sendInvitation: false,
    },
  })
  assert.equal(operationResponse.status(), 201)
  await admin.getByRole('tab', { name: 'Recent operations', exact: true }).click()
  await admin.locator('#integration-panel-operations article').waitFor()
  await capture(admin, 'operations-desktop-light')
  await admin.setViewportSize({ width: 390, height: 844 })
  await capture(admin, 'operations-mobile-light')
  await admin.setViewportSize({ width: 1440, height: 980 })
  await admin.getByRole('tab', { name: 'API tokens', exact: true }).click()
  await capture(admin, 'integrations-tokens-desktop-light')
  await admin.setViewportSize({ width: 390, height: 844 })
  await capture(admin, 'integrations-tokens-mobile-light')
  await admin.setViewportSize({ width: 1440, height: 980 })
  results.push({
    test: 'lists-first-collapsible-creators-retained-drafts-copy-before-fold-and-separate-sso',
    passed: true,
  })

  const client = await context(),
    cpPage = await client.newPage()
  await cpPage.goto(origin + '/access/' + f.token)
  await cpPage.waitForURL('**/project/' + f.project)
  await admin.goto(origin + '/admin/projects/' + f.project + '/brief')
  await admin.locator('.task-card').first().waitFor()
  assert.equal(await admin.locator('.toast-event').count(), 0, 'No initial backlog')
  const idlePoll = admin.waitForResponse((r) => r.url().includes('/api/admin/sync?'))
  await admin.clock.fastForward(61000)
  assert.equal((await (await idlePoll).json()).notifications.length, 0)
  const task = (
    await call(client, '/api/client/tasks', 'POST', {
      title: 'M84 new client task',
      location: 'Website',
      section: 'Website',
      description: 'Requirements',
      priority: 'normal',
    })
  ).data
  const poll = () => admin.waitForResponse((r) => r.url().includes('/api/admin/sync?'))
  let response = poll()
  await admin.clock.fastForward(61000)
  const firstSync = await response
  assert.equal(firstSync.status(), 200, await firstSync.text())
  const firstBody = await firstSync.json()
  assert.ok(
    firstBody.notifications?.some((event) => event.task === 'M84 new client task'),
    JSON.stringify(firstBody),
  )
  await admin.locator('.toast-event').filter({ hasText: 'M84 new client task' }).waitFor()
  const firstIds = (await response).url()
  response = poll()
  await admin.clock.fastForward(61000)
  await response
  assert.equal(
    await admin.locator('.toast-event').count(),
    0,
    'Overlap must not redisplay an expired banner',
  )
  await call(client, '/api/client/tasks/' + task.id + '/comments', 'POST', {
    text: '<b>Checked layout</b><script>alert(1)</script>',
    submissionId: crypto.randomUUID(),
  })
  response = poll()
  await admin.clock.fastForward(61000)
  await response
  await admin.locator('.toast-event').filter({ hasText: 'Checked layout' }).waitFor()
  assert.equal(await admin.locator('.toast-event script').count(), 0)
  await admin.locator('.toast-event').filter({ hasText: 'Checked layout' }).click()
  await admin.waitForURL((url) => url.hash.startsWith('#comment-'))
  await admin
    .locator('textarea')
    .first()
    .waitFor()
    .catch(() => {})
  await capture(admin, 'comment-toast-navigation-desktop-light')
  results.push({
    test: 'live-task-and-comment-safe-preview-click-target-overlap-dedup-no-initial-backlog',
    passed: true,
  })

  // A same-persona follower shares the existing coordinator and never repeats banners.
  const follower = await owner.newPage()
  await follower.clock.install({ time: await admin.evaluate(() => Date.now()) })
  await follower.clock.pauseAt((await admin.evaluate(() => Date.now())) + 1000)
  await follower.goto(origin + '/admin/projects/' + f.project + '/brief')
  await follower.locator('.task-card').first().waitFor()
  await admin.bringToFront()
  await call(client, '/api/client/tasks/' + task.id + '/comments', 'POST', {
    text: 'Only one visible banner across tabs',
    submissionId: crypto.randomUUID(),
  })
  const before = requests.filter((r) => r.url === '/api/admin/sync').length
  response = poll()
  await admin.clock.fastForward(61000)
  await response
  await follower.clock.fastForward(61000)
  assert.ok(
    requests.filter((r) => r.url === '/api/admin/sync').length - before <= 1,
    'One shared periodic request',
  )
  assert.ok(
    (await admin.locator('.toast-event').count()) +
      (await follower.locator('.toast-event').count()) <=
      1,
  )
  await follower.close()
  results.push({
    test: 'real-broadcast-channel-and-web-locks-single-periodic-request',
    passed: true,
  })

  // Staff changes are visible to clients without losing an unsent discussion draft.
  await cpPage.clock.install({ time: clockTime })
  await cpPage.clock.pauseAt(clockTime + 1000)
  cpPage.qaPaused = true
  await cpPage.goto(origin + '/project/' + f.project + '/discussions')
  await cpPage.locator('#project-comment').fill('UNSENT CLIENT DRAFT')
  await call(owner, '/api/admin/tasks/' + task.id, 'PATCH', { status: 'done' })
  const clientSync = cpPage.waitForResponse((r) => r.url().includes('/sync?'))
  await cpPage.clock.fastForward(61000)
  await clientSync
  await cpPage.locator('.toast-event').filter({ hasText: 'M84 new client task' }).waitFor()
  assert.equal(await cpPage.locator('#project-comment').inputValue(), 'UNSENT CLIENT DRAFT')
  assert.match(await cpPage.locator('.toast-event').innerText(), /Done/)
  await cpPage.setViewportSize({ width: 390, height: 844 })
  await capture(cpPage, 'status-toast-client-mobile-light')
  const commentLabels = {
    en: 'New comment from',
    ru: 'Новый комментарий от',
    tr: 'tarafından yeni yorum',
    'zh-CN': '的新评论',
  }
  for (const [index, lang] of ['en', 'ru', 'tr', 'zh-CN'].entries()) {
    const mode = index % 2 ? 'dark' : 'light'
    await call(owner, '/api/admin/me/locale', 'PATCH', { locale: lang })
    await admin.evaluate(
      ({ lang, mode }) => {
        localStorage.setItem('projectbrief.locale', lang)
        localStorage.setItem('projectbrief.theme', mode)
      },
      { lang, mode },
    )
    await admin.setViewportSize({ width: index % 2 ? 390 : 1440, height: 980 })
    await admin.goto(origin + '/admin/settings/integrations')
    await admin.locator('#create-tokens').waitFor({ state: 'hidden' })
    await capture(admin, 'integrations-' + lang + '-' + mode)
    await admin.goto(origin + '/admin/projects/' + f.project + '/brief')
    await admin.locator('.task-card').first().waitFor()
    await call(client, '/api/client/tasks/' + task.id + '/comments', 'POST', {
      text: 'Locale preview ' + lang,
      submissionId: crypto.randomUUID(),
    })
    response = poll()
    await admin.clock.fastForward(61000)
    await response
    const banner = admin.locator('.toast-event').filter({ hasText: 'Locale preview ' + lang })
    await banner.waitFor()
    assert.ok((await banner.innerText()).includes(commentLabels[lang]))
    await capture(admin, 'comment-toast-' + lang + '-' + mode)
  }
  await call(owner, '/api/admin/me/locale', 'PATCH', { locale: 'en' })
  results.push({
    test: 'four-locale-integration-folds-and-localized-event-banners-light-dark-mobile-desktop',
    passed: true,
  })
  await client.close()
  await owner.close()
  results.push({ test: 'client-status-toast-retains-unsent-comment', passed: true })

  const localeLabels = {
    en: ['Password recovery', 'Password changed successfully.'],
    ru: ['Восстановление пароля', 'Пароль успешно изменён.'],
    tr: [],
    'zh-CN': [],
  }
  for (const [index, locale] of ['en', 'ru', 'tr', 'zh-CN'].entries()) {
    const mode = index % 2 ? 'dark' : 'light',
      c = await context(index % 2 ? 390 : 1440, mode, locale),
      p = await c.newPage()
    await p.goto(origin + '/admin/forgot-password')
    assert.equal(await p.locator('.appearance-controls').count(), 1)
    await capture(p, 'forgot-' + locale + '-' + mode)
    await p.locator('#recovery-email').fill('client@example.test')
    await p.locator('form button[type=submit]').click()
    await p.locator('.account-success').waitFor()
    await c.close()
  }
  const resetData = (email, mode = '') =>
    JSON.parse(php(['tests/milestone84-fixture.php', email, mode]))
  async function openReset(data, locale = 'en', mode = 'light') {
    const c = await context(390, mode, locale),
      p = await c.newPage()
    await p.clock.install({ time: clockTime })
    await p.clock.pauseAt(clockTime + 1000)
    p.qaPaused = true
    await p.goto(
      origin +
        '/admin/reset-password#token=' +
        data.token +
        '&email=' +
        encodeURIComponent(data.email),
    )
    await p.locator('#recovery-password').waitFor()
    assert.equal(new URL(p.url()).hash, '')
    assert.equal(await p.locator('.appearance-controls').count(), 1)
    return { c, p }
  }
  let data = resetData('developer@example.test', 'none'),
    r = await openReset(data)
  assert.equal(await r.p.locator('#reset-totp,#reset-recovery,#recovery-email').count(), 0)
  await r.p.locator('#recovery-password').fill('M84-password-no-factor!')
  await r.p.locator('#recovery-confirmation').fill('M84-password-no-factor!')
  let submits = 0
  await r.p.route('**/api/admin/password/reset', async (route) => {
    submits++
    const reply = await route.fetch()
    await new Promise((resolve) => setTimeout(resolve, 120))
    await route.fulfill({ response: reply })
  })
  await r.p.locator('form button[type=submit]').click()
  await r.p
    .locator('form button[type=submit]')
    .click({ force: true })
    .catch(() => {})
  await r.p.locator('.account-success').waitFor()
  assert.equal(submits, 1)
  assert.equal(await r.p.locator('form').count(), 0)
  await capture(r.p, 'reset-success-mobile-light')
  await r.p.clock.fastForward(1600)
  await r.p.waitForURL('**/admin/login')
  await r.c.close()
  data = resetData('owner@example.test')
  r = await openReset(data, 'en', 'dark')
  await r.p.locator('#recovery-password').fill('M84-owner-password!')
  await r.p.locator('#recovery-confirmation').fill('M84-owner-password!')
  await r.p.locator('#reset-totp').fill('000000')
  await r.p.locator('form button[type=submit]').click()
  await r.p.locator('.field-error').waitFor()
  assert.equal(await r.p.locator('#recovery-password').inputValue(), 'M84-owner-password!')
  await r.p
    .getByRole('button', { name: 'No access to the app? Use a recovery code', exact: true })
    .click()
  assert.equal(await r.p.locator('#reset-totp').count(), 0)
  await r.p.locator('#reset-recovery').fill(data.recovery)
  await capture(r.p, 'reset-recovery-mobile-dark')
  await r.p.locator('form button[type=submit]').click()
  await r.p.locator('.account-success').waitFor()
  await r.p.getByRole('link', { name: 'Back to sign in', exact: true }).click()
  await r.p.waitForURL('**/admin/login')
  const replay = await r.c.request.post(origin + '/api/admin/password/inspect', {
    data: { email: data.email, token: data.token },
    headers: { 'X-CSRF-TOKEN': (await (await r.c.request.get(origin + '/api/csrf')).json()).token },
  })
  assert.equal(replay.status(), 422)
  await r.c.close()
  data = resetData('owner@example.test')
  r = await openReset(data)
  await r.p.locator('#recovery-password').fill('M84-owner-password-totp!')
  await r.p.locator('#recovery-confirmation').fill('M84-owner-password-totp!')
  await r.p
    .getByRole('button', { name: 'No access to the app? Use a recovery code', exact: true })
    .click()
  await r.p.getByRole('button', { name: 'Use authenticator', exact: true }).click()
  assert.equal(await r.p.locator('#reset-recovery').count(), 0)
  await r.p.locator('#reset-totp').fill(data.code)
  await r.p.locator('form button[type=submit]').click()
  await r.p.locator('.account-success').waitFor()
  await r.c.close()
  data = resetData('owner@example.test', 'expired')
  const expired = await context(),
    ep = await expired.newPage()
  await ep.goto(
    origin +
      '/admin/reset-password#token=' +
      data.token +
      '&email=' +
      encodeURIComponent(data.email),
  )
  await ep.locator('.field-error').waitFor()
  assert.equal(await ep.locator('#reset-totp,#reset-recovery,#recovery-password').count(), 0)
  await expired.close()
  results.push({
    test: 'password-four-locales-no-duplicate-switchers-token-privacy-totp-recovery-errors-redirect-double-submit-and-replay',
    passed: true,
  })
  assert.equal(errors.length, 0, errors.join('\n'))
  const sqlMeasurements = (await readFile(env.M84_QA_METRICS, 'utf8'))
    .trim()
    .split('\n')
    .filter(Boolean)
    .map(JSON.parse)
  await writeFile(
    path.join(output, 'http-sql.json'),
    JSON.stringify(sqlMeasurements, null, 2) + '\n',
  )
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify(
      {
        results,
        errors,
        database: mysql ? 'MariaDB' : 'SQLite',
        requests,
        sharedPollSeconds: 60,
        noExtraPolling: true,
      },
      null,
      2,
    ) + '\n',
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
