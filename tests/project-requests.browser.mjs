import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, cp, rm, readFile, readdir } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import net from 'node:net'
import { createServer, preview } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = fileURLToPath(new URL('..', import.meta.url))
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-provisioning-'))
const app = path.join(scratch, 'app')
const mysql = !!process.env.PROVISIONING_MYSQL_PORT
const productionBuild = process.env.PROJECT_REQUESTS_PRODUCTION_BUILD !== '0'
const output = path.join(
  root,
  'artifacts/milestone-8-1-project-requests',
  mysql ? 'mysql' : 'sqlite',
)
await mkdir(app)
await mkdir(output, { recursive: true })
let backend, otherBackend, frontend, browser, smtp
const results = [],
  errors = [],
  messages = [],
  smtpObservations = []
let smtpFail = false
async function port() {
  const server = net.createServer()
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const result = server.address().port
  await new Promise((resolve) => server.close(resolve))
  return result
}
const apiPort = await port(),
  otherPort = await port(),
  webPort = await port(),
  smtpPort = await port()
const origin = `http://127.0.0.1:${webPort}`
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
  DB_URL: '',
  ...(mysql
    ? {
        DB_HOST: '127.0.0.1',
        DB_PORT: process.env.PROVISIONING_MYSQL_PORT,
        DB_USERNAME: 'briefqa',
        DB_PASSWORD: 'Disposable-m80-only',
      }
    : {}),
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'provisioning_test_session',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'file',
  MAIL_MAILER: 'smtp',
  MAIL_HOST: '127.0.0.1',
  MAIL_PORT: String(smtpPort),
  MAIL_USERNAME: '',
  MAIL_PASSWORD: '',
  MAIL_FROM_ADDRESS: 'system@example.test',
  MAIL_SCHEME: 'smtp',
  LOG_CHANNEL: 'single',
  QUEUE_CONNECTION: 'sync',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args) {
  const result = spawnSync('php', args, { cwd: app, env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
function state() {
  return JSON.parse(php(['tests/project-requests-fixture.php', 'state']))
}
async function call(context, url, method = 'GET', body) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET')
    headers['X-CSRF-TOKEN'] = (await (await context.request.get(origin + '/api/csrf')).json()).token
  const response = await context.request.fetch(origin + url, {
    method,
    headers,
    ...(body ? { data: body } : {}),
  })
  assert.ok(response.ok(), url + ' ' + response.status() + ' ' + (await response.text()))
  return response.json()
}
async function context(width = 1440, theme = 'light', locale = 'en') {
  const context = await browser.newContext({
    viewport: { width, height: 980 },
    reducedMotion: 'reduce',
    colorScheme: theme,
  })
  await context.addInitScript(
    ({ theme, locale }) => {
      try {
        if (!localStorage.getItem('projectbrief.theme'))
          localStorage.setItem('projectbrief.theme', theme)
        if (!localStorage.getItem('projectbrief.locale'))
          localStorage.setItem('projectbrief.locale', locale)
      } catch {}
    },
    { theme, locale },
  )
  context.on('page', (p) => p.on('pageerror', (e) => errors.push(String(e))))
  return context
}
async function capture(page, name) {
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.evaluate(() => document.fonts.ready)
  while (await page.locator('.toast-close').count())
    await page.locator('.toast-close').first().click()
  await page.evaluate(() => {
    document.activeElement?.blur()
    window.scrollTo(0, 0)
  })
  await page.waitForTimeout(220)
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
    name + ': overflow',
  )
  if (await page.locator('.client-project-login').count()) {
    const box = await page.locator('.client-project-login').boundingBox()
    assert.ok(Math.abs(box.x + box.width / 2 - page.viewportSize().width / 2) <= 1)
    assert.ok(box.width <= 481)
    const contentBox = await page.locator('#main-content').boundingBox()
    const workspaceBox = await page.locator('.workspace').boundingBox()
    assert.ok(
      Math.abs(contentBox.y + contentBox.height / 2 - (workspaceBox.y + workspaceBox.height / 2)) <=
        12,
    )
  }
  await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true })
}
function decodedMail(index = messages.length - 1) {
  return messages[index]
    .replace(/=\r?\n/g, '')
    .replace(/=([0-9A-F]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16)))
}
function mailLink(index = messages.length - 1) {
  const text = decodedMail(index)
  return origin + text.match(/\/access\/[a-f0-9]{64}/)[0]
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
  ]) {
    await cp(path.join(root, 'api', entry), path.join(app, entry), {
      recursive: true,
      filter: (f) => !/(?:^|\/)(?:\.DS_Store|cache\/[^/]+|[^/]*\.sqlite[^/]*)$/.test(f),
    })
  }
  await mkdir(path.join(app, 'tests'))
  await cp(
    path.join(root, 'api/tests/provisioning-fixture.php'),
    path.join(app, 'tests/provisioning-fixture.php'),
  )
  await cp(
    path.join(root, 'api/tests/project-requests-fixture.php'),
    path.join(app, 'tests/project-requests-fixture.php'),
  )
  for (const directory of [
    'bootstrap/cache',
    'storage/framework/views',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/logs',
  ]) {
    await mkdir(path.join(app, directory), { recursive: true })
  }
  await writeFile(path.join(app, '.env'), '')
  if (!mysql) await writeFile(env.DB_DATABASE, '')
  const deferredMigrations = (await readdir(path.join(app, 'database/migrations'))).filter(
    (name) => name >= '2026_10_29_123816_create_project_requests_foundation.php',
  )
  const deferredSources = []
  for (const name of deferredMigrations) {
    const file = path.join(app, 'database/migrations', name)
    deferredSources.push([file, await readFile(file)])
    await rm(file)
  }
  if (mysql) php(['artisan', 'db:wipe', '--force', '--no-interaction'])
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const legacy = JSON.parse(php(['tests/provisioning-fixture.php', 'legacy']))
  for (const [file, source] of deferredSources) await writeFile(file, source)
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/project-requests-fixture.php']))
  assert.equal(state().projects, 1)
  results.push({
    test: 'upgrade-from-client-entry-081-retains-existing-project-and-client',
    passed: true,
  })
  smtp = net.createServer((socket) => {
    socket.write('220 localhost ESMTP\r\n')
    let buffer = '',
      data = false
    socket.on('data', (chunk) => {
      buffer += chunk.toString()
      while (buffer.includes('\r\n')) {
        if (data) {
          const end = buffer.indexOf('\r\n.\r\n')
          if (end < 0) return
          const message = buffer.slice(0, end)
          buffer = buffer.slice(end + 5)
          data = false
          smtpObservations.push(state())
          if (smtpFail) socket.write('550 5.0.0 Simulated delivery failure\r\n')
          else {
            messages.push(message)
            socket.write('250 2.0.0 Accepted\r\n')
          }
          continue
        }
        const end = buffer.indexOf('\r\n'),
          line = buffer.slice(0, end)
        buffer = buffer.slice(end + 2)
        if (/^EHLO/.test(line)) socket.write('250-localhost\r\n250 SIZE 1000000\r\n')
        else if (/^DATA/.test(line)) {
          data = true
          socket.write('354 End with dot\r\n')
        } else if (/^QUIT/.test(line)) socket.end('221 Goodbye\r\n')
        else socket.write('250 OK\r\n')
      }
    })
  })
  await new Promise((resolve) => smtp.listen(smtpPort, '127.0.0.1', resolve))
  const serve = (port) =>
    spawn(
      'php',
      ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'],
      { cwd: app, env, stdio: 'ignore' },
    )
  backend = serve(apiPort)
  otherBackend = serve(otherPort)
  frontend = productionBuild
    ? await preview({
        configFile: path.join(root, 'vite.config.js'),
        preview: {
          host: '127.0.0.1',
          port: webPort,
          strictPort: true,
          proxy: {
            '/api': { target: `http://127.0.0.1:${apiPort}` },
            '/access/': { target: `http://127.0.0.1:${apiPort}` },
            '/branding-assets': { target: `http://127.0.0.1:${apiPort}` },
          },
        },
        logLevel: 'error',
      })
    : await createServer({
        cacheDir: path.join(scratch, 'vite-cache'),
        mode: 'test',
        define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
        server: {
          host: '127.0.0.1',
          port: webPort,
          strictPort: true,
          hmr: false,
          proxy: {
            '/api': { target: `http://127.0.0.1:${apiPort}` },
            '/access/': { target: `http://127.0.0.1:${apiPort}` },
            '/branding-assets': { target: `http://127.0.0.1:${apiPort}` },
          },
        },
        logLevel: 'error',
      })
  if (!productionBuild) await frontend.listen()
  for (let i = 0; i < 100; i++) {
    try {
      if (
        (await fetch(`http://127.0.0.1:${otherPort}/api/health`)).ok &&
        (await fetch(`http://127.0.0.1:${apiPort}/api/health`)).ok
      )
        break
    } catch {}
    await new Promise((r) => setTimeout(r, 50))
  }
  browser = await chromium.launch({ headless: true })
  const body = {
    externalId: 'web86-request-105',
    client: {
      name: 'Ada Client',
      email: 'ada@example.test',
      company: 'Ada Studio',
      website: 'https://example.test',
    },
    project: {
      name: 'Corporate website',
      description:
        'Accessible website with a service catalog. <script>window.PB_UNSAFE = true</script>',
      language: 'en',
    },
  }
  async function apiRequest(
    pathname = '',
    method = 'GET',
    input,
    key = 'request-105',
    token = fixture.token,
    target = apiPort,
  ) {
    const response = await fetch(`http://127.0.0.1:${target}/api/v1/project-requests${pathname}`, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + token.secret,
        'Idempotency-Key': key,
      },
      ...(input !== undefined ? { body: JSON.stringify(input) } : {}),
    })
    return {
      status: response.status,
      body: await response.json(),
      replayed: response.headers.get('Idempotency-Replayed'),
    }
  }
  async function external(action, input, token = fixture.token) {
    return new Promise((resolve, reject) => {
      const process = spawn('php', [path.join(root, 'docs/milestone-8-1/modx/smoke.php'), action], {
        env: {
          PATH: env.PATH,
          PB_ORIGIN: origin,
          PB_TOKEN: token.secret,
          PB_ALLOW_LOCAL_HTTP: '1',
        },
      })
      let output = '',
        error = ''
      process.stdout.on('data', (data) => (output += data))
      process.stderr.on('data', (data) => (error += data))
      process.on('error', reject)
      process.on('close', (code) =>
        code === 0
          ? resolve(JSON.parse(output))
          : reject(new Error('External helper failed: ' + error)),
      )
      process.stdin.end(JSON.stringify(input))
    })
  }
  function resetLimits() {
    php(['artisan', 'cache:clear'])
  }
  const owner = await context(),
    admin = await owner.newPage()
  await admin.goto(origin + '/admin/login')
  await admin.locator('#admin-email').fill('owner@example.test')
  await admin.locator('#admin-password').fill('Local-provisioning-test!')
  await admin.locator('form button[type=submit]').click()
  await admin.waitForURL('**/admin/security')
  await call(owner, '/api/security/challenge', 'POST', {
    recovery_code: 'Provisioning-browser-recovery',
  })
  await admin.goto(origin + '/admin/requests')
  await admin.locator('.request-empty').waitFor()
  const created = await external('create', { body, idempotencyKey: 'external-create-105' })
  const id = created.data.requestId
  assert.equal(created.data.status, 'pending')
  assert.equal(state().projects, 1)
  assert.equal(state().contacts, 0)
  assert.equal(state().memberships, 1)
  assert.equal(messages.length, 1, 'Owner notification after commit')
  assert.equal(smtpObservations[0].requests, 1)
  const notificationCount = state().deliveries
  assert.equal(
    (await external('create', { body, idempotencyKey: 'external-create-105' })).data.requestId,
    id,
  )
  assert.equal(messages.length, 1)
  assert.equal(state().deliveries, notificationCount)
  await admin.evaluate(() =>
    navigator.serviceWorker.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'projectbrief-sync', id: 'qa-request-created' },
      }),
    ),
  )
  await admin.locator('.request-card').filter({ hasText: 'Corporate website' }).waitFor()
  assert.equal(
    await admin.locator('a[href="/admin/requests"] .unread-badge').first().innerText(),
    '1',
  )
  results.push({
    test: 'external-php-create-owner-notification-no-access-replay-and-live-sync-count',
    passed: true,
  })
  const listing = await external('list', { status: 'pending', search: 'Ada Studio', perPage: 1 })
  assert.equal(listing.data[0].id, id)
  assert.equal(listing.meta.total, 1)
  assert.equal(
    (await apiRequest('/' + id, 'GET', undefined, 'foreign', fixture.otherToken)).status,
    404,
  )
  assert.equal(
    (await apiRequest('/' + id + '/approve', 'POST', {}, 'foreign', fixture.otherToken)).status,
    404,
  )
  assert.equal(
    (
      await apiRequest(
        '',
        'POST',
        { ...body, project: { ...body.project, name: 'Changed' } },
        'changed',
      )
    ).status,
    409,
  )
  await capture(admin, 'requests-en-desktop-light')
  await admin.getByRole('button', { name: 'View', exact: true }).first().click()
  await admin.locator('.request-detail-name').waitFor()
  assert.equal(
    await admin.locator('dialog .request-description').first().innerText(),
    body.project.description,
  )
  assert.equal(await admin.evaluate(() => !!window.PB_UNSAFE), false)
  await capture(admin, 'request-pending-en-desktop-light')
  const mailBeforeApproval = messages.length
  await admin.getByRole('button', { name: 'Approve', exact: true }).click()
  await admin.getByRole('link', { name: 'Open project', exact: true }).waitFor()
  const approved = await external('get', { requestId: id })
  assert.equal(approved.data.status, 'approved')
  assert.equal(approved.data.invitation, 'sent')
  assert.equal(messages.length, mailBeforeApproval + 1)
  assert.equal(smtpObservations.at(-1).decisions[0], 'approved')
  const mailIndex = messages.findIndex((_, index) => decodedMail(index).includes('/access/'))
  assert.ok(mailIndex >= 0)
  assert.ok(decodedMail(mailIndex).includes('7 days'))
  const client = await context(),
    clientPage = await client.newPage()
  await clientPage.goto(mailLink(mailIndex))
  await clientPage.waitForURL('**/project/' + approved.data.projectId)
  await clientPage.getByRole('heading', { name: body.project.name, exact: true }).waitFor()
  assert.equal(await clientPage.locator('#client-login-email').count(), 0)
  assert.equal(
    (await call(client, '/api/client/project/' + approved.data.projectId)).data.id,
    approved.data.projectId,
  )
  await capture(clientPage, 'approved-client-project-en-desktop')
  await client.close()
  const beforeReplay = state().projects
  assert.equal(
    (
      await external('approve', {
        requestId: id,
        sendInvitation: true,
        idempotencyKey: 'api-approval-recovery',
      })
    ).data.projectId,
    approved.data.projectId,
  )
  assert.equal(state().projects, beforeReplay)
  assert.equal(messages.length, mailBeforeApproval + 1)
  results.push({
    test: 'modx-request-owner-ui-approve-provisioning-smtp-invitation-client-url-and-api-recovery',
    passed: true,
  })
  resetLimits()
  const parallelBody = { ...body, externalId: 'parallel-create' }
  const parallel = await Promise.all(
    Array.from({ length: 8 }, (_, index) =>
      apiRequest(
        '',
        'POST',
        parallelBody,
        index < 4 ? 'same-key' : 'distinct-' + index,
        fixture.token,
        index % 2 ? apiPort : otherPort,
      ),
    ),
  )
  assert.ok(
    parallel.every((result) => [200, 201].includes(result.status)),
    JSON.stringify(parallel),
  )
  assert.equal(new Set(parallel.map((result) => result.body.data.requestId)).size, 1)
  const parallelId = parallel[0].body.data.requestId
  const csrf = (await (await owner.request.get(origin + '/api/csrf')).json()).token
  const baseline = state().projects
  const uiApi = await Promise.all([
    apiRequest(
      '/' + parallelId + '/approve',
      'POST',
      { sendInvitation: false },
      'parallel-approve',
      fixture.token,
      otherPort,
    ),
    owner.request
      .post(origin + '/api/admin/project-requests/' + parallelId + '/approve', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        data: { sendInvitation: false },
      })
      .then(async (response) => ({ status: response.status(), body: await response.json() })),
  ])
  assert.ok(
    uiApi.every((result) => result.status === 200),
    JSON.stringify(uiApi),
  )
  assert.equal(new Set(uiApi.map((result) => result.body.data.projectId)).size, 1)
  assert.equal(state().projects, baseline + 1)
  assert.equal(state().contacts, 1, 'Same workspace client reused')
  const conflict = await apiRequest(
    '',
    'POST',
    { ...body, externalId: 'conflicting-decision' },
    'conflict-create',
  )
  const conflictId = conflict.body.data.requestId
  const race = await Promise.all([
    apiRequest(
      '/' + conflictId + '/approve',
      'POST',
      { sendInvitation: false },
      'race-approve',
      fixture.token,
      otherPort,
    ),
    owner.request
      .post(origin + '/api/admin/project-requests/' + conflictId + '/reject', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        data: { reason: 'Outside scope' },
      })
      .then(async (response) => ({ status: response.status(), body: await response.json() })),
  ])
  assert.deepEqual(race.map((result) => result.status).sort(), [200, 409])
  const winner = await external('get', { requestId: conflictId })
  assert.ok(['approved', 'rejected'].includes(winner.data.status))
  results.push({
    test: 'parallel-submission-ui-api-approval-and-approval-rejection-conflict-two-processes',
    passed: true,
  })
  const replacement = JSON.parse(
    php(['tests/project-requests-fixture.php', 'rotate', fixture.token.id]),
  )
  assert.equal((await apiRequest('', 'POST', body, 'old-token')).status, 401)
  assert.equal((await apiRequest('', 'POST', body, 'new-key', replacement)).body.data.requestId, id)
  assert.equal(
    (
      await apiRequest(
        '/' + id + '/approve',
        'POST',
        { sendInvitation: true },
        'new-token-approval',
        replacement,
      )
    ).body.data.projectId,
    approved.data.projectId,
  )
  fixture.token = replacement
  const failed = await apiRequest(
    '',
    'POST',
    {
      ...body,
      externalId: 'smtp-failure',
      client: { ...body.client, email: 'second@example.test' },
    },
    'smtp-create',
  )
  smtpFail = true
  const failedResult = await apiRequest(
    '/' + failed.body.data.requestId + '/approve',
    'POST',
    {},
    'smtp-approve',
  )
  smtpFail = false
  assert.equal(failedResult.status, 200)
  assert.equal(failedResult.body.data.status, 'approved')
  assert.equal(failedResult.body.data.invitation, 'failed')
  assert.equal(
    (await apiRequest('/' + failed.body.data.requestId + '/approve', 'POST', {}, 'smtp-recovery'))
      .body.data.projectId,
    failedResult.body.data.projectId,
  )
  results.push({
    test: 'replacement-token-retains-source-and-smtp-failure-keeps-one-approved-project',
    passed: true,
  })
  resetLimits()
  const rejected = await apiRequest(
    '',
    'POST',
    {
      ...body,
      externalId: 'ui-rejection',
      project: { ...body.project, name: 'Request to decline' },
    },
    'ui-rejection',
  )
  await admin.goto(origin + '/admin/requests?request=' + rejected.body.data.requestId)
  await admin.locator('.request-detail-name').waitFor()
  await admin.getByRole('button', { name: 'Reject', exact: true }).click()
  await admin.locator('#request-reason').fill('Not within the studio scope.')
  await apiRequest(
    '',
    'POST',
    { ...body, externalId: 'draft-refresh-event' },
    'draft-refresh-event',
  )
  await admin.evaluate(() =>
    navigator.serviceWorker.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'projectbrief-sync', id: 'qa-preserve-rejection-draft' },
      }),
    ),
  )
  await admin.waitForResponse(
    (response) =>
      response.url().includes('/api/admin/project-requests?') &&
      response.request().method() === 'GET',
  )
  assert.equal(await admin.locator('#request-reason').inputValue(), 'Not within the studio scope.')
  await capture(admin, 'request-rejection-confirm-en-desktop')
  await admin.getByRole('button', { name: 'Confirm rejection', exact: true }).click()
  await admin.locator('dialog').getByText('Not within the studio scope.', { exact: true }).waitFor()
  assert.equal(
    (await external('get', { requestId: rejected.body.data.requestId })).data.status,
    'rejected',
  )
  await apiRequest(
    '',
    'POST',
    {
      ...body,
      externalId: 'matrix-pending',
      project: { ...body.project, name: 'Website request awaiting review' },
    },
    'matrix-pending',
  )
  const languages = {
    en: 'Project requests',
    ru: 'Заявки',
    tr: 'Proje talepleri',
    'zh-CN': '项目申请',
  }
  for (const [locale, title] of Object.entries(languages)) {
    resetLimits()
    await call(owner, '/api/admin/me/locale', 'PATCH', { locale })
    await admin.evaluate((locale) => {
      localStorage.setItem('projectbrief.locale', locale)
      localStorage.setItem('projectbrief.theme', 'dark')
    }, locale)
    await admin.setViewportSize({ width: 1440, height: 980 })
    await admin.goto(origin + '/admin/requests')
    await admin.getByRole('heading', { name: title, exact: true }).waitFor()
    await admin.locator('.request-card').first().waitFor()
    await capture(admin, 'requests-' + locale + '-desktop-dark')
    await admin.setViewportSize({ width: 390, height: 844 })
    await capture(admin, 'requests-' + locale + '-mobile-dark')
    await admin.locator('.request-card .button').first().click()
    await admin.locator('.request-detail-name').waitFor()
    await capture(admin, 'request-detail-' + locale + '-mobile-dark')
    await admin.locator('dialog[open] .modal-header button').click()
  }
  resetLimits()
  await call(owner, '/api/admin/me/locale', 'PATCH', { locale: 'en' })
  await admin.evaluate(() => {
    localStorage.setItem('projectbrief.locale', 'en')
    localStorage.setItem('projectbrief.theme', 'light')
  })
  await admin.goto(origin + '/admin/requests')
  await admin.locator('.request-card').first().waitFor()
  await capture(admin, 'requests-en-mobile-light')
  results.push({
    test: 'owner-rejection-confirmation-four-locales-light-dark-desktop-mobile-escaping',
    passed: true,
  })
  const developer = await context(),
    developerPage = await developer.newPage()
  await developerPage.goto(origin + '/admin/login')
  await developerPage.locator('#admin-email').fill('developer@example.test')
  await developerPage.locator('#admin-password').fill('Local-provisioning-test!')
  await developerPage.locator('form button[type=submit]').click()
  await developerPage.waitForURL('**/admin')
  await developerPage.goto(origin + '/admin/requests')
  await developerPage.waitForURL((url) => url.pathname === '/admin/unavailable')
  assert.equal(await developerPage.locator('a[href="/admin/requests"]').count(), 0)
  await developer.close()
  await admin.goto(origin + '/admin/settings/integrations')
  await admin.locator('#token-name').waitFor()
  await admin.locator('input[type=checkbox][value="requests:create"]').check()
  await admin.locator('input[type=checkbox][value="requests:approve"]').check()
  await admin.locator('#token-source').selectOption({ label: 'web86.site' })
  assert.equal(await admin.locator('#token-source-name').count(), 0)
  await capture(admin, 'request-scopes-en-mobile-light')
  const operations = await call(owner, '/api/admin/integrations/operations')
  assert.ok(operations.data.some((row) => row.operationType === 'request.create'))
  assert.ok(operations.data.some((row) => row.operationType === 'request.approve'))
  results.push({
    test: 'developer-denied-source-reuse-token-scopes-and-distinct-operation-history',
    passed: true,
  })
  const finalState = state()
  const logs = await readFile(path.join(app, 'storage/logs/laravel.log'), 'utf8').catch(() => '')
  const requestEvents = finalState.events.filter((event) =>
    event.type.startsWith('project_request.'),
  )
  assert.equal(
    requestEvents.filter((event) => event.type === 'project_request.created').length,
    finalState.requests,
  )
  const magicTokens = messages.flatMap((_, index) =>
    [...decodedMail(index).matchAll(/\/access\/([a-f0-9]{64})/g)].map((match) => match[1]),
  )
  for (const value of [
    ...magicTokens,
    body.client.name,
    body.client.email,
    body.client.company,
    body.project.name,
    body.project.description,
    fixture.token.secret,
    fixture.otherToken.secret,
  ]) {
    assert.equal(JSON.stringify(requestEvents).includes(value), false)
    assert.equal(logs.includes(value), false)
  }
  assert.equal(JSON.stringify(finalState.events).includes('/access/'), false)
  assert.equal(errors.length, 0, errors.join('\n'))
  results.push({ test: 'unique-business-events-and-contact-secret-log-payload-scan', passed: true })
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify(
      {
        results,
        browserErrors: errors,
        concurrentProcesses: 2,
        php: '8.5',
        database: mysql ? 'MariaDB 11.8.9' : 'SQLite',
        mail: 'local SMTP sink',
        productionBuild,
        liveJino: false,
      },
      null,
      2,
    ) + '\n',
  )
  console.log(
    JSON.stringify({
      passed: results.length,
      screenshots: (await readdir(output)).filter((file) => file.endsWith('.png')).length,
    }),
  )
} catch (error) {
  console.error(await readFile(path.join(app, 'storage/logs/laravel.log'), 'utf8').catch(() => ''))
  throw error
} finally {
  await browser?.close()
  if (productionBuild && frontend)
    await new Promise((resolve) => frontend.httpServer.close(resolve))
  else await frontend?.close()
  backend?.kill('SIGTERM')
  otherBackend?.kill('SIGTERM')
  if (smtp) await new Promise((resolve) => smtp.close(resolve))
  await rm(scratch, { recursive: true, force: true })
}
