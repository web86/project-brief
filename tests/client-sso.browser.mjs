import https from 'node:https'
import http from 'node:http'
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes, createHash } from 'node:crypto'
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
const productionBuild = true
const output = path.join(
  root,
  process.env.M93_EVIDENCE_DIRECTORY || 'artifacts/milestone-9-2/sso',
  mysql ? 'mysql' : 'sqlite',
)
await mkdir(app)
await mkdir(output, { recursive: true })
let backend, otherBackend, frontend, browser, smtp, tlsServer, areaServer, areaBackend
const protocol = []
let areaErrors = ''
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
const tlsPort = await port(),
  areaPort = await port(),
  areaTlsPort = await port()
const origin = `https://127.0.0.1:${tlsPort}`,
  areaOrigin = `https://localhost:${areaTlsPort}`
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
  SESSION_SECURE_COOKIE: 'true',
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
  return JSON.parse(php(['tests/client-sso-fixture.php', 'state']))
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
    ignoreHTTPSErrors: true,
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
    path.join(root, 'api/tests/client-sso-fixture.php'),
    path.join(app, 'tests/client-sso-fixture.php'),
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
    (name) => name >= '2026_10_29_174316_add_client_sso_handoffs.php',
  )
  const deferredSources = []
  for (const name of deferredMigrations) {
    const file = path.join(app, 'database/migrations', name)
    deferredSources.push([file, await readFile(file)])
    await rm(file)
  }
  if (mysql) php(['artisan', 'db:wipe', '--force', '--no-interaction'])
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const legacy = JSON.parse(php(['tests/client-sso-fixture.php', 'legacy']))
  for (const [file, source] of deferredSources) await writeFile(file, source)
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/client-sso-fixture.php']))
  assert.equal(state().projects, 1)
  results.push({
    test: 'upgrade-from-082-retains-existing-project-and-client',
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
            '/client-sso': { target: `http://127.0.0.1:${apiPort}` },
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
            '/client-sso': { target: `http://127.0.0.1:${apiPort}` },
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
  const keyFile = path.join(scratch, 'local.key'),
    certFile = path.join(scratch, 'local.crt')
  const certificate = spawnSync(
    'openssl',
    [
      'req',
      '-x509',
      '-newkey',
      'rsa:2048',
      '-nodes',
      '-keyout',
      keyFile,
      '-out',
      certFile,
      '-days',
      '2',
      '-subj',
      '/CN=localhost',
      '-addext',
      'subjectAltName=DNS:localhost,IP:127.0.0.1',
    ],
    { encoding: 'utf8' },
  )
  assert.equal(certificate.status, 0, 'Local TLS certificate creation failed')
  const tls = { key: await readFile(keyFile), cert: await readFile(certFile) }
  function proxy(target, request, response) {
    const forwarded = http.request(
      {
        hostname: '127.0.0.1',
        port: target,
        path: request.url,
        method: request.method,
        headers: request.headers,
      },
      (upstream) => {
        response.writeHead(upstream.statusCode, upstream.headers)
        upstream.pipe(response)
      },
    )
    forwarded.on('error', () => {
      response.writeHead(502)
      response.end()
    })
    request.pipe(forwarded)
  }
  tlsServer = https.createServer(tls, (request, response) =>
    proxy(request.headers['x-qa-worker'] === '2' ? otherPort : webPort, request, response),
  )
  await new Promise((resolve) => tlsServer.listen(tlsPort, '127.0.0.1', resolve))
  browser = await chromium.launch({ headless: true })
  const httpApi = await browser.newContext({ ignoreHTTPSErrors: true })
  async function v1(url, method = 'GET', body, token = fixture.token, key = 'sso-qa') {
    return httpApi.request.fetch(origin + '/api/v1' + url, {
      method,
      headers: {
        Accept: 'application/json',
        Authorization: 'Bearer ' + token.secret,
        'Idempotency-Key': key,
      },
      ...(body !== undefined ? { data: body } : {}),
    })
  }
  const input = {
    externalId: 'sso-existing-project',
    client: { name: 'Ada Client', email: 'ada@example.test' },
    project: {
      name: 'Trusted handoff project',
      description: 'Existing project created through provisioning',
      language: 'en',
    },
    sendInvitation: false,
  }
  const provision = await v1('/projects/provision', 'POST', input)
  assert.equal(provision.status(), 201)
  const project = (await provision.json()).data
  const requestInput = {
    externalId: 'sso-approved-request',
    client: input.client,
    project: { ...input.project, name: 'Approved request handoff project' },
  }
  const requested = await v1(
    '/project-requests',
    'POST',
    requestInput,
    fixture.token,
    'sso-request',
  )
  assert.equal(requested.status(), 201)
  const requestId = (await requested.json()).data.requestId
  const approved = await v1(
    '/project-requests/' + requestId + '/approve',
    'POST',
    { sendInvitation: false },
    fixture.token,
    'sso-approve',
  )
  assert.equal(approved.status(), 200)
  const secondProject = (await approved.json()).data
  const codes = []
  async function issue(extra = {}, token = fixture.externalToken) {
    const response = await v1(
      '/client-sso/handoffs',
      'POST',
      {
        projectId: project.projectId,
        externalUserId: 'user-42',
        email: 'ada@example.test',
        emailVerified: true,
        ...extra,
      },
      token,
    )
    if (response.status() === 201) {
      const result = (await response.json()).data
      codes.push(result.code)
      return { status: 201, data: result }
    }
    return { status: response.status() }
  }
  assert.equal((await issue()).status, 403, 'Sources start disabled')
  const owner = await context(),
    admin = await owner.newPage()
  await admin.goto(origin + '/admin/login')
  await admin.locator('#admin-email').fill('owner@example.test')
  await admin.locator('#admin-password').fill('Local-sso-test!')
  await admin.locator('form button[type=submit]').click()
  await admin.waitForURL('**/admin/security')
  await call(owner, '/api/security/challenge', 'POST', { recovery_code: 'Sso-browser-recovery' })
  async function settings(enabled = true) {
    return call(owner, '/api/admin/integrations/sources/' + fixture.source + '/sso', 'PATCH', {
      ssoEnabled: enabled,
      ssoOrigin: areaOrigin,
      projectIds: [],
    })
  }
  await admin.goto(origin + '/admin/settings/integrations')
  await admin.getByRole('tab', { name: 'SSO', exact: true }).click()
  await admin
    .locator('.sso-source-card')
    .filter({
      hasText: (await call(admin.context(), '/api/admin/integrations')).sources.find(
        (source) => source.id === fixture.source,
      ).name,
    })
    .locator('.sso-configure')
    .click()
  await admin.locator('#sso-origin').fill(areaOrigin)
  await admin.locator('#sso-enabled input').check()
  await admin.locator('.sso-source-dialog .sso-projects > summary').click()
  await admin.locator('.sso-automatic').waitFor()
  assert.match(await admin.locator('.sso-automatic').innerText(), /2/)
  for (const id of [project.projectId, secondProject.projectId])
    assert.equal(await admin.locator('.sso-projects input[value="' + id + '"]').count(), 0)
  const saved = await Promise.all([
    admin.waitForResponse(
      (response) =>
        response.url().endsWith('/sources/' + fixture.source + '/sso') &&
        response.request().method() === 'PATCH',
    ),
    admin.locator('.sso-source-dialog button[form="sso-source-editor"]').click(),
  ])
  assert.equal(saved[0].status(), 200, 'Owner saves source permissions through the existing UI')
  await admin.getByRole('tab', { name: 'API tokens', exact: true }).click()
  await admin.getByRole('button', { name: 'Create a new API token', exact: true }).click()
  const tokenForm = admin.locator('form').filter({ has: admin.locator('#token-name') })
  await admin.locator('#token-name').fill('Provision only from UI')
  for (const scope of ['projects:read', 'tasks:read'])
    await tokenForm.locator('input[value="' + scope + '"]').uncheck()
  await tokenForm.locator('input[value="projects:provision"]').check()
  await admin.locator('#token-source').selectOption(fixture.source)
  const createdProvisionToken = await Promise.all([
    admin.waitForResponse(
      (response) =>
        response.url().endsWith('/api/admin/integrations/tokens') &&
        response.request().method() === 'POST',
    ),
    tokenForm.locator('button[type=submit]').click(),
  ])
  assert.equal(createdProvisionToken[0].status(), 201)
  let provisionOnly = await createdProvisionToken[0].json()
  await admin
    .locator('.integration-secret')
    .getByRole('button', { name: 'Close', exact: true })
    .click()
  const issued = (await call(owner, '/api/admin/integrations')).tokens.find(
    (token) => token.id === provisionOnly.id,
  )
  assert.deepEqual(issued.scopes, ['projects:provision'])
  assert.equal(issued.sourceId, fixture.source)
  const configFile = path.join(scratch, 'external-config.json')
  const externalConfig = {
    origin,
    token: fixture.externalToken.secret,
    projectId: project.projectId,
    helper: path.join(root, 'docs/milestone-8-2/examples/ProjectBriefClientSso.php'),
    ca: certFile,
  }
  async function writeExternalConfig() {
    await writeFile(configFile, JSON.stringify(externalConfig), { mode: 0o600 })
  }
  await writeExternalConfig()
  areaBackend = spawn(
    'php',
    [
      '-d',
      'opcache.enable=0',
      '-S',
      `127.0.0.1:${areaPort}`,
      path.join(root, 'tests/client-sso-external.php'),
    ],
    {
      env: { ...env, PB_SSO_QA: '1', PB_SSO_QA_CONFIG: configFile },
      stdio: ['ignore', 'ignore', 'pipe'],
    },
  )
  areaBackend.stderr.on('data', (chunk) => {
    areaErrors += chunk.toString()
  })
  areaServer = https.createServer(tls, (request, response) => proxy(areaPort, request, response))
  await new Promise((resolve) => areaServer.listen(areaTlsPort, '127.0.0.1', resolve))
  for (let attempt = 0; attempt < 100; attempt++) {
    try {
      if ((await httpApi.request.get(areaOrigin + '/mock-login')).status() === 200) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 50))
  }
  results.push({
    test: 'existing-provisioning-and-approved-request-source-explicit-project-permissions',
    passed: true,
  })
  const urls = [],
    posts = []
  function observe(page) {
    page.on('response', (response) => {
      if (response.url().includes('/client-sso/'))
        protocol.push({
          path: new URL(response.url()).pathname,
          status: response.status(),
          location: response.headers().location,
        })
    })
    page.on('request', async (request) => {
      urls.push(request.url())
      if (request.url() === origin + '/client-sso/enter') {
        const code = new URLSearchParams(request.postData() || '').get('code')
        if (code) codes.push(code)
        const allHeaders = await request.allHeaders()
        protocol.push({
          path: '/client-sso/enter',
          origin: allHeaders.origin,
          hasSessionCookie: (allHeaders.cookie || '').includes(env.SESSION_COOKIE),
        })
        posts.push({
          url: request.url(),
          cookie: allHeaders.cookie || '',
          origin: allHeaders.origin || '',
        })
      }
    })
  }
  const client = await context(),
    page = await client.newPage()
  observe(page)
  const accountResponse = await page.goto(areaOrigin + '/mock-login')
  assert.equal(
    accountResponse.status(),
    200,
    'External account: ' + (await page.locator('body').innerText()),
  )
  assert.equal((await page.content()).includes(fixture.externalToken.secret), false)
  await page.getByRole('button', { name: 'Open project', exact: true }).click()
  await page.waitForURL((url) => url.pathname === '/project/' + project.projectId)
  await page.getByRole('heading', { name: input.project.name, exact: true }).waitFor()
  assert.equal(await page.locator('#client-login-email').count(), 0)
  assert.ok(
    (await client.cookies(origin)).some(
      (cookie) =>
        cookie.name === env.SESSION_COOKIE &&
        cookie.secure &&
        cookie.httpOnly &&
        cookie.sameSite === 'Lax',
    ),
  )
  assert.equal(
    (await client.cookies()).some((cookie) => cookie.name === 'projectbrief_sso_handoff'),
    false,
  )
  assert.equal(posts[0].origin, areaOrigin)
  await capture(page, 'client-sso-success-en-desktop-light')
  const firstCode = codes.at(-1)
  const browserState = await page.evaluate(() =>
    JSON.stringify({
      local: { ...localStorage },
      session: { ...sessionStorage },
      history: history.state,
    }),
  )
  for (const code of codes) assert.equal(browserState.includes(code), false)
  assert.equal(state().bindings, 1)
  const afterSignIn = state()
  assert.equal(afterSignIn.access, 1)
  const replay = await client.request.post(origin + '/client-sso/enter', {
    headers: { Origin: areaOrigin },
    form: { code: firstCode },
    maxRedirects: 0,
  })
  assert.equal(replay.status(), 303, 'Used code cannot enter again')
  assert.equal(
    (await call(client, '/api/client/project/' + project.projectId)).data.id,
    project.projectId,
  )
  results.push({
    test: 'real-external-php-curl-cross-site-post-to-own-client-session-without-email',
    passed: true,
  })
  externalConfig.projectId = secondProject.projectId
  await writeExternalConfig()
  await page.goto(areaOrigin + '/account')
  await page.getByRole('button', { name: 'Open project', exact: true }).click()
  await page.waitForURL((url) => url.pathname === '/project/' + secondProject.projectId)
  assert.equal(
    (await call(client, '/api/client/project/' + secondProject.projectId)).data.id,
    secondProject.projectId,
  )
  assert.equal(state().bindings, 1, 'Same pinned identity opens another explicitly allowed project')
  externalConfig.projectId = project.projectId
  await writeExternalConfig()
  const parallel = await issue()
  assert.equal(parallel.status, 201)
  const pair = await Promise.all([context(), context()])
  for (const ctx of pair) {
    const bridge = await ctx.request.post(origin + '/client-sso/enter', {
      headers: { Origin: areaOrigin },
      form: { code: parallel.data.code },
      maxRedirects: 0,
    })
    assert.equal(bridge.status(), 200)
    assert.equal((await bridge.text()).includes(parallel.data.code), false)
  }
  const beforeRace = state().access
  const raced = await Promise.all(
    pair.map((ctx, index) =>
      ctx.request.get(origin + '/client-sso/complete', {
        headers: index ? { 'X-QA-Worker': '2' } : {},
        maxRedirects: 0,
      }),
    ),
  )
  assert.ok(raced.every((response) => response.status() === 303))
  assert.equal(
    raced.filter((response) => response.headers().location === project.projectUrl).length,
    1,
  )
  assert.equal(state().access, beforeRace + 1)
  for (const ctx of pair) await ctx.close()
  results.push({
    test: 'one-code-two-workers-one-atomic-consumption-and-same-identity-project-switch',
    passed: true,
  })
  const expired = await issue()
  assert.equal(expired.status, 201)
  php([
    'tests/client-sso-fixture.php',
    'expire',
    createHash('sha256').update(expired.data.code).digest('hex'),
  ])
  const fallback = await context(),
    fallbackPage = await fallback.newPage()
  observe(fallbackPage)
  const expiredBridge = await fallback.request.post(origin + '/client-sso/enter', {
    headers: { Origin: areaOrigin },
    form: { code: expired.data.code },
    maxRedirects: 0,
  })
  assert.equal(expiredBridge.status(), 303)
  await fallbackPage.goto(expiredBridge.headers().location)
  await fallbackPage.getByRole('link', { name: 'Sign in by email', exact: true }).click()
  await fallbackPage.locator('#client-login-email').fill('ada@example.test')
  await fallbackPage.locator('form button[type=submit]').click()
  await fallbackPage.getByRole('heading', { name: 'Check your email', exact: true }).waitFor()
  assert.ok(messages.length >= 1)
  const magicIndex = messages.findIndex((_, index) => decodedMail(index).includes('/access/'))
  assert.ok(magicIndex >= 0)
  await fallbackPage.goto(mailLink(magicIndex))
  await fallbackPage.waitForURL((url) => url.pathname === '/project/' + project.projectId)
  assert.equal(
    (await call(fallback, '/api/client/project/' + project.projectId)).data.id,
    project.projectId,
  )
  results.push({
    test: 'expired-sso-falls-back-to-real-email-magic-link-with-existing-smtp-flow',
    passed: true,
  })
  php(['artisan', 'cache:clear'])
  const otherInput = {
    ...input,
    externalId: 'other-client',
    client: { name: 'Other Client', email: 'other@example.test' },
    project: { ...input.project, name: 'Other client project' },
  }
  const otherResponse = await v1(
    '/projects/provision',
    'POST',
    otherInput,
    fixture.token,
    'other-client',
  )
  assert.equal(otherResponse.status(), 201)
  const otherProject = (await otherResponse.json()).data
  const magicOther = JSON.parse(
    php(['tests/client-sso-fixture.php', 'magic', otherProject.projectId]),
  )
  const other = await context(),
    otherPage = await other.newPage()
  observe(otherPage)
  await otherPage.goto(magicOther.url)
  await otherPage.waitForURL((url) => url.pathname === '/project/' + otherProject.projectId)
  await otherPage.goto(areaOrigin + '/mock-login')
  const beforePosts = posts.length
  await otherPage.getByRole('button', { name: 'Open project', exact: true }).click()
  await otherPage.waitForURL(
    (url) => url.pathname === '/access-error' && url.searchParams.get('reason') === 'sso',
  )
  assert.equal(
    posts[beforePosts].cookie.includes(env.SESSION_COOKIE),
    false,
    'Explicit Lax cookie is absent on cross-site POST',
  )
  assert.equal(
    (await call(other, '/api/client/project/' + otherProject.projectId)).data.id,
    otherProject.projectId,
    'Other client kept after same-origin completion check',
  )
  await admin.goto(areaOrigin + '/mock-login')
  await admin.getByRole('button', { name: 'Open project', exact: true }).click()
  await admin.waitForURL(
    (url) => url.pathname === '/access-error' && url.searchParams.get('reason') === 'sso',
  )
  assert.equal((await call(owner, '/api/admin/me')).user.email, 'owner@example.test')
  assert.equal(
    (await issue({ email: 'owner@example.test', externalUserId: 'staff-id' })).status,
    403,
  )
  results.push({
    test: 'cross-site-lax-cookie-return-preserves-other-client-and-mfa-owner-without-identity-switch',
    passed: true,
  })
  assert.equal((await issue({ externalUserId: 'changed-id' })).status, 409)
  php(['tests/client-sso-fixture.php', 'email', project.projectId, 'changed@example.test'])
  assert.equal((await issue({ email: 'changed@example.test' })).status, 409)
  php(['tests/client-sso-fixture.php', 'email', project.projectId, 'ada@example.test'])
  assert.equal((await issue({ emailVerified: false })).status, 422)
  assert.equal((await issue({}, fixture.otherToken)).status, 404)
  const protectedCode = await issue()
  assert.equal(protectedCode.status, 201)
  const wrongOrigin = await httpApi.request.post(origin + '/client-sso/enter', {
    headers: { Origin: 'https://evil.example.test' },
    form: { code: protectedCode.data.code },
    maxRedirects: 0,
  })
  assert.equal(wrongOrigin.status(), 303)
  const missingOrigin = await httpApi.request.post(origin + '/client-sso/enter', {
    form: { code: protectedCode.data.code },
    maxRedirects: 0,
  })
  assert.equal(missingOrigin.status(), 303)
  const csrf = await owner.request.post(origin + '/api/admin/integrations/tokens', {
    data: { name: 'No CSRF', scopes: ['projects:read'] },
    maxRedirects: 0,
  })
  assert.equal(csrf.status(), 419)
  const externalCsrf = await page.request.post(areaOrigin + '/open', {
    form: { email: 'other@example.test' },
    maxRedirects: 0,
  })
  assert.equal(externalCsrf.status(), 403)
  results.push({
    test: 'identity-pin-origin-csrf-verified-email-and-tenant-boundaries',
    passed: true,
  })
  const beforeRotate = await issue()
  assert.equal(beforeRotate.status, 201)
  const oldToken = fixture.externalToken
  fixture.externalToken = JSON.parse(php(['tests/client-sso-fixture.php', 'rotate', oldToken.id]))
  externalConfig.token = fixture.externalToken.secret
  await writeExternalConfig()
  assert.equal((await issue({}, oldToken)).status, 401)
  const revokedBridge = await httpApi.request.post(origin + '/client-sso/enter', {
    headers: { Origin: areaOrigin },
    form: { code: beforeRotate.data.code },
    maxRedirects: 0,
  })
  assert.equal(revokedBridge.status(), 200)
  const revokedComplete = await httpApi.request.get(origin + '/client-sso/complete', {
    maxRedirects: 0,
  })
  assert.equal(revokedComplete.status(), 303)
  assert.ok(revokedComplete.headers().location.includes('reason=sso'))
  const fresh = await issue()
  assert.equal(fresh.status, 201)
  assert.equal(state().bindings, 1)
  php(['tests/client-sso-fixture.php', 'client', project.projectId, '0'])
  const disabled = await httpApi.request.post(origin + '/client-sso/enter', {
    headers: { Origin: areaOrigin },
    form: { code: fresh.data.code },
    maxRedirects: 0,
  })
  assert.equal(disabled.status(), 200)
  const disabledGet = await httpApi.request.get(origin + '/client-sso/complete', {
    maxRedirects: 0,
  })
  assert.ok(disabledGet.headers().location.includes('reason=sso'))
  php(['tests/client-sso-fixture.php', 'client', project.projectId, '1'])
  results.push({
    test: 'replacement-token-keeps-binding-revoked-token-and-inactive-membership-block-redemption',
    passed: true,
  })
  const noJs = await browser.newContext({ ignoreHTTPSErrors: true, javaScriptEnabled: false }),
    noJsPage = await noJs.newPage()
  observe(noJsPage)
  await noJsPage.goto(areaOrigin + '/mock-login')
  await noJsPage.getByRole('button', { name: 'Open project', exact: true }).click()
  await noJsPage.getByRole('button', { name: 'Continue', exact: true }).click()
  await noJsPage.getByRole('button', { name: 'Continue', exact: true }).click()
  await noJsPage.waitForURL((url) => url.pathname === '/project/' + project.projectId)
  assert.equal(
    (await call(noJs, '/api/client/project/' + project.projectId)).data.id,
    project.projectId,
  )
  await noJs.close()
  results.push({ test: 'no-javascript-continue-buttons-complete-the-protocol', passed: true })
  externalConfig.ordersFile = path.join(scratch, 'orders.json')
  externalConfig.orderHelper = path.join(root, 'docs/milestone-8-2/examples/order-project.php')
  externalConfig.provisionHelper = path.join(root, 'docs/milestone-8-0/provision-project.php')
  externalConfig.provisionToken = provisionOnly.secret
  const orders = [
    {
      id: 901,
      user_id: 'user-42',
      pb_project_id: null,
      provision_input: JSON.stringify({
        externalId: 'first-open-901',
        client: input.client,
        project: { ...input.project, name: 'First open project' },
        sendInvitation: false,
      }),
    },
    { id: 902, user_id: 'user-42', pb_project_id: secondProject.projectId },
    { id: 903, user_id: 'someone-else', pb_project_id: project.projectId },
  ]
  await writeFile(externalConfig.ordersFile, JSON.stringify(orders), { mode: 0o600 })
  await writeExternalConfig()
  const ordersContext = await context(),
    ordersPage = await ordersContext.newPage()
  observe(ordersPage)
  const beforeFirstOpen = state().projects
  await ordersPage.goto(areaOrigin + '/mock-login')
  assert.equal(
    await ordersPage.getByRole('button', { name: 'Open order 903', exact: true }).count(),
    0,
  )
  const orderCsrf = await ordersPage.locator('input[name=csrf]').first().inputValue()
  const otherOrder = await ordersContext.request.post(areaOrigin + '/order-open', {
    form: { csrf: orderCsrf, order_id: '903' },
  })
  assert.equal(otherOrder.status(), 403)
  const firstOrderResponse = await Promise.all([
    ordersPage.waitForResponse((response) => response.url() === areaOrigin + '/order-open'),
    ordersPage.getByRole('button', { name: 'Open order 901', exact: true }).click(),
  ])
  assert.equal(firstOrderResponse[0].status(), 200, 'External first order failed: ' + areaErrors)
  await ordersPage.waitForURL((url) => /^\/project\/[a-f0-9-]+$/.test(url.pathname))
  const savedOrders = JSON.parse(await readFile(externalConfig.ordersFile, 'utf8'))
  const firstOpened = savedOrders[0].pb_project_id
  assert.equal(new URL(ordersPage.url()).pathname, '/project/' + firstOpened)
  await ordersPage.getByRole('heading', { name: 'First open project', exact: true }).waitFor()
  assert.equal(state().projects, beforeFirstOpen + 1)
  // Simulate a lost local save followed by credential rotation: the source and
  // immutable external order snapshot must recover the same remote project.
  savedOrders[0].pb_project_id = null
  await writeFile(externalConfig.ordersFile, JSON.stringify(savedOrders), { mode: 0o600 })
  provisionOnly = JSON.parse(php(['tests/client-sso-fixture.php', 'rotate', provisionOnly.id]))
  externalConfig.provisionToken = provisionOnly.secret
  await writeExternalConfig()
  await ordersPage.goto(areaOrigin + '/account')
  await ordersPage.getByRole('button', { name: 'Open order 901', exact: true }).click()
  await ordersPage.waitForURL((url) => url.pathname === '/project/' + firstOpened)
  assert.equal(
    state().projects,
    beforeFirstOpen + 1,
    'Source recovers the same project after a lost save and provisioning-token rotation',
  )
  assert.equal(
    JSON.parse(await readFile(externalConfig.ordersFile, 'utf8'))[0].pb_project_id,
    firstOpened,
  )
  await ordersPage.goto(areaOrigin + '/account')
  await ordersPage.getByRole('button', { name: 'Open order 902', exact: true }).click()
  await ordersPage.waitForURL((url) => url.pathname === '/project/' + secondProject.projectId)
  assert.equal(state().bindings, 1, 'Multiple order projects use one stable client identity')
  await ordersContext.close()
  results.push({
    test: 'owned-orders-first-click-provision-persist-sso-rotated-token-recovery-and-multiple-projects-without-manual-grants',
    passed: true,
  })
  php(['artisan', 'cache:clear'])
  const titles = {
    en: 'Trusted client sign-in (SSO)',
    ru: 'Доверенный вход клиента (SSO)',
    tr: 'Güvenilir müşteri girişi (SSO)',
    'zh-CN': '受信任的客户登录（SSO）',
  }
  for (const [locale, title] of Object.entries(titles)) {
    await call(owner, '/api/admin/me/locale', 'PATCH', { locale })
    await admin.evaluate((locale) => {
      localStorage.setItem('projectbrief.locale', locale)
      localStorage.setItem('projectbrief.theme', 'dark')
    }, locale)
    await admin.setViewportSize({ width: 1440, height: 980 })
    await admin.goto(origin + '/admin/settings/integrations')
    await admin.getByRole('tab', { name: 'SSO', exact: true }).click()
    await admin.getByRole('heading', { name: title, exact: true }).waitFor()
    await admin
      .locator('.sso-source-card')
      .filter({
        hasText: (await call(admin.context(), '/api/admin/integrations')).sources.find(
          (source) => source.id === fixture.source,
        ).name,
      })
      .locator('.sso-configure')
      .click()
    assert.equal(await admin.locator('#sso-enabled input').isChecked(), true)
    await capture(admin, 'sso-settings-' + locale + '-desktop-dark')
    await admin.setViewportSize({ width: 390, height: 844 })
    await capture(admin, 'sso-settings-' + locale + '-mobile-dark')
  }
  await call(owner, '/api/admin/me/locale', 'PATCH', { locale: 'en' })
  await settings(false)
  const lastSso = await fallback.request.get(origin + '/api/client/project/' + project.projectId)
  assert.equal(lastSso.status(), 200, 'Ordinary Magic Link session is unaffected')
  assert.equal((await issue()).status, 403)
  results.push({
    test: 'four-locale-owner-controls-dark-mobile-desktop-source-disable-preserves-magic-link',
    passed: true,
  })
  const finalState = state(),
    logs = await readFile(path.join(app, 'storage/logs/laravel.log'), 'utf8').catch(() => '')
  for (const code of codes) {
    assert.equal(
      urls.some((url) => url.includes(code)),
      false,
    )
    assert.equal(logs.includes(code), false)
    assert.equal(JSON.stringify(finalState.events).includes(code), false)
  }
  for (const token of [
    fixture.token.secret,
    fixture.externalToken.secret,
    oldToken.secret,
    fixture.otherToken.secret,
  ]) {
    assert.equal(logs.includes(token), false)
    assert.equal(
      urls.some((url) => url.includes(token)),
      false,
    )
  }
  assert.equal(errors.length, 0, errors.join('\n'))
  results.push({
    test: 'no-sso-code-or-bearer-in-urls-storage-history-application-logs-webhooks',
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
        productionBuild: true,
        crossSiteHttps: true,
        selfSignedLocalCertificate: true,
        curlVerifiesLocalCa: true,
        sameSite: 'Lax',
        noLiveCms: true,
        liveJino: false,
      },
      null,
      2,
    ) + '\n',
  )
  console.log(
    JSON.stringify({
      passed: results.length,
      screenshots: (await readdir(output)).filter((name) => name.endsWith('.png')).length,
    }),
  )
  await httpApi.close()
  await client.close()
  await fallback.close()
  await other.close()
  await owner.close()
} catch (error) {
  console.error('Protocol:', JSON.stringify(protocol))
  console.error(await readFile(path.join(app, 'storage/logs/laravel.log'), 'utf8').catch(() => ''))
  throw error
} finally {
  await browser?.close()
  if (frontend) await new Promise((resolve) => frontend.httpServer.close(resolve))
  backend?.kill('SIGTERM')
  otherBackend?.kill('SIGTERM')
  areaBackend?.kill('SIGTERM')
  for (const server of [tlsServer, areaServer, smtp])
    if (server) await new Promise((resolve) => server.close(resolve))
  await rm(scratch, { recursive: true, force: true })
}
