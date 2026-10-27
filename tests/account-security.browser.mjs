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
    hmr: { host: 'localhost', clientPort: 5199, protocol: 'wss' },
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
  assert.equal((await request('/api/security/status')).body.state, 'authenticated')
  assert.equal((await request('/api/security/status')).body.fresh, false)
  assert.equal((await request('/api/admin/projects')).status, 200)
  await page.getByRole('button', { name: 'Later — continue to workspace', exact: true }).waitFor()
  results.push('Owner with saved TOTP recovery has full access without a Passkey')
  await page.evaluate(() => {
    globalThis.m7OriginalCreate = navigator.credentials.create
    navigator.credentials.create = () =>
      Promise.reject(new DOMException('User cancelled', 'NotAllowedError'))
  })
  await page.getByLabel('Device name', { exact: true }).fill('Virtual authenticator')
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  const stepUp = page.getByRole('dialog')
  await stepUp.waitFor()
  await stepUp.getByRole('button', { name: 'Use a recovery code', exact: true }).waitFor()
  assert.equal(await stepUp.locator('input[type="password"]').count(), 0)
  await stepUp.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await stepUp.getByLabel('Recovery code', { exact: true }).fill(recoveryCodes[7])
  await stepUp.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await stepUp.waitFor({ state: 'hidden' })
  await page.locator('.toast-error').filter({ hasText: 'cancelled or timed out' }).waitFor()
  assert.equal((await request('/api/security/status')).body.passkeys.length, 0)
  results.push(
    'Compact TOTP/recovery step-up needs no password and safely continues registration options once',
  )
  await page
    .locator('form:has(#passkey-name) [role="alert"]')
    .filter({ hasText: 'cancelled or timed out' })
    .waitFor()
  await page.locator('.toast-error').filter({ hasText: 'cancelled or timed out' }).waitFor()
  assert.equal((await request('/api/security/status')).body.state, 'authenticated')
  assert.equal((await request('/api/security/status')).body.passkeys.length, 0)
  await page.evaluate(() => {
    navigator.credentials.create = globalThis.m7OriginalCreate
    delete globalThis.m7OriginalCreate
  })
  results.push('Cancelled optional WebAuthn preserves account access and permits explicit retry')
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
  assert.equal(afterFailure.state, 'authenticated')
  assert.equal(afterFailure.passkeys.length, 0)
  assert.equal(afterFailure.fresh, true)
  assert.equal(afterFailure.totp, true)
  assert.equal(afterFailure.recoverySaved, true)
  await page.unroute('**/api/security/passkey/register')
  results.push(
    'Created but unsaved credential: real challenge rejection, toast + form reference, full access and safe fresh retry',
  )
  await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
  await page.getByRole('button', { name: 'Continue to workspace', exact: true }).waitFor()
  await page.locator('.toast-success').filter({ hasText: 'Passkey registered.' }).waitFor()
  assert.equal((await request('/api/admin/projects')).status, 200)
  results.push(
    'Owner password: restricted enrollment, reload, real TOTP, saved recovery, fresh recovery step-up, optional real UV Passkey, unlocked workspace',
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
        .getByRole('heading', { name: language.messages.security.passkeys, exact: false })
        .waitFor()
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
        `overflow ${language.code} ${width}`,
      )
      await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
      assert.equal(await page.evaluate(() => document.documentElement.lang), language.code)
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
  await page.goto(origin + '/admin/projects/' + fixture.project + '/team')
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
      // Locale/theme reloads are independent visual cases; keep the production throttle unchanged.
      php(['tests/account-security-fixture.php', 'reset-limiter'])
      await page.reload()
      await page.locator('.team-member').first().waitFor()
      const text = await page.locator('.team-member').first().textContent()
      assert.ok(text.includes(language.messages.security.authenticator))
      assert.ok(text.includes(language.messages.security.configured))
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      )
      await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
      assert.equal(await page.evaluate(() => document.documentElement.lang), language.code)
      await page.screenshot({
        path: path.join(output, `team-${language.code}-${width}-${scheme}.png`),
        fullPage: true,
      })
    }
  }
  await page.evaluate(() => localStorage.setItem('projectbrief.locale', 'en'))
  results.push(
    'Owner team: textual factor indicators, RU/EN/TR/ZH-CN, desktop/mobile and light/dark without overflow',
  )
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  await page.reload()
  await page.locator('.team-member').first().waitFor()
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.evaluate(async () => {
    const { stepUp } = await import('/src/services/stepUp.js')
    await stepUp.prepare()
    globalThis.m721Assertions = []
    globalThis.m721Dialogs = 0
    globalThis.m721RealGet = navigator.credentials.get.bind(navigator.credentials)
    navigator.credentials.get = (options) => {
      globalThis.m721Assertions.push({
        active: navigator.userActivation.isActive,
        dialog: !!document.querySelector('dialog[open]'),
      })
      return globalThis.m721RealGet(options)
    }
    const show = HTMLDialogElement.prototype.showModal
    HTMLDialogElement.prototype.showModal = function () {
      globalThis.m721Dialogs++
      return show.call(this)
    }
  })
  const teamMember = page
    .locator('.team-member')
    .filter({ hasText: 'security-developer@example.test' })
  const teamMutations = []
  page.on('request', (incoming) => {
    if (incoming.method() === 'PATCH' && incoming.url().includes('/team/members/'))
      teamMutations.push(incoming.url())
  })
  await teamMember.getByRole('button', { name: 'Projects and permissions', exact: true }).click()
  const switches = teamMember.locator('.member-permissions [role="switch"]')
  for (let i = 1; i < 6; i++) await switches.nth(i).check()
  await teamMember.getByRole('button', { name: 'Save', exact: true }).dblclick()
  await page.locator('.toast-success').filter({ hasText: 'Access updated' }).waitFor()
  await teamMember.locator('.team-edit').waitFor({ state: 'hidden' })
  assert.equal(teamMutations.length, 1)
  assert.deepEqual(await page.evaluate(() => globalThis.m721Assertions), [
    { active: true, dialog: false },
  ])
  assert.equal(await page.evaluate(() => globalThis.m721Dialogs), 0)
  const savedTeam = (await request('/api/admin/projects/' + fixture.project + '/team')).body
  const savedPermissions = savedTeam.members.find(
    (member) => member.user.email === 'security-developer@example.test',
  ).projectPermissions[fixture.project]
  assert.equal(Object.values(savedPermissions).filter(Boolean).length, 6)
  results.push(
    'Team Save -> synchronous user-activated real UV assertion -> exactly one PATCH with five changed permissions; no application dialog, including double click',
  )
  await teamMember.getByRole('button', { name: 'Projects and permissions', exact: true }).click()
  await switches.nth(5).uncheck()
  await teamMember.getByRole('button', { name: 'Save', exact: true }).click()
  await teamMember.locator('.team-edit').waitFor({ state: 'hidden' })
  assert.equal(teamMutations.length, 2)
  assert.equal(await page.evaluate(() => globalThis.m721Assertions.length), 1)
  results.push('Existing five-minute proof saves immediately without another ceremony')
  php(['tests/account-security-fixture.php', 'expire-proof'])
  await page.evaluate(async () => {
    const { stepUp } = await import('/src/services/stepUp.js')
    stepUp.invalidate()
    await stepUp.prepare()
    navigator.credentials.get = () =>
      Promise.reject(new DOMException('Cancelled', 'NotAllowedError'))
  })
  await teamMember.getByRole('button', { name: 'Projects and permissions', exact: true }).click()
  await switches.nth(4).uncheck()
  const draft = await switches.evaluateAll((items) => items.map((item) => item.checked))
  const criticalBefore = await page.locator('.toast-error').count()
  await teamMember.getByRole('button', { name: 'Save', exact: true }).click()
  const fallback = page.getByRole('dialog')
  await fallback.getByRole('button', { name: 'Retry with Passkey', exact: true }).waitFor()
  assert.equal(teamMutations.length, 2)
  assert.equal(await page.locator('.toast-error').count(), criticalBefore)
  // Measure actual action colors before and after theme transitions, not just theme attributes.
  await page.waitForFunction(() => !document.querySelector('.toast-success'))
  await page.waitForFunction(() => {
    const retry = document.querySelector('dialog[open] .primary')
    return retry && !retry.disabled
  })
  const contrastSamples = []
  const readFallbackColors = () =>
    fallback.locator('.button, .text-button').evaluateAll((buttons) => {
      const luminance = (color) => {
        const components = color
          .match(/[\d.]+/g)
          .slice(0, 3)
          .map(Number)
        return components
          .map((value) => {
            const channel = value / 255
            return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
          })
          .reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0)
      }
      return buttons.map((button) => {
        const style = getComputedStyle(button)
        let parent = button,
          background
        while (parent) {
          background = getComputedStyle(parent).backgroundColor
          if (!background.startsWith('rgba') && background !== 'transparent') break
          parent = parent.parentElement
        }
        const foregroundLuminance = luminance(style.color),
          backgroundLuminance = luminance(background)
        return {
          text: button.textContent.trim(),
          enabled: !button.disabled,
          foreground: style.color,
          background,
          ratio: Number(
            (
              (Math.max(foregroundLuminance, backgroundLuminance) + 0.05) /
              (Math.min(foregroundLuminance, backgroundLuminance) + 0.05)
            ).toFixed(3),
          ),
        }
      })
    })
  for (const language of LANGUAGE_CATALOG) {
    for (const [width, scheme] of [
      [1440, 'light'],
      [390, 'dark'],
    ]) {
      await page.setViewportSize({ width, height: width === 390 ? 844 : 1050 })
      await page.emulateMedia({ colorScheme: scheme })
      await page.evaluate(
        async ({ lang, scheme }) => {
          const { setManualLocale } = await import('/src/i18n/index.js')
          const { theme } = await import('/src/services/theme.js')
          setManualLocale(lang)
          theme.select(scheme)
        },
        { lang: language.code, scheme },
      )
      assert.equal(await page.evaluate(() => document.documentElement.dataset.theme), scheme)
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      )
      const before = await readFallbackColors()
      await page.waitForFunction(() =>
        document
          .getAnimations()
          .every(
            (animation) =>
              !('transitionProperty' in animation) || animation.playState === 'finished',
          ),
      )
      const after = await readFallbackColors()
      for (const button of after) {
        assert.equal(button.enabled, true, button.text)
        assert.ok(
          button.ratio >= 4.5,
          `${language.code}/${scheme}: ${button.text} contrast ${button.ratio}`,
        )
      }
      contrastSamples.push({ locale: language.code, scheme, width, before, after })
      await page.evaluate(() => window.scrollTo(0, 0))
      await page.screenshot({
        path: path.join(output, `fallback-${language.code}-${width}-${scheme}.png`),
        fullPage: false,
        animations: 'disabled',
      })
    }
  }
  await writeFile(
    path.join(output, 'fallback-contrast.json'),
    JSON.stringify(contrastSamples, null, 2) + '\n',
  )
  await page.evaluate(async () => {
    const { setManualLocale } = await import('/src/i18n/index.js')
    setManualLocale('en')
  })
  await fallback.getByRole('button', { name: 'Cancel', exact: true }).click()
  assert.deepEqual(await switches.evaluateAll((items) => items.map((item) => item.checked)), draft)
  assert.equal(teamMutations.length, 2)
  results.push(
    'Cancelled Passkey: no mutation or critical toast; fallback in four locales/themes, Cancel retains exact permission draft',
  )
  await page.evaluate(() => {
    navigator.credentials.get = () =>
      Promise.reject(new DOMException('Unavailable', 'NotSupportedError'))
  })
  await teamMember.getByRole('button', { name: 'Save', exact: true }).click()
  await fallback.getByRole('button', { name: 'Another confirmation method', exact: true }).click()
  assert.equal(await fallback.locator('input[type="password"]').count(), 0)
  await fallback.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await fallback.getByLabel('Recovery code', { exact: true }).fill(recoveryCodes[6])
  await fallback.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await teamMember.locator('.team-edit').waitFor({ state: 'hidden' })
  assert.equal(teamMutations.length, 3)
  await page.evaluate(() => {
    navigator.credentials.get = globalThis.m721RealGet
  })
  results.push(
    'Unavailable Passkey uses allowed compact recovery alternative and continues the exact draft once',
  )
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  php(['tests/account-security-fixture.php', 'expire-proof'])
  await page.evaluate(async () => {
    const { stepUp } = await import('/src/services/stepUp.js')
    stepUp.invalidate()
    await stepUp.prepare()
    navigator.credentials.get = () =>
      Promise.reject(new DOMException('Cancelled', 'NotAllowedError'))
  })
  await teamMember.getByRole('button', { name: 'Projects and permissions', exact: true }).click()
  await switches.nth(4).check()
  await teamMember.getByRole('button', { name: 'Save', exact: true }).click()
  await fallback.getByRole('button', { name: 'Retry with Passkey', exact: true }).waitFor()
  assert.equal(teamMutations.length, 3)
  await page.evaluate(() => {
    navigator.credentials.get = globalThis.m721RealGet
  })
  await fallback.getByRole('button', { name: 'Retry with Passkey', exact: true }).click()
  await teamMember.locator('.team-edit').waitFor({ state: 'hidden' })
  assert.equal(teamMutations.length, 4)
  results.push('Explicit Retry with Passkey invokes a new real UV assertion and continues once')

  const integrationOptions = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/security/passkey/confirm/options') && response.status() === 200,
  )
  await page.goto(origin + '/admin/settings/integrations')
  await integrationOptions
  assert.equal(await page.locator('dialog[open]').count(), 0)
  results.push(
    'Actual settings/integrations route prepares a confirmation challenge without opening an application dialog',
  )

  php(['tests/account-security-fixture.php', 'reset-limiter'])
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
  await page.waitForURL(origin + '/admin/security')
  await page.getByRole('button', { name: 'Later — continue to workspace', exact: true }).click()
  await page.waitForURL(origin + '/admin')
  assert.equal((await request('/api/security/status')).body.totp, false)
  results.push('Developer password remains usable without TOTP or Passkey')
  php(['tests/account-security-fixture.php', 'expire-proof', 'developer'])
  await page.goto(origin + '/admin/account/security')
  await page.getByRole('button', { name: 'Set up authenticator', exact: true }).click()
  await page
    .getByRole('dialog')
    .getByLabel('Current password', { exact: true })
    .fill('Developer-test-password!')
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Confirm identity', exact: true })
    .click()
  await page.locator('#totp-confirm').waitFor()
  results.push(
    'Developer without factors gets compact current-password confirmation and setup continues without a second click',
  )

  await page
    .locator('#totp-confirm')
    .fill(php(['tests/account-security-fixture.php', 'code', 'developer'], true).code)
  await page.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await page.locator('.security-codes code').first().waitFor()
  const developerCodes = await page.locator('.security-codes code').allTextContents()
  await page.getByRole('button', { name: 'I have saved my recovery codes', exact: true }).click()
  await page.getByRole('button', { name: 'Turn off authenticator', exact: true }).waitFor()
  await request('/api/admin/logout', 'POST')
  await page.goto(origin + '/admin/login')
  await page.getByLabel('Email', { exact: true }).fill('security-developer@example.test')
  await page.getByLabel('Password', { exact: true }).fill('Developer-test-password!')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await page.waitForURL(origin + '/admin/security')
  assert.equal((await request('/api/admin/projects')).status, 403)
  await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await page.getByLabel('Recovery code', { exact: true }).fill(developerCodes[0])
  await page.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await page.getByRole('button', { name: 'Continue to workspace', exact: true }).waitFor()
  assert.equal((await request('/api/admin/projects')).status, 200)
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  await page.goto(origin + '/admin/account/security')
  await page.getByRole('button', { name: 'Turn off authenticator', exact: true }).click()
  await page.locator('#disable-totp-password').fill('Developer-test-password!')
  await page
    .locator('form:has(#disable-totp-password)')
    .getByRole('button', { name: 'Turn off authenticator', exact: true })
    .click()
  await page.getByRole('button', { name: 'Set up authenticator', exact: true }).waitFor()
  assert.equal((await request('/api/security/status')).body.totp, false)
  results.push(
    'Developer voluntarily enrolls TOTP, password login requires recovery, fresh MFA + password alternative permits disabling',
  )
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
      const compatibility = await compatibilityBrowser.newContext({
        ignoreHTTPSErrors: true,
        serviceWorkers: 'block',
      })
      await compatibility.routeWebSocket('**/*', (socket) =>
        socket.send(JSON.stringify({ type: 'connected' })),
      )
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
      // A separate real-engine session validates activation and unavailable-device fallback.
      // The provider deliberately cancels; signed resident UV is exercised by Chromium above.
      php(['tests/account-security-fixture.php', 'reset-limiter'])
      await compatibilityPage.goto(origin + '/admin/login')
      await compatibilityPage
        .getByLabel('Email', { exact: true })
        .fill('security-owner@example.test')
      await compatibilityPage.getByLabel('Password', { exact: true }).fill('Owner-test-password!')
      await compatibilityPage.getByRole('button', { name: 'Sign in', exact: true }).click()
      await compatibilityPage.waitForURL('**/admin/security')
      await compatibilityPage
        .getByRole('button', { name: 'Use a recovery code', exact: true })
        .click()
      await compatibilityPage.getByLabel('Recovery code', { exact: true }).fill(recoveryCodes[5])
      await compatibilityPage.getByRole('button', { name: 'Confirm identity', exact: true }).click()
      await compatibilityPage
        .getByRole('button', { name: 'Continue to workspace', exact: true })
        .waitFor()
      await compatibilityPage.goto(origin + '/admin/projects/' + fixture.project + '/team')
      await compatibilityPage.locator('.team-member').first().waitFor()
      php(['tests/account-security-fixture.php', 'expire-proof'])
      await compatibilityPage.evaluate(async () => {
        const { stepUp } = await import('/src/services/stepUp.js')
        stepUp.invalidate()
        await stepUp.prepare()
        navigator.credentials.get = () => {
          globalThis.m721EngineActivation = {
            active: navigator.userActivation.isActive,
            dialog: !!document.querySelector('dialog[open]'),
          }
          return Promise.reject(new DOMException('Device unavailable', 'NotAllowedError'))
        }
      })
      const employee = compatibilityPage
        .locator('.team-member')
        .filter({ hasText: 'security-developer@example.test' })
      await employee.getByRole('button', { name: 'Projects and permissions', exact: true }).click()
      const permission = employee.locator('.member-permissions [role="switch"]').nth(5)
      await permission.setChecked(!(await permission.isChecked()))
      await employee.getByRole('button', { name: 'Save', exact: true }).click()
      const engineFallback = compatibilityPage.getByRole('dialog')
      await engineFallback
        .getByRole('button', { name: 'Another confirmation method', exact: true })
        .waitFor()
      assert.deepEqual(await compatibilityPage.evaluate(() => globalThis.m721EngineActivation), {
        active: true,
        dialog: false,
      })
      await engineFallback
        .getByRole('button', { name: 'Another confirmation method', exact: true })
        .click()
      assert.equal(await engineFallback.locator('input[type="password"]').count(), 0)
      await engineFallback.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
      await engineFallback.getByLabel('Recovery code', { exact: true }).fill(recoveryCodes[4])
      await engineFallback.getByRole('button', { name: 'Confirm identity', exact: true }).click()
      await employee.locator('.team-edit').waitFor({ state: 'hidden' })
      await compatibilityPage
        .locator('.toast-success')
        .filter({ hasText: 'Access updated' })
        .waitFor()
      results.push(
        `${engine}: native get invoked with real user activation and no app dialog; cancelled provider -> compact recovery -> original team draft saved (mock provider, real server)`,
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
