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
const output = process.env.M7_EVIDENCE_DIRECTORY || path.join(tmpdir(), 'project-brief-m7-evidence')
await mkdir(output, { recursive: true })
const transportOrigin = 'https://localhost:5199'
const origin =
  process.env.M7_TEST_PRODUCTION_ORIGIN === '1' ? 'https://brief.web86.site' : transportOrigin
const env = {
  ...process.env,
  APP_ENV: 'local',
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
await writeFile(env.DB_DATABASE, '')
php(['artisan', 'migrate', '--force', '--no-interaction'])
const fixture = php(['tests/account-security-fixture.php'], true)
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
    https: { key: await readFile(key), cert: await readFile(cert) },
    proxy: { '/api': 'http://127.0.0.1:8099', '/access/': 'http://127.0.0.1:8099' },
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
    viewport: { width: 1440, height: 1050 },
    locale: 'en-GB',
  })
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
  await context.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  const page = await context.newPage()
  activePage = page
  page.on('response', (response) => {
    if (response.url().includes('/api/'))
      apiResponses.push([new URL(response.url()).pathname, response.status()])
  })
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  const cdp = await context.newCDPSession(page)
  await cdp.send('WebAuthn.enable')
  await cdp.send('WebAuthn.addVirtualAuthenticator', {
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
  await page.getByLabel('Device name', { exact: true }).fill('Virtual authenticator')
  await page.evaluate(() => {
    globalThis.m7OriginalCreate = navigator.credentials.create
    navigator.credentials.create = () =>
      Promise.reject(new DOMException('User cancelled', 'NotAllowedError'))
  })
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  await page
    .locator('form:has(#passkey-name) [role="alert"]')
    .filter({ hasText: 'cancelled or timed out' })
    .waitFor()
  await page.locator('.toast-error').filter({ hasText: 'cancelled or timed out' }).waitFor()
  assert.equal((await request('/api/security/status')).body.state, 'enrollment_required')
  assert.equal((await request('/api/security/status')).body.passkeys.length, 0)
  await page.evaluate(() => {
    navigator.credentials.create = globalThis.m7OriginalCreate
    delete globalThis.m7OriginalCreate
  })
  results.push('Cancelled WebAuthn returns to restricted setup and permits retry')
  // The authenticator creates a credential but an expired server challenge prevents storage.
  // Retrying must create a new challenge and must not reuse the accepted enrollment TOTP.
  let rejectOnce = true
  await page.route('**/api/security/passkey/register', async (route) => {
    if (!rejectOnce) return route.fallback()
    rejectOnce = false
    await page.evaluate(async () => {
      const { api } = await import('/src/api/client.js')
      await api.request('/api/security/passkey/register/options')
    })
    await route.fallback()
  })
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  await page
    .locator('form:has(#passkey-name) [role="alert"]')
    .filter({ hasText: 'PK_CHALLENGE_MISMATCH' })
    .waitFor()
  await page.locator('.toast-error').filter({ hasText: 'expired or was replaced' }).waitFor()
  const afterFailure = (await request('/api/security/status')).body
  assert.equal(afterFailure.state, 'enrollment_required')
  assert.equal(afterFailure.passkeys.length, 0)
  assert.equal(afterFailure.fresh, true)
  assert.equal(afterFailure.totp, true)
  assert.equal(afterFailure.recoverySaved, true)
  await page.unroute('**/api/security/passkey/register')
  results.push(
    'Created but unsaved credential: real challenge rejection, toast + form reference, restricted state and safe fresh retry',
  )
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  await page.getByRole('button', { name: 'Continue to workspace', exact: true }).waitFor()
  await page.locator('.toast-success').filter({ hasText: 'Passkey registered.' }).waitFor()
  assert.equal((await request('/api/admin/projects')).status, 200)
  results.push(
    'Owner password: restricted enrollment, reload, real TOTP, saved recovery, real UV Passkey, unlocked workspace',
  )
  for (const language of LANGUAGE_CATALOG) {
    for (const [width, scheme] of [
      [1440, 'light'],
      [390, 'dark'],
    ]) {
      await page.setViewportSize({ width, height: 1050 })
      await page.emulateMedia({ colorScheme: scheme })
      await page.evaluate(
        ({ lang, scheme }) => {
          localStorage.setItem('projectbrief.locale', lang)
          localStorage.setItem('projectbrief.theme', scheme)
        },
        { lang: language.code, scheme },
      )
      await page.reload()
      await page
        .getByRole('heading', { name: language.messages.security.passkeys, exact: true })
        .waitFor()
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
        `overflow ${language.code} ${width}`,
      )
      await page.screenshot({
        path: path.join(output, `${language.code}-${width}-${scheme}.png`),
        fullPage: true,
      })
    }
  }
  results.push('RU/EN/TR/ZH-CN security UI: 1440px light + 390px dark; no horizontal overflow')
  await page.evaluate(() => {
    localStorage.setItem('projectbrief.locale', 'en')
    localStorage.setItem('projectbrief.theme', 'system')
  })
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.reload()
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark')
  await page.emulateMedia({ colorScheme: 'light' })
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'light')
  results.push('System theme follows dark/light device changes on the security screen')
  await request('/api/admin/logout', 'POST')
  await page.goto(origin + '/admin/login')
  await page.getByRole('button', { name: 'Sign in with a Passkey', exact: true }).click()
  await page.waitForURL(origin + '/admin')
  assert.equal((await request('/api/security/status')).body.state, 'authenticated')
  results.push('Real discoverable UV Passkey login without password or TOTP')
  await request('/api/admin/logout', 'POST')
  await page.goto(origin + '/admin/login')
  await page.getByLabel('Email', { exact: true }).fill('security-owner@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Owner-test-password!')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await page.waitForURL('**/admin/security')
  await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await page.getByLabel('Recovery code', { exact: true }).fill(recoveryCodes[0])
  await page.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await page.getByRole('button', { name: 'Continue to workspace', exact: true }).waitFor()
  results.push('Password requires recovery challenge before workspace access')
  const csrfResponse = await context.request.post(transportOrigin + '/api/security/totp/setup', {
    headers: { Accept: 'application/json' },
  })
  assert.equal(csrfResponse.status(), 419)
  assert.equal((await csrfResponse.json()).code, 'SEC_CSRF_EXPIRED')
  results.push('CSRF enforced on security mutation over real HTTP')
  await request('/api/admin/logout', 'POST')
  await page.goto(origin + '/admin/login')
  await page.getByLabel('Email', { exact: true }).fill('security-developer@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Developer-test-password!')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await page.waitForURL(origin + '/admin')
  assert.equal((await request('/api/security/status')).body.totp, false)
  results.push('Developer password remains usable without TOTP or Passkey')
  await request('/api/admin/logout', 'POST')
  await page.goto(origin + '/access/' + fixture.clientToken)
  assert.equal((await request('/api/session')).body.type, 'client')
  assert.equal((await request('/api/client/tasks')).status, 200)
  results.push('Existing client link and client task session unchanged')
  assert.deepEqual(errors, [])
  const compatibilityEngines = [['Firefox', firefox]]
  // The bundled mac14-special WebKit rejects PushAPIEnabled before opening a page.
  // Enable its real-engine check on a compatible Playwright host.
  if (process.env.M7_TEST_WEBKIT === '1') compatibilityEngines.push(['WebKit', webkit])
  else
    limitations.push(
      'Real WebKit not run: use M7_TEST_WEBKIT=1 on a compatible host. Safari API shapes covered by frontend tests; physical Touch ID not tested.',
    )
  for (const [engine, browserType] of compatibilityEngines) {
    const compatibilityBrowser = await browserType.launch({ headless: true })
    try {
      const compatibility = await compatibilityBrowser.newContext({ ignoreHTTPSErrors: true })
      await compatibility.route('**/*', async (route) => {
        const url = new URL(route.request().url())
        if (url.origin !== origin) return route.abort()
        const response = await route.fetch({
          url: transportOrigin + url.pathname + url.search,
          maxRedirects: 0,
        })
        await route.fulfill({ response })
      })
      const compatibilityPage = await compatibility.newPage()
      await compatibilityPage.goto(origin + '/admin/login')
      const serialized = await compatibilityPage.evaluate(async () => {
        const { createPasskeyClient } = await import('/src/services/accountSecurity.js')
        const bytes = (...values) => new Uint8Array(values).buffer
        Object.defineProperty(navigator, 'credentials', {
          configurable: true,
          value: {
            async create({ publicKey }) {
              if (
                !(publicKey.challenge instanceof ArrayBuffer) ||
                publicKey.authenticatorSelection.userVerification !== 'required'
              )
                throw Error('Invalid binary options or UV')
              return {
                id: '-_8B',
                rawId: bytes(251, 255, 1),
                type: 'public-key',
                response: { attestationObject: bytes(251, 255, 1), clientDataJSON: bytes(2, 3, 4) },
                getClientExtensionResults: () => ({}),
              }
            },
          },
        })
        const ceremony = createPasskeyClient({
          client: {
            resetCsrf() {},
            async request(url, input) {
              return input
                ? JSON.parse(JSON.stringify(input.body))
                : {
                    options: {
                      challenge: 'AQID',
                      rp: { id: location.hostname, name: 'Project Brief' },
                      user: { id: 'BAUG', name: 'owner', displayName: 'Owner' },
                      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
                      authenticatorSelection: {
                        userVerification: 'required',
                        residentKey: 'required',
                      },
                    },
                  }
            },
          },
        })
        return ceremony('register', 'Compatibility')
      })
      assert.equal(serialized.credential.rawId, '-_8B')
      assert.equal(serialized.credential.response.attestationObject, '-_8B')
      assert.equal(serialized.credential.response.clientDataJSON, 'AgME')
      results.push(
        `${engine}: real engine serializer compatibility with minimal WebAuthn API shape (mock authenticator; no hardware assertion)`,
      )
    } finally {
      await compatibilityBrowser.close()
    }
  }
  await writeFile(
    path.join(output, 'browser-results.json'),
    JSON.stringify(
      {
        origin,
        rpId: env.PASSKEY_RP_ID,
        isolatedLocalTransport: true,
        results,
        errors,
        limitations,
      },
      null,
      2,
    ),
  )
  console.log(JSON.stringify({ passed: results.length, results, limitations }))
} catch (error) {
  await writeFile(path.join(output, 'server.log'), serverLog)
  await activePage
    ?.screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
    .catch(() => {})
  console.error(
    JSON.stringify({
      apiResponses,
      alerts: await activePage?.getByRole('alert').allTextContents(),
    }),
  )
  throw error
} finally {
  await browser?.close()
  await vite.close()
  backend.kill('SIGTERM')
  await rm(temporary, { recursive: true, force: true })
}
