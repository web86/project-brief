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
const productionBuild = process.env.CLIENT_ENTRY_PRODUCTION_BUILD === '1'
const output =
  process.env.PROVISIONING_QA_DIRECTORY ||
  path.join(root, 'artifacts/client-entry-0-8-1', mysql ? 'mysql' : 'sqlite')
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
  return JSON.parse(php(['tests/provisioning-fixture.php', 'state']))
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
        localStorage.setItem('projectbrief.theme', theme)
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
    (name) => name >= '2026_10_29_092022_add_project_provisioning_foundation.php',
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
  const fixture = JSON.parse(php(['tests/provisioning-fixture.php']))
  assert.equal(state().projects, 1)
  results.push({ test: 'additive-upgrade-on-existing-project-and-client', passed: true })
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
    externalId: 'website-order-1042',
    client: {
      name: 'Ada Client',
      email: 'ada@example.test',
      company: 'Ada Studio',
      website: 'https://example.test',
    },
    project: {
      name: 'Studio website project',
      description: 'Accessible website with multilingual content',
      language: 'en',
    },
    sendInvitation: true,
  }
  async function provision(input = body, key = 'order-1042', token = fixture.token, api = apiPort) {
    const response = await fetch(`http://127.0.0.1:${api}/api/v1/projects/provision`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + token.secret,
        'Idempotency-Key': key,
      },
      body: JSON.stringify(input),
    })
    return {
      status: response.status,
      body: await response.json(),
      replayed: response.headers.get('Idempotency-Replayed'),
    }
  }
  const created = await provision()
  assert.equal(created.status, 201, JSON.stringify(created))
  assert.equal(created.body.data.invitation, 'sent')
  assert.equal(messages.length, 1)
  assert.equal(smtpObservations[0].provisionings, 1)
  assert.ok(smtpObservations[0].states.includes('sending'))
  assert.equal(JSON.stringify(created).includes('/access/'), false)
  results.push({ test: 'real-smtp-delivery-after-visible-commit', passed: true })
  const replay = await provision()
  assert.equal(replay.replayed, 'true')
  assert.equal(replay.body.data.projectId, created.body.data.projectId)
  assert.equal(messages.length, 1)
  const recovery = await provision(body, 'lost-response-recovery')
  assert.equal(recovery.status, 200)
  assert.equal(recovery.body.data.created, false)
  assert.equal(recovery.body.data.projectUrl, created.body.data.projectUrl)
  const parallelBody = { ...body, externalId: 'parallel-order', sendInvitation: false }
  const parallel = await Promise.all(
    Array.from({ length: 8 }, (_, i) =>
      provision(parallelBody, 'parallel-key', fixture.token, i % 2 ? otherPort : apiPort),
    ),
  )
  assert.ok(
    parallel.every((result) => result.status === 201),
    JSON.stringify(parallel),
  )
  assert.equal(new Set(parallel.map((result) => result.body.data.projectId)).size, 1)
  assert.equal(state().projects, 3)
  assert.equal(state().contacts, 1)
  const parallelExternal = { ...parallelBody, externalId: 'parallel-external-id' }
  const distinctKeys = await Promise.all(
    Array.from({ length: 6 }, (_, i) =>
      provision(parallelExternal, 'key-' + i, fixture.token, i % 2 ? otherPort : apiPort),
    ),
  )
  assert.ok(
    distinctKeys.every((result) => [200, 201].includes(result.status)),
    JSON.stringify(distinctKeys),
  )
  assert.equal(distinctKeys.filter((result) => result.status === 201).length, 1)
  assert.equal(new Set(distinctKeys.map((result) => result.body.data.projectId)).size, 1)
  assert.equal(state().projects, 4)
  results.push({
    test: 'parallel-same-key-eight-and-same-external-id-six-two-php-processes',
    passed: true,
  })
  assert.equal(
    (await provision({ ...body, project: { name: 'Overwrite', language: 'en' } }, 'conflict'))
      .status,
    409,
  )
  const foreign = await provision(body, 'foreign-workspace', fixture.otherToken)
  assert.equal(foreign.status, 201)
  assert.notEqual(foreign.body.data.clientId, created.body.data.clientId)
  smtpFail = true
  const failure = await provision({ ...body, externalId: 'smtp-failure' }, 'smtp-failure')
  assert.equal(failure.status, 201)
  assert.equal(failure.body.data.invitation, 'failed')
  smtpFail = false
  assert.equal(
    (await provision({ ...body, externalId: 'smtp-failure' }, 'smtp-failure')).body.data.invitation,
    'failed',
  )
  results.push({ test: 'smtp-failure-durable-result-and-no-automatic-resend', passed: true })
  assert.ok(decodedMail(0).includes('7 days'))
  assert.ok(decodedMail(0).includes('Open project'))
  const invited = await context(),
    invitationPage = await invited.newPage()
  await invitationPage.goto(mailLink(0))
  await invitationPage.waitForURL(created.body.data.projectUrl)
  await invitationPage
    .getByRole('heading', { name: 'Studio website project', exact: true })
    .first()
    .waitFor()
  assert.equal(await invitationPage.locator('#client-login-email').count(), 0)
  await invited.close()
  results.push({
    test: 'initial-invitation-opens-project-directly-without-email-step',
    passed: true,
  })
  const guest = await context(),
    page = await guest.newPage()
  await page.goto(created.body.data.projectUrl)
  await page.waitForURL('**/project/*/login')
  await page.locator('#client-login-email').fill('ada@example.test')
  await capture(page, 'client-login-en-light')
  let releaseLogin,
    loginRequests = 0
  const loginGate = new Promise((resolve) => {
    releaseLogin = resolve
  })
  await page.route('**/api/client/project/*/login', async (route) => {
    loginRequests++
    const response = await route.fetch()
    await loginGate
    await route.fulfill({ response })
  })
  await page.locator('form button[type=submit]').click()
  await page
    .locator('form')
    .evaluate((form) =>
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })),
    )
  releaseLogin()
  await page.getByRole('heading', { name: 'Check your email', exact: true }).waitFor()
  await page.unroute('**/api/client/project/*/login')
  assert.equal(loginRequests, 1)
  assert.equal(await page.locator('#client-login-email').count(), 0)
  assert.equal(
    await page.getByRole('heading', { name: 'Open your project', exact: true }).count(),
    0,
  )
  assert.equal(
    await page
      .getByText(
        'Enter the email address linked to this project. We will email you a sign-in link.',
        { exact: true },
      )
      .count(),
    0,
  )
  await capture(page, 'client-login-sent-en-light')
  const knownSentText = await page.locator('.client-entry-sent').innerText()
  const loginMailIndex = messages.length - 1
  assert.ok(decodedMail(loginMailIndex).includes('30 minutes'))
  assert.ok(!decodedMail(loginMailIndex).includes('7 days'))
  assert.ok(decodedMail(loginMailIndex).includes('Open project'))
  await page.getByRole('button', { name: 'Use another email', exact: true }).click()
  await page.locator('#client-login-email').fill('unknown@example.test')
  const mailBeforeUnknown = messages.length
  await page.locator('form button[type=submit]').click()
  await page.getByRole('heading', { name: 'Check your email', exact: true }).waitFor()
  assert.equal(await page.locator('.client-entry-sent').innerText(), knownSentText)
  assert.equal(messages.length, mailBeforeUnknown)
  results.push({ test: 'sent-state-another-email-privacy-and-duplicate-submit', passed: true })
  const loginLink = mailLink(loginMailIndex)
  await page.goto(loginLink)
  await page.waitForURL(created.body.data.projectUrl)
  await page.getByRole('heading', { name: 'Studio website project', exact: true }).first().waitFor()
  await capture(page, 'client-project-en-light')
  await page.goto(loginLink)
  await page.waitForURL(created.body.data.projectUrl)
  await page.getByRole('heading', { name: 'Studio website project', exact: true }).first().waitFor()
  await page.goto(parallel[0].body.data.projectUrl)
  await page.waitForURL('**/access-error?reason=unavailable')
  php(['tests/provisioning-fixture.php', 'deactivate', created.body.data.projectId])
  await page.goto(created.body.data.projectUrl)
  await page.waitForURL('**/project/*/login')
  const beforeBlockedMail = messages.length
  await page.locator('#client-login-email').fill('ada@example.test')
  await page.locator('form button[type=submit]').click()
  await page.getByRole('status').filter({ hasText: 'If this email' }).waitFor()
  assert.equal(messages.length, beforeBlockedMail)
  results.push({
    test: 'canonical-link-mailbox-login-repeat-magic-link-foreign-project-and-revocation',
    passed: true,
  })
  for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
    const ctx = await context(390, 'dark', locale),
      p = await ctx.newPage()
    await p.goto(recovery.body.data.projectUrl)
    await p.waitForURL('**/project/*/login')
    await p.locator('#client-login-email').waitFor()
    await capture(p, 'client-login-' + locale + '-mobile-dark')
    const initial = await p.locator('.client-entry-heading h1').innerText()
    php(['artisan', 'cache:clear']) // Isolate independent UI cases in the disposable QA cache.
    await p.locator('#client-login-email').fill('ada@example.test')
    await p.locator('form button[type=submit]').click()
    await p.locator('.client-entry-sent h1').waitFor()
    assert.notEqual(await p.locator('.client-entry-sent h1').innerText(), initial)
    assert.equal(await p.locator('.client-entry-heading').count(), 0)
    assert.equal(await p.locator('#client-login-email').count(), 0)
    await capture(p, 'client-login-sent-' + locale + '-mobile-dark')
    await p.setViewportSize({ width: 1440, height: 980 })
    await capture(p, 'client-login-sent-' + locale + '-desktop-dark')
    await p.locator('.client-entry-another').click()
    assert.equal(await p.locator('#client-login-email').inputValue(), 'ada@example.test')
    assert.equal(
      await p.locator('#client-login-email').evaluate((el) => el === document.activeElement),
      true,
    )
    await p.route('**/api/client/project/*/login', (route) =>
      route.fulfill({ status: 429, json: { message: 'Too many requests.' } }),
    )
    await p.locator('form button[type=submit]').click()
    await p.getByRole('alert').waitFor()
    assert.equal(await p.locator('.client-entry-sent').count(), 0)
    assert.equal(await p.locator('#client-login-email').isEnabled(), true)
    assert.equal(await p.locator('form button[type=submit]').isEnabled(), true)
    const rateError = await p.getByRole('alert').innerText()
    await p.unroute('**/api/client/project/*/login')
    await p.route('**/api/client/project/*/login', (route) => route.abort('failed'))
    await p.locator('form button[type=submit]').click()
    await p.waitForFunction((previous) => {
      const alert = document.querySelector('[role=alert]')
      return alert && alert.textContent.trim() !== previous.trim()
    }, rateError)
    assert.equal(await p.locator('.client-entry-sent').count(), 0)
    assert.equal(await p.locator('#client-login-email').isEnabled(), true)
    await ctx.close()
  }
  results.push({
    test: 'login-state-matrix-four-locales-two-sizes-and-recoverable-errors',
    passed: true,
  })
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
  await admin.goto(origin + '/admin/settings/integrations')
  await admin.locator('.integration-item').filter({ hasText: 'Website orders' }).waitFor()
  const tokenItem = admin.locator('.integration-item').filter({ hasText: 'Website orders' })
  await Promise.all([
    admin.waitForResponse(
      (r) => r.url().includes('/api/admin/integrations') && r.request().method() === 'GET',
    ),
    tokenItem.locator('input[type=checkbox]').click(),
  ])
  assert.equal((await provision()).status, 403)
  await Promise.all([
    admin.waitForResponse(
      (r) => r.url().includes('/api/admin/integrations') && r.request().method() === 'GET',
    ),
    tokenItem.locator('input[type=checkbox]').click(),
  ])
  assert.equal((await provision()).status, 201)
  const writes = []
  const trackWrites = (request) => {
    if (request.method() !== 'GET' && request.url().includes('/api/admin/integrations'))
      writes.push(request.url())
  }
  admin.on('request', trackWrites)
  await admin.locator('input[name=invitation-mode][value=self_service]').check()
  assert.deepEqual(
    JSON.parse(await admin.locator('.provisioning-request-example code').innerText()),
    { sendInvitation: false },
  )
  await admin.locator('input[name=invitation-mode][value=immediate]').check()
  assert.deepEqual(
    JSON.parse(await admin.locator('.provisioning-request-example code').innerText()),
    { sendInvitation: true },
  )
  assert.deepEqual(writes, [])
  admin.off('request', trackWrites)
  await capture(admin, 'tokens-en-desktop')
  await admin.setViewportSize({ width: 390, height: 844 })
  await admin.locator('input[name=invitation-mode][value=self_service]').check()
  await capture(admin, 'invitation-mode-en-mobile')
  await admin.setViewportSize({ width: 1440, height: 980 })
  results.push({
    test: 'invitation-mode-chooser-retains-sendInvitation-contract-without-settings-writes',
    passed: true,
  })
  await admin.getByRole('tab', { name: 'Recent operations' }).click()
  await admin.locator('#integration-panel-operations .integration-item').first().waitFor()
  await capture(admin, 'operations-en-desktop')
  await admin.setViewportSize({ width: 390, height: 844 })
  await capture(admin, 'operations-en-mobile')
  const history = await call(owner, '/api/admin/integrations/operations')
  assert.ok(history.data.some((row) => row.outcome === 'error'))
  assert.ok(history.data.some((row) => row.outcome === 'replayed'))
  assert.ok(history.data.some((row) => row.invitation === 'failed'))
  await admin.getByRole('tab', { name: 'Webhooks', exact: true }).click()
  await admin.locator('#hook-name').waitFor()
  results.push({
    test: 'owner-ui-toggle-history-webhook-preservation-mobile-and-four-locales',
    passed: true,
  })
  await call(owner, '/api/admin/integrations/tokens/' + fixture.token.id, 'DELETE')
  assert.equal((await provision()).status, 401)
  assert.equal((await provision(body, 'invalid', { secret: 'invalid' })).status, 401)
  const finalState = state()
  assert.ok(
    finalState.events.some(
      (event) =>
        event.type === 'project.created' &&
        event.changes.projectUrl === created.body.data.projectUrl,
    ),
  )
  assert.ok(
    finalState.events.some(
      (event) => event.type === 'project.invitation_changed' && event.changes.invitation === 'sent',
    ),
  )
  assert.ok(
    finalState.events.some(
      (event) =>
        event.type === 'project.invitation_changed' && event.changes.invitation === 'failed',
    ),
  )
  const logs = await readFile(path.join(app, 'storage/logs/laravel.log'), 'utf8').catch(() => '')
  const secretValues = [
    fixture.token.secret,
    fixture.otherToken.secret,
    ...messages.map((_, i) => mailLink(i).split('/access/')[1]),
  ]
  for (const value of secretValues) {
    assert.equal(logs.includes(value), false)
    assert.equal(JSON.stringify(finalState.events).includes(value), false)
    assert.equal(JSON.stringify(history).includes(value), false)
  }
  assert.equal(JSON.stringify(finalState.events).includes('/access/'), false)
  assert.equal(errors.length, 0, errors.join('\n'))
  results.push({
    test: 'webhook-recovery-payload-and-log-response-history-secret-scan',
    passed: true,
  })
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
        liveJino: false,
        productionBuild,
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
