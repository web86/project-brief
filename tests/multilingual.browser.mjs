import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer } from 'vite'
import { createServer as createNetServer } from 'node:net'
import { LANGUAGE_CATALOG } from '../src/i18n/catalog.js'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = new URL('../docs/milestone-6-0/', import.meta.url)
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone60-e2e-'))
const database = path.join(scratch, 'test.sqlite')
await writeFile(database, '')
async function freePort() {
  const probe = createNetServer()
  await new Promise((resolve) => probe.listen(0, '127.0.0.1', resolve))
  const port = probe.address().port
  await new Promise((resolve) => probe.close(resolve))
  return port
}
const frontendPort = await freePort(),
  backendPort = await freePort()
const backendOrigin = `http://127.0.0.1:${backendPort}`
const origin = `http://127.0.0.1:${frontendPort}`
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_DOMAIN: '',
  CACHE_STORE: 'array',
  LOG_CHANNEL: 'stderr',
  MAIL_MAILER: 'log',
  TELEGRAM_BOT_TOKEN: '',
  TELEGRAM_BRIDGE_ENABLED: 'false',
  QUEUE_CONNECTION: 'sync',
  ARGON_MEMORY: '1024',
  ARGON_TIME: '1',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function run(args) {
  const result = spawnSync('php', args, { cwd: root + '/api', env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
const vite = await createServer({
  envFile: false,
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: {
    host: '127.0.0.1',
    port: frontendPort,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/release/**'] },
    proxy: { '/api': { target: backendOrigin }, '/access/': { target: backendOrigin } },
  },
  logLevel: 'error',
})
let backend, browser
const results = [],
  errors = []
async function navigate(page, url) {
  await page.evaluate(async (url) => {
    const { default: router } = await import('/src/router/index.js')
    await router.push(url)
  }, url)
}
async function call(page, url) {
  return page.evaluate(
    async (url) => (await fetch(url, { headers: { Accept: 'application/json' } })).json(),
    url,
  )
}
async function login(page, persona = 'owner') {
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill(persona + '@example.test')
  await page.locator('#admin-password').fill('Local-e2e-password!')
  await page.locator('form button[type=submit]').click()
  await page.waitForURL(origin + '/admin')
}
async function audit(page, label) {
  await page.locator('#main-content').waitFor()
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
    label + ': horizontal overflow',
  )
  assert.doesNotMatch(
    await page.locator('body').innerText(),
    /\b(?:ui|common|flow|account|nav|ai|notifications|export|integrations|discussions)\.[a-zA-Z_]\w*/u,
    label + ': technical key',
  )
}
async function select(page, language, persona) {
  const response = page.waitForResponse(
    (r) =>
      r.url().endsWith(`/api/${persona === 'client' ? 'client' : 'admin'}/me/locale`) &&
      r.request().method() === 'PATCH',
  )
  await page.locator('.language-switcher select').first().selectOption(language, { force: true })
  assert.equal((await response).status(), 200)
  await page.waitForFunction((language) => document.documentElement.lang === language, language)
}
try {
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(run(['tests/milestone60-fixture.php']))
  backend = spawn(
    'php',
    [
      '-S',
      `127.0.0.1:${backendPort}`,
      root + '/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    { cwd: root + '/api/public', env, stdio: ['ignore', 'ignore', 'pipe'] },
  )
  backend.stderr.on('data', (data) => {
    if (process.env.E2E_DEBUG) process.stderr.write(data)
  })
  backend.on('exit', (code) => {
    if (code) console.error('Backend exited:', code)
  })
  await vite.listen()
  for (let i = 0; i < 50; i++) {
    try {
      if ((await fetch(backendOrigin + '/api/health')).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100))
  }
  browser = await chromium.launch({ headless: true })
  for (const width of [1440, 390])
    for (const { code: lang, intl, messages } of LANGUAGE_CATALOG) {
      const context = await browser.newContext({
        viewport: { width, height: width === 390 ? 844 : 1000 },
        locale: intl,
        hasTouch: width === 390,
        reducedMotion: 'reduce',
      })
      const page = await context.newPage()
      page.setDefaultTimeout(15000)
      page.setDefaultNavigationTimeout(15000)
      page.on('pageerror', (error) => errors.push(error.message))
      page.on('console', (msg) => {
        if (msg.text().includes('[intlify]')) errors.push(msg.text())
      })
      await page.goto(origin + '/admin/login')
      assert.equal(await page.locator('html').getAttribute('lang'), lang)
      await audit(page, `login-${lang}-${width}`)
      await page.screenshot({
        path: new URL(`${width === 390 ? 'mobile' : 'desktop'}-login-${lang}.png`, output).pathname,
        fullPage: true,
      })
      await login(page)
      const adminRoot = `/admin/projects/${fixture.project}`
      await navigate(page, adminRoot + '/brief')
      await page.locator('.project-header').waitFor()
      const marker = randomBytes(8).toString('hex')
      await page.evaluate((marker) => {
        window.noReloadMarker = marker
      }, marker)
      // Exercise all three choices during an authenticated session, without a document navigation.
      for (const item of LANGUAGE_CATALOG) {
        await select(page, item.code, 'owner')
        assert.equal(await page.evaluate(() => window.noReloadMarker), marker)
        assert.equal((await call(page, '/api/admin/account')).user.preferredLocale, item.code)
      }
      await select(page, lang, 'owner')
      assert.equal(
        (await call(page, `/api/admin/projects/${fixture.project}`)).data.currency,
        'USD',
      )
      for (const section of [
        'brief',
        'discussions',
        'clients',
        'team',
        'structure',
        'notifications',
        'settings',
        'export',
      ]) {
        await navigate(page, adminRoot + '/' + section)
        await audit(page, `owner-${section}-${lang}-${width}`)
        if (section === 'discussions')
          assert.ok((await page.locator('body').innerText()).includes(messages.ui.developer))
        if (section === 'brief')
          await page.screenshot({
            path: new URL(`${width === 390 ? 'mobile' : 'desktop'}-admin-${lang}.png`, output)
              .pathname,
            fullPage: true,
          })
      }
      await navigate(page, adminRoot + '/brief/task/new')
      await page.locator('#idea-description').fill('Original user description çğışİöü')
      await page.getByRole('button', { name: messages.ai.improve, exact: true }).click()
      await page.locator('dialog[open]').waitFor()
      assert.ok((await page.locator('dialog[open]').innerText()).includes(messages.ai.title))
      await page.getByRole('button', { name: messages.ai.close, exact: true }).click()
      await navigate(page, adminRoot + '/export')
      const interfaceLang = await page.locator('html').getAttribute('lang')
      const documentLanguage =
        LANGUAGE_CATALOG[
          (LANGUAGE_CATALOG.findIndex((item) => item.code === lang) + LANGUAGE_CATALOG.length - 1) %
            LANGUAGE_CATALOG.length
        ].code
      await page.locator('#export-language').selectOption(documentLanguage)
      assert.equal(
        await page.locator('#export-document-title').inputValue(),
        LANGUAGE_CATALOG.find((item) => item.code === documentLanguage).messages.export
          .defaultDocumentTitle,
      )
      assert.equal(await page.locator('html').getAttribute('lang'), interfaceLang)
      await page.getByLabel(messages.export.estimateHours, { exact: true }).check()
      await page.getByLabel(messages.export.price, { exact: true }).check()
      const pdf = page.waitForEvent('download')
      await page.locator('.export-form button[type=submit]').click()
      await (await pdf).saveAs(new URL(`sample-${documentLanguage}.pdf`, output).pathname)
      const docx = page.waitForEvent('download')
      await page.locator('.export-actions button.secondary').click()
      await (await docx).saveAs(new URL(`sample-${documentLanguage}.docx`, output).pathname)
      await navigate(page, '/admin/account')
      await page.locator('#account-language').waitFor()
      await page.locator('#account-language').selectOption(lang)
      await page
        .locator('form')
        .filter({ has: page.locator('#account-language') })
        .locator('button[type=submit]')
        .click()
      await page.waitForResponse(
        (r) => r.url().endsWith('/api/admin/account') && r.request().method() === 'PATCH',
      )
      assert.equal((await call(page, '/api/admin/account')).user.preferredLocale, lang)
      await page.reload()
      assert.equal(await page.locator('html').getAttribute('lang'), lang)
      // A second device has a different browser language and no saved manual choice.
      const remote = await browser.newContext({ locale: lang === 'en' ? 'ru-RU' : 'en-GB' })
      const remotePage = await remote.newPage()
      await login(remotePage)
      await remotePage.waitForFunction((lang) => document.documentElement.lang === lang, lang)
      assert.equal(
        await remotePage.evaluate(() => localStorage.getItem('projectbrief.locale')),
        null,
      )
      await remote.close()
      await navigate(page, '/app')
      await page.waitForURL(origin + '/admin')
      await context.close()
      const clientContext = await browser.newContext({
        viewport: { width, height: width === 390 ? 844 : 1000 },
        locale: intl,
        reducedMotion: 'reduce',
      })
      const clientPage = await clientContext.newPage()
      clientPage.on('pageerror', (error) => errors.push(error.message))
      await clientPage.goto(origin + '/access/' + fixture.token)
      const clientRoot = `/project/${fixture.project}`
      await clientPage.waitForURL(origin + clientRoot)
      await clientPage.locator('.project-header').waitFor()
      await select(clientPage, lang, 'client')
      assert.equal((await call(clientPage, '/api/client/me')).preferredLocale, lang)
      for (const suffix of [
        '',
        '/discussions',
        '/notifications',
        '/export',
        '/task/' + fixture.task,
      ]) {
        await navigate(clientPage, clientRoot + suffix)
        await audit(clientPage, `client${suffix}-${lang}-${width}`)
        if (!suffix)
          await clientPage.screenshot({
            path: new URL(`${width === 390 ? 'mobile' : 'desktop'}-client-${lang}.png`, output)
              .pathname,
            fullPage: true,
          })
      }
      assert.ok((await clientPage.locator('body').innerText()).includes('исходный текст'))
      await clientPage.reload()
      await clientPage.locator('.task-heading').waitFor()
      assert.equal(await clientPage.locator('html').getAttribute('lang'), lang)
      await navigate(clientPage, '/app')
      await clientPage.waitForURL(origin + clientRoot)
      const remoteClient = await browser.newContext({ locale: lang === 'en' ? 'ru-RU' : 'en-GB' })
      const remoteClientPage = await remoteClient.newPage()
      await remoteClientPage.goto(origin + '/access/' + fixture.token)
      await remoteClientPage.waitForURL(origin + clientRoot)
      await remoteClientPage.waitForFunction((lang) => document.documentElement.lang === lang, lang)
      await remoteClient.close()
      await clientContext.close()
      results.push({
        language: lang,
        width,
        personas: ['owner', 'client'],
        switchWithoutReload: true,
        profilePersistence: true,
        crossDevice: true,
        pwaEntry: true,
        exportsIndependent: true,
        overflow: false,
      })
      console.log(`Passed ${lang} ${width}`)
    }
  for (const [browserLocale, expected] of [
    ['zh-Hans', 'zh-CN'],
    ['zh-SG', 'zh-CN'],
    ['zh-TW', 'en'],
    ['zh-Hant', 'en'],
    ['zh-HK', 'en'],
  ]) {
    const aliasContext = await browser.newContext({ locale: browserLocale })
    const aliasPage = await aliasContext.newPage()
    await aliasPage.goto(origin + '/admin/login')
    assert.equal(await aliasPage.locator('html').getAttribute('lang'), expected, browserLocale)
    await aliasContext.close()
  }
  const context = await browser.newContext({ locale: 'de-DE' }),
    page = await context.newPage()
  await page.goto(origin + '/admin/login')
  assert.equal(await page.locator('html').getAttribute('lang'), 'en')
  await page.goto(origin + '/admin/forgot-password')
  assert.ok((await page.locator('body').innerText()).includes('Password recovery'))
  await page.goto(origin + '/invitation#token=' + fixture.invitation)
  await page.getByText('new@example.test', { exact: true }).waitFor()
  await audit(page, 'invitation')
  await context.close()
  assert.deepEqual(errors, [])
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify({ results, errors }, null, 2) + '\n',
  )
} finally {
  await browser?.close()
  await vite.close()
  backend?.kill('SIGTERM')
  await rm(scratch, { recursive: true, force: true })
}
