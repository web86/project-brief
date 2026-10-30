// Real Laravel cookie/CSRF API and WebAuthn over isolated local HTTPS.
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, readFile, mkdir, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer } from 'vite'
import { LANGUAGE_CATALOG } from '../src/i18n/catalog.js'
const { chromium, firefox, webkit } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const temporary = await mkdtemp(path.join(tmpdir(), 'm7-security-'))
const output = path.resolve(
  process.env.M93_EVIDENCE_DIRECTORY || path.join(root, 'artifacts/milestone-9-2/parallel'),
)
await mkdir(output, { recursive: true })
const transportOrigin = 'https://localhost:5199'
const origin =
  process.env.M7_TEST_PRODUCTION_ORIGIN === '1' ? 'https://brief.web86.site' : transportOrigin
const env = {
  ...process.env,
  APP_ENV: 'local',
  LARAVEL_STORAGE_PATH: path.join(temporary, 'storage'),
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_URL: origin,
  FRONTEND_URL: origin,
  PASSKEY_ORIGIN: origin,
  PASSKEY_RP_ID: new URL(origin).hostname,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(temporary, 'database.sqlite'),
  DB_URL: '',
  CACHE_STORE: 'database',
  SESSION_DRIVER: 'database',
  SESSION_SECURE_COOKIE: 'true',
  SESSION_COOKIE: 'm7_security',
  LOG_CHANNEL: 'stderr',
}
function php(args, json = false) {
  const result = spawnSync(process.env.PHP_BIN || 'php', args, {
    cwd: path.join(root, 'api'),
    env,
    encoding: 'utf8',
  })
  if (result.status !== 0) throw new Error(result.stderr || result.stdout)
  return json ? JSON.parse(result.stdout) : result.stdout
}
for (const directory of [
  'app/private',
  'framework/cache/data',
  'framework/sessions',
  'framework/views',
  'logs',
])
  await mkdir(path.join(env.LARAVEL_STORAGE_PATH, directory), { recursive: true })
await writeFile(env.DB_DATABASE, '')
php(['artisan', 'migrate', '--force', '--no-interaction'])
const fixture = php(['tests/account-security-fixture.php'], true)
php(['tests/account-security-fixture.php', 'platform-owner'])
const cert = path.join(temporary, 'cert.pem'),
  key = path.join(temporary, 'key.pem')
assert.equal(
  spawnSync('openssl', [
    'req',
    '-x509',
    '-newkey',
    'rsa:2048',
    '-nodes',
    '-keyout',
    key,
    '-out',
    cert,
    '-days',
    '1',
    '-subj',
    '/CN=localhost',
    '-addext',
    'subjectAltName=DNS:localhost',
  ]).status,
  0,
)
const backend = spawn(
  process.env.PHP_BIN || 'php',
  ['-S', '127.0.0.1:8099', '-t', 'public', 'public/index.php'],
  { cwd: path.join(root, 'api'), env, stdio: ['ignore', 'ignore', 'pipe'] },
)
let serverLog = ''
backend.stderr.on('data', (chunk) => {
  serverLog += chunk
})
const vite = await createServer({
  server: {
    host: '127.0.0.1',
    port: 5199,
    strictPort: true,
    hmr: { host: 'localhost', clientPort: 5199, protocol: 'wss' },
    https: { key: await readFile(key), cert: await readFile(cert) },
    proxy: {
      '/api': 'http://127.0.0.1:8099',
      '/access/': 'http://127.0.0.1:8099',
      '/branding-assets/': 'http://127.0.0.1:8099',
    },
  },
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  logLevel: 'error',
})
let browser, activePage
const apiResponses = []
const results = []
const limitations = []
try {
  await vite.listen()
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    serviceWorkers: 'block',
    viewport: { width: 1440, height: 1050 },
    locale: 'en-GB',
  })
  // Vite HMR is irrelevant to auth; keep its socket fully inside the test harness.
  await context.routeWebSocket('**/*', (socket) =>
    socket.send(JSON.stringify({ type: 'connected' })),
  )
  if (origin !== transportOrigin) {
    // Browser origin/RP are exact production values; all traffic terminates in disposable local fixtures.
    await context.route('**/*', async (route) => {
      const url = new URL(route.request().url())
      if (url.origin !== origin) return route.abort()
      const response = await route.fetch({
        url: transportOrigin + url.pathname + url.search,
        maxRedirects: 0,
      })
      await route.fulfill({ response })
    })
  }
  await context.addInitScript(() => {
    if (!localStorage.getItem('projectbrief.locale'))
      localStorage.setItem('projectbrief.locale', 'en')
  })
  const page = await context.newPage()
  page.setDefaultTimeout(20000)
  page.setDefaultNavigationTimeout(20000)
  activePage = page
  page.on('response', async (response) => {
    if (response.url().includes('/api/'))
      apiResponses.push([
        new URL(response.url()).pathname,
        response.status(),
        response.url().includes('/passkey/') && !response.ok()
          ? (await response.json().catch(() => ({}))).code
          : null,
      ])
  })
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  const cdp = await context.newCDPSession(page)
  await cdp.send('WebAuthn.enable')
  const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  })
  const request = (url, method = 'GET', body) =>
    page.evaluate(
      async ({ url, method, body }) => {
        const { token } = await (
          await fetch('/api/csrf', { headers: { Accept: 'application/json' } })
        ).json()
        const response = await fetch(url, {
          method,
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
          },
          ...(body ? { body: JSON.stringify(body) } : {}),
        })
        return { status: response.status, body: await response.json() }
      },
      { url, method, body },
    )
  await page.goto(origin + '/admin/login')
  await page.getByLabel('Email', { exact: true }).fill('security-owner@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Owner-test-password!')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await page.waitForURL('**/admin/security')
  assert.equal((await request('/api/admin/projects')).status, 403)
  assert.equal((await request('/api/session')).body.authenticated, false)
  await page.reload()
  await page.getByRole('button', { name: 'Set up authenticator', exact: true }).click()
  await page.locator('#totp-confirm').waitFor()
  await page
    .locator('#totp-confirm')
    .fill(php(['tests/account-security-fixture.php', 'code'], true).code)
  assert.equal(
    await page.locator('form:has(#totp-confirm)').evaluate((form) => form.checkValidity()),
    true,
  )
  assert.equal(
    await page.getByRole('button', { name: 'Confirm identity', exact: true }).getAttribute('type'),
    'submit',
  )
  await page.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await page.locator('.security-codes code').first().waitFor()
  const recoveryCodes = await page.locator('.security-codes code').allTextContents()
  assert.equal(recoveryCodes.length, 8)
  await page.getByRole('button', { name: 'I have saved my recovery codes', exact: true }).click()
  assert.equal((await request('/api/security/status')).body.state, 'authenticated')
  assert.equal((await request('/api/security/status')).body.fresh, false)
  assert.equal((await request('/api/admin/projects')).status, 200)
  await page.getByRole('button', { name: 'Later — continue to workspace', exact: true }).waitFor()
  results.push('Owner with saved TOTP recovery has full access without a Passkey')
  await request('/api/security/confirm', 'POST', { recovery_code: recoveryCodes[7] })
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  await page.getByLabel('Device name', { exact: true }).fill('Parallel tab authenticator')
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  await page.getByRole('button', { name: 'Continue to workspace', exact: true }).waitFor()
  const readyA = page.waitForResponse(
    (r) => r.url().endsWith('/api/security/passkey/confirm/options') && r.status() === 200,
  )
  await page.goto(origin + '/admin/settings/integrations')
  await readyA
  await page.evaluate(
    () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
  )
  await page.locator('#token-name').fill('Tab A API key')
  let release, arrive, firstBody
  const gate = new Promise((resolve) => (release = resolve)),
    arrived = new Promise((resolve) => (arrive = resolve))
  await page.route('**/api/security/passkey/confirm', async (route) => {
    if (route.request().method() !== 'POST' || firstBody) return route.fallback()
    firstBody = route.request().postDataJSON()
    arrive()
    await gate
    await route.fallback()
  })
  const confirmedA = page.waitForResponse(
    (r) => r.url().endsWith('/api/security/passkey/confirm') && r.request().method() === 'POST',
  )
  await page.locator('#create-tokens button[type=submit]').dblclick()
  await arrived
  const second = await context.newPage()
  second.on('pageerror', (e) => errors.push(e.message))
  const cdpB = await context.newCDPSession(second)
  await cdpB.send('WebAuthn.enable')
  const deviceB = await cdpB.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  })
  let attemptB
  second.on('response', async (response) => {
    if (
      response.url().endsWith('/api/security/passkey/confirm/options') &&
      response.status() === 200
    )
      attemptB = (await response.json()).attemptId
  })
  const preparedB = second.waitForResponse(
    (r) => r.url().endsWith('/api/security/passkey/confirm/options') && r.status() === 200,
  )
  await second.goto(origin + '/platform')
  await preparedB
  const confirmationB = second.locator('dialog[aria-labelledby="security-step-up-title"]')
  await confirmationB.getByRole('button', { name: 'Retry with Passkey', exact: true }).waitFor()
  assert.ok(firstBody.attemptId)
  assert.notEqual(firstBody.attemptId, attemptB)
  release()
  assert.equal((await confirmedA).status(), 200)
  await page.locator('.integration-secret').waitFor({ state: 'visible' })
  await page
    .locator('.integration-secret')
    .getByRole('button', { name: 'Close', exact: true })
    .click()
  const credentials = (await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials
  for (const credential of credentials)
    await cdpB.send('WebAuthn.addCredential', {
      authenticatorId: deviceB.authenticatorId,
      credential,
    })
  const confirmedB = second.waitForResponse(
    (r) => r.url().endsWith('/api/security/passkey/confirm') && r.request().method() === 'POST',
  )
  await confirmationB.getByRole('button', { name: 'Retry with Passkey', exact: true }).click()
  assert.equal((await confirmedB).status(), 200)
  await confirmationB.waitFor({ state: 'hidden' })
  await second.locator('.platform-block').first().waitFor()
  assert.equal(
    (await request('/api/admin/integrations')).body.tokens.filter((t) => t.name === 'Tab A API key')
      .length,
    1,
  )
  const replay = await request('/api/security/passkey/confirm', 'POST', firstBody)
  assert.equal(replay.status, 422)
  assert.equal(replay.body.code, 'PK_CHALLENGE_INVALID')
  results.push(
    'Shared-session two tabs: signed API-key attempt A held while /platform prepares B; A and B both verify after session rotation; one token, distinct attempts, replay denied',
  )
  const latest = (
    await cdpB.send('WebAuthn.getCredentials', { authenticatorId: deviceB.authenticatorId })
  ).credentials
  for (const credential of latest) {
    await cdp.send('WebAuthn.removeCredential', {
      authenticatorId,
      credentialId: credential.credentialId,
    })
    await cdp.send('WebAuthn.addCredential', { authenticatorId, credential })
  }
  await second.close()
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  await page.unroute('**/api/security/passkey/confirm')
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  php(['tests/account-security-fixture.php', 'expire-proof'])
  await page.evaluate(async () => {
    const { stepUp } = await import('/src/services/stepUp.js')
    stepUp.invalidate()
    await stepUp.prepare()
  })
  php(['tests/account-security-fixture.php', 'expire-confirm'])
  await page.getByRole('button', { name: 'Create a new API token', exact: true }).click()
  await page.locator('#token-name').fill('Expired attempt retry')
  const expired = page.waitForResponse(
    (r) => r.url().endsWith('/api/security/passkey/confirm') && r.status() === 422,
  )
  await page.locator('#create-tokens button[type=submit]').click()
  assert.equal((await (await expired).json()).code, 'PK_CHALLENGE_EXPIRED')
  const retry = page.locator('dialog[aria-labelledby="security-step-up-title"]')
  await retry.getByRole('button', { name: 'Retry with Passkey', exact: true }).waitFor()
  await retry.getByRole('button', { name: 'Retry with Passkey', exact: true }).click()
  await page.locator('.integration-secret').waitFor({ state: 'visible' })
  await page
    .locator('.integration-secret')
    .getByRole('button', { name: 'Close', exact: true })
    .click()
  assert.equal(
    (await request('/api/admin/integrations')).body.tokens.filter(
      (t) => t.name === 'Expired attempt retry',
    ).length,
    1,
  )
  results.push(
    'Expired signed attempt is rejected; new challenge retry succeeds without duplicate API-key creation',
  )
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  php(['tests/account-security-fixture.php', 'expire-proof'])
  await page.evaluate(async () => {
    const { stepUp } = await import('/src/services/stepUp.js')
    stepUp.invalidate()
    await stepUp.prepare()
    globalThis.parallelNativeGet = navigator.credentials.get
    navigator.credentials.get = () =>
      Promise.reject(new DOMException('Cancelled', 'NotAllowedError'))
  })
  await page.getByRole('button', { name: 'Create a new API token', exact: true }).click()
  await page.locator('#token-name').fill('Cancelled draft')
  await page.locator('#create-tokens button[type=submit]').click()
  await retry.getByRole('button', { name: 'Cancel', exact: true }).click()
  assert.equal(await page.locator('#token-name').inputValue(), 'Cancelled draft')
  assert.equal(
    (await request('/api/admin/integrations')).body.tokens.some(
      (t) => t.name === 'Cancelled draft',
    ),
    false,
  )
  await page.evaluate(() => {
    navigator.credentials.get = () =>
      Promise.reject(new DOMException('Unavailable', 'NotSupportedError'))
  })
  await page.locator('#create-tokens button[type=submit]').click()
  await retry.getByRole('button', { name: 'Another confirmation method', exact: true }).click()
  assert.equal(await retry.locator('input[type=password]').count(), 0)
  await new Promise((resolve) => setTimeout(resolve, 31000 - (Date.now() % 30000)))
  await retry
    .locator('#security-code')
    .fill(php(['tests/account-security-fixture.php', 'code'], true).code)
  await retry.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await page.locator('.integration-secret').waitFor({ state: 'visible' })
  await page
    .locator('.integration-secret')
    .getByRole('button', { name: 'Close', exact: true })
    .click()
  assert.equal(
    (await request('/api/admin/integrations')).body.tokens.filter(
      (t) => t.name === 'Cancelled draft',
    ).length,
    1,
  )
  results.push(
    'Cancelled native Passkey preserves draft and creates no key; allowed TOTP fallback confirms once without owner password alternative',
  )
  assert.deepEqual(errors, [])
  await page.screenshot({
    path: path.join(output, 'integrations-after-parallel.png'),
    fullPage: true,
  })
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify(
      {
        passed: true,
        sharedBrowserSession: true,
        realVirtualUvSignatures: true,
        distinctAttemptIds: true,
        sourceTabs: ['/admin/settings/integrations', '/platform'],
        results,
        errors,
      },
      null,
      2,
    ) + '\n',
  )
  await rm(path.join(output, 'failure.json'), { force: true })
  await rm(path.join(output, 'failure.png'), { force: true })
  console.log(JSON.stringify({ passed: results.length, errors: errors.length }))
} catch (error) {
  await writeFile(
    path.join(output, 'failure.json'),
    JSON.stringify({ error: String(error), apiResponses }, null, 2),
  )
  await activePage
    ?.screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
    .catch(() => {})
  throw error
} finally {
  await browser?.close()
  await vite.close()
  backend.kill('SIGTERM')
  await rm(temporary, { recursive: true, force: true })
}
