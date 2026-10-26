import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import net from 'node:net'
import { preview } from 'vite'
const { chromium, firefox } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = fileURLToPath(new URL('..', import.meta.url))
const output = path.join(root, 'docs/milestone-6-2')
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone62-'))
const app = path.join(scratch, 'app')
await mkdir(app)
await mkdir(output, { recursive: true })
let backend, frontend
const errors = [],
  results = []
async function port() {
  const server = net.createServer()
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const value = server.address().port
  await new Promise((resolve) => server.close(resolve))
  return value
}
const apiPort = await port(),
  webPort = await port()
const origin = `http://127.0.0.1:${webPort}`,
  apiOrigin = `http://127.0.0.1:${apiPort}`
const environment = {
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
  SESSION_COOKIE: 'milestone62_session',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'array',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'null',
  QUEUE_CONNECTION: 'sync',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  BROWSER_PDF_RATE_LIMIT: '10000',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args) {
  const result = spawnSync('php', args, { cwd: app, env: environment, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
async function request(context, url, body) {
  const options = { headers: { Accept: 'application/json' } }
  if (body !== undefined) {
    const csrf = await (await context.request.get(origin + '/api/csrf')).json()
    options.headers['X-CSRF-TOKEN'] = csrf.token
    options.data = body
  }
  return context.request[body === undefined ? 'get' : 'post'](origin + url, options)
}
async function login(page) {
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill('owner@example.test')
  await page.locator('#admin-password').fill('Local-test-password!')
  await page.locator('form button[type=submit]').click()
  await page.waitForURL(origin + '/admin')
}
async function capture(page, name) {
  await page.evaluate(async () => {
    document.activeElement?.blur()
    scrollTo(0, 0)
    await new Promise(requestAnimationFrame)
    await new Promise(requestAnimationFrame)
  })
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
    name + ' overflow',
  )
  await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true })
}
async function download(page, name, type = 'pdf') {
  const downloaded = page.waitForEvent('download', { timeout: 60000 })
  const data =
    type === 'pdf'
      ? page.waitForResponse(
          (response) =>
            response.url().endsWith('/export-data') && response.request().method() === 'POST',
        )
      : null
  await page
    .locator(
      type === 'pdf'
        ? '.export-actions button[type=submit]'
        : '.export-actions button[type=button]',
    )
    .first()
    .click()
  const file = await downloaded
  assert.equal(await file.failure(), null)
  await file.saveAs(path.join(output, name + '.' + type))
  if (data) {
    const response = await data
    assert.equal(response.status(), 200)
    assert.match(response.headers()['cache-control'], /no-store/)
    const payload = await response.json()
    await writeFile(
      path.join(output, name + '.json'),
      JSON.stringify(payload.document, null, 2) + '\n',
    )
  }
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
      filter: (file) => !/(?:^|\/)(?:\.DS_Store|cache\/[^/]+|[^/]*\.sqlite[^/]*)$/.test(file),
    })
  }
  await mkdir(path.join(app, 'tests'))
  await cp(
    path.join(root, 'api/tests/export-fixture.php'),
    path.join(app, 'tests/export-fixture.php'),
  )
  for (const directory of [
    'bootstrap/cache',
    'storage/framework/views',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/logs',
  ])
    await mkdir(path.join(app, directory), { recursive: true })
  await writeFile(path.join(app, '.env'), '')
  await writeFile(environment.DB_DATABASE, '')
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/export-fixture.php']))
  backend = spawn(
    'php',
    [
      '-d',
      'memory_limit=128M',
      '-d',
      'opcache.enable=0',
      '-d',
      'max_execution_time=30',
      '-S',
      `127.0.0.1:${apiPort}`,
      '-t',
      'public',
      'public/index.php',
    ],
    { cwd: app, env: environment, stdio: 'ignore' },
  )
  frontend = await preview({
    configFile: path.join(root, 'vite.config.js'),
    preview: {
      host: '127.0.0.1',
      port: webPort,
      strictPort: true,
      proxy: { '/api': { target: apiOrigin }, '/access/': { target: apiOrigin } },
    },
    logLevel: 'error',
  })
  for (let attempt = 0; attempt < 100; attempt++) {
    try {
      if ((await fetch(apiOrigin + '/api/health')).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100))
  }
  for (const engine of [chromium, firefox]) {
    const browser = await engine.launch({ headless: true })
    try {
      if (engine === chromium) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
        const page = await context.newPage()
        await login(page)
        const existing = (
          await (await request(context, '/api/admin/projects/' + fixture.project)).json()
        ).data.sections
        await page.goto(origin + '/admin/projects/new')
        await page.locator('#project-title').fill('Empty project / Новый / 空项目')
        const created = page.waitForResponse(
          (response) =>
            response.url().endsWith('/api/admin/projects') &&
            response.request().method() === 'POST',
        )
        await page.locator('form button[type=submit]').click()
        const payload = await (await created).json(),
          project = payload.data.id
        await page.waitForURL(origin + '/admin/projects/' + project + '/brief')
        await page.locator('.project-empty-state').waitFor()
        assert.deepEqual(payload.data.sections, [])
        await capture(page, 'empty-admin-desktop')
        await page.setViewportSize({ width: 390, height: 844 })
        await page.locator('.theme-switcher select').selectOption('dark')
        await capture(page, 'empty-admin-mobile-dark')
        await page.goto(origin + `/admin/projects/${project}/export`)
        await page.locator('.export-actions button[type=submit]').click()
        await page.locator('.export-actions [role=alert]').waitFor()
        await capture(page, 'empty-export-message')
        await page.goto(origin + `/admin/projects/${project}/brief`)
        await page.locator('.project-empty-state').waitFor()
        assert.deepEqual(
          (await (await request(context, '/api/admin/projects/' + project + '/discussions')).json())
            .data,
          [],
        )
        const clients = await (
          await request(context, `/api/admin/projects/${project}/clients`, {
            name: 'Read only empty client',
          })
        ).json()
        const access = await (
          await request(
            context,
            `/api/admin/projects/${project}/clients/${clients.data[0].id}/access-links`,
            {},
          )
        ).json()
        const client = await browser.newContext({ viewport: { width: 390, height: 844 } })
        const clientPage = await client.newPage()
        await clientPage.goto(origin + new URL(access.url).pathname)
        await clientPage.waitForURL(origin + '/project/' + project)
        await clientPage.locator('.project-empty-state').waitFor()
        assert.equal(await clientPage.locator('.project-empty-state a').count(), 0)
        assert.equal(await clientPage.locator('a[href$="/task/new"]').count(), 0)
        await capture(clientPage, 'empty-client-mobile')
        await client.close()
        await page.locator('.project-empty-state a').click()
        await page.locator('#new-section-name').fill('First section / İlk bölüm / 第一个分区')
        await page.locator('.add-section-form button[type=submit]').click()
        await page.locator('.managed-section').waitFor()
        const sections = await (
          await request(context, `/api/admin/projects/${project}/sections`)
        ).json()
        assert.equal(sections.data.length, 1)
        assert.equal(sections.data[0].position, 1)
        assert.deepEqual(
          (await (await request(context, '/api/admin/projects/' + fixture.project)).json()).data
            .sections,
          existing,
        )
        results.push({
          test: 'empty-project-ui-manual-section-client-and-preservation',
          status: 'passed',
        })
        await page.setViewportSize({ width: 768, height: 1024 })
        await page.goto(origin + `/admin/projects/${fixture.project}/export`)
        await page.locator('.language-switcher select').selectOption('tr')
        await page.locator('#export-language').selectOption('tr')
        assert.equal(
          await page
            .locator('.export-grid')
            .evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length),
          1,
        )
        await capture(page, 'tablet-768-tr-dark-export')
        results.push({ test: 'tablet-768-single-column', status: 'passed' })
        await context.close()
      }
      for (const width of [1440, 390])
        for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
          const context = await browser.newContext({
            viewport: { width, height: width === 390 ? 844 : 1000 },
            isMobile: engine === chromium && width === 390,
            hasTouch: width === 390,
            acceptDownloads: true,
          })
          const page = await context.newPage()
          page.on('pageerror', (error) => errors.push(engine.name() + ': ' + error.message))
          const fontsBeforeExport = []
          page.on('request', (request) => {
            if (/\.ttf(?:\?|$)/.test(request.url())) fontsBeforeExport.push(request.url())
          })
          await login(page)
          await page.goto(origin + '/admin/projects/' + fixture.project + '/export')
          await page.locator('.export-form').waitFor()
          assert.equal(fontsBeforeExport.length, 0, 'TTF must remain lazy')
          await page.locator('.language-switcher select').selectOption(locale)
          await page
            .locator('.theme-switcher select')
            .selectOption(['en', 'tr'].includes(locale) ? 'dark' : 'light')
          await page.locator('#export-language').selectOption(locale)
          for (const field of await page.locator('.export-fields input').all()) await field.check()
          assert.equal(await page.locator('.export-fields input').count(), 9)
          const columns = await page
            .locator('.export-grid')
            .evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length)
          assert.equal(columns, width >= 1024 ? 2 : 1)
          const name = `${engine.name()}-${width}-${locale}`
          await capture(page, name + '-export')
          await download(page, name)
          if (engine === chromium && width === 1440 && locale === 'en') {
            await page.evaluate(() => caches.delete('projectbrief-pdf-public-fonts-v1'))
            await page.route('**/NotoSansSC-Regular-*.ttf', (route) => route.abort())
            await page.locator('.export-actions button[type=submit]').click()
            await page.locator('.export-actions [role=alert]').waitFor()
            await page.unroute('**/NotoSansSC-Regular-*.ttf')
            await download(page, 'retry-en')
            await page.evaluate(() => caches.delete('projectbrief-pdf-public-fonts-v1'))
            let held
            await page.route('**/NotoSansSC-Regular-*.ttf', (route) => {
              held = route
            })
            await page.locator('.export-actions button[type=submit]').click()
            await page.locator('.export-actions [role=status]').waitFor()
            await page.locator('.export-actions button.text').click()
            if (held) await held.abort().catch(() => {})
            await page.unroute('**/NotoSansSC-Regular-*.ttf')
            assert.equal(
              await page.locator('.export-actions button[type=submit]').isEnabled(),
              true,
            )
            await download(page, 'after-cancel-en')
            await download(page, 'sample-en', 'docx')
            results.push({ test: 'font-error-retry-and-cancel', status: 'passed' })
          }
          if (engine === chromium) {
            await page.goto(origin + '/access/' + fixture.token)
            await page.waitForURL(origin + '/project/' + fixture.project)
            await page.goto(origin + '/project/' + fixture.project + '/export')
            await page.locator('.export-form').waitFor()
            await page.locator('#export-language').selectOption(locale)
            assert.equal(await page.locator('.export-fields input').count(), 6)
            for (const field of await page.locator('.export-fields input').all())
              await field.check()
            await capture(page, name + '-client-export')
            const forged = await (
              await request(context, `/api/client/project/${fixture.project}/export-data`, {
                format: 'pdf',
                locale,
                scope: 'all',
                fields: { developerNotes: true, price: true, estimateHours: true },
              })
            ).text()
            assert.doesNotMatch(forged, /SYNTHETIC_PRIVATE|developerNotes|estimateHours|"price"/)
            await download(page, name + '-client')
            if (width === 1440 && locale === 'zh-CN')
              await download(page, 'sample-client-zh-CN', 'docx')
          }
          const stored = await page.evaluate(async () => {
            const cache = await caches.open('projectbrief-pdf-public-fonts-v1')
            return {
              values: [...Object.values(localStorage), ...Object.values(sessionStorage)].join(' '),
              urls: (await cache.keys()).map((request) => request.url),
            }
          })
          assert.doesNotMatch(stored.values, /Export task|SYNTHETIC_PRIVATE|原始中文需求/)
          assert.ok(stored.urls.every((url) => new URL(url).pathname.endsWith('.ttf')))
          results.push({
            engine: engine.name(),
            version: browser.version(),
            width,
            locale,
            status: 'passed',
          })
          console.log('Passed', name)
          await context.close()
        }
    } finally {
      await browser.close()
    }
  }
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'browser-results.json'),
    JSON.stringify(
      {
        results,
        errors,
        safari: 'not tested',
        physicalMobile: 'not tested',
        productionBuild: true,
      },
      null,
      2,
    ) + '\n',
  )
} finally {
  await new Promise((resolve) => (frontend ? frontend.httpServer.close(resolve) : resolve()))
  backend?.kill('SIGTERM')
  await rm(scratch, { recursive: true, force: true })
}
