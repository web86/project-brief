import assert from 'node:assert/strict'
import { spawn, spawnSync, execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, readFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer as netServer } from 'node:net'
import { createServer } from 'vite'
const { chromium, firefox, webkit } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = path.join(root, 'docs/milestone-6-1')
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone61-e2e-'))
const app = path.join(scratch, 'app')
await mkdir(app)
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
await mkdir(path.join(app, 'tests'), { recursive: true })
await cp(
  path.join(root, 'api/tests/milestone61-fixture.php'),
  path.join(app, 'tests/milestone61-fixture.php'),
)
for (const entry of [
  'bootstrap/cache',
  'storage/framework/views',
  'storage/framework/cache/data',
  'storage/framework/sessions',
  'storage/logs',
])
  await mkdir(path.join(app, entry), { recursive: true })
await writeFile(path.join(app, '.env'), '')
const database = path.join(scratch, 'benchmark.sqlite')
await writeFile(database, '')
async function freePort() {
  const server = netServer()
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const port = server.address().port
  await new Promise((resolve) => server.close(resolve))
  return port
}
const apiPort = await freePort(),
  port = await freePort(),
  origin = `http://127.0.0.1:${port}`,
  backendOrigin = `http://127.0.0.1:${apiPort}`
const environment = {
  PATH: process.env.PATH,
  TMPDIR: scratch,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'milestone61_session',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_DOMAIN: '',
  CACHE_STORE: 'array',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'null',
  QUEUE_CONNECTION: 'sync',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  BROWSER_PDF_PROTOTYPE_ENABLED: 'true',
  BROWSER_PDF_DIAGNOSTICS: 'true',
  BROWSER_PDF_RATE_LIMIT: '10000',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args) {
  const result = spawnSync('php', args, { cwd: app, env: environment, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
php(['artisan', 'migrate', '--force', '--no-interaction'])
const fixtures = JSON.parse(php(['tests/milestone61-fixture.php']))
const backend = spawn(
  'php',
  [
    '-d',
    'memory_limit=128M',
    '-d',
    'opcache.enable=0',
    '-d',
    'opcache.enable_cli=0',
    '-d',
    'max_execution_time=30',
    '-S',
    `127.0.0.1:${apiPort}`,
    '-t',
    'public',
    'public/index.php',
  ],
  { cwd: app, env: environment, stdio: 'pipe' },
)
let backendLog = ''
backend.stderr.on('data', (chunk) => {
  backendLog += chunk
})
const vite = await createServer({
  configFile: root + '/preview/browser-pdf/vite.config.js',
  server: {
    port,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/artifacts/**', '**/release/**'] },
    proxy: { '/api': { target: backendOrigin }, '/access/': { target: backendOrigin } },
  },
  logLevel: 'error',
})
let browser, browserServer
let browserPid
async function closeTestBrowser() {
  const current = browser,
    server = browserServer
  browser = null
  browserServer = null
  if (!server) return
  let timer
  try {
    await Promise.race([
      Promise.allSettled([current?.close(), server.close()]),
      new Promise((resolve) => {
        timer = setTimeout(() => {
          try {
            process.kill(-server.process().pid, 'SIGKILL')
          } catch {
            server.process()?.kill('SIGKILL')
          }
          resolve()
        }, 5000)
      }),
    ])
  } finally {
    clearTimeout(timer)
  }
}
function sampleBrowser() {
  const rows = execFileSync('/bin/ps', ['-axo', 'pid=,ppid=,rss=,time='], { encoding: 'utf8' })
    .trim()
    .split('\n')
    .map((line) => {
      const [pid, ppid, rss, time] = line.trim().split(/\s+/)
      return {
        pid: Number(pid),
        ppid: Number(ppid),
        rss: Number(rss) * 1024,
        cpu: time.split(':').reduce((total, part) => total * 60 + Number(part), 0),
      }
    })
  const ids = new Set([browserPid])
  let expanded = true
  while (expanded) {
    expanded = false
    for (const row of rows)
      if (ids.has(row.ppid) && !ids.has(row.pid)) {
        ids.add(row.pid)
        expanded = true
      }
  }
  return rows.filter((row) => ids.has(row.pid))
}
const results = [],
  errors = []
const full = Object.fromEntries(
  [
    'description',
    'expectedResult',
    'priority',
    'status',
    'estimateHours',
    'price',
    'attachments',
    'approvalDate',
    'developerNotes',
  ].map((key) => [key, true]),
)
const defaults = { description: true, expectedResult: true, priority: true, status: true }
async function login(page) {
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill('owner@example.test')
  await page.locator('#admin-password').fill('Local-e2e-password!')
  await page.locator('form button[type=submit]').click()
  await page.waitForURL(origin + '/admin')
}
async function generate(
  page,
  {
    profile = 'cjk',
    tasks = 10,
    locale = 'zh-CN',
    fields = 'full',
    save = false,
    samplePrefix = '',
    pageBreakBySection = false,
  } = {},
) {
  const project = fixtures[profile + '-' + tasks].project
  const record = { profile, tasks, locale, fields }
  let apiResponse
  const observedStarted = performance.now()
  const before = sampleBrowser(),
    cpuBefore = new Map(before.map((row) => [row.pid, row.cpu])),
    observed = new Map(cpuBefore)
  let peakRss = before.reduce((sum, row) => sum + row.rss, 0),
    rssSamples = 0
  const sample = () => {
    const rows = sampleBrowser()
    peakRss = Math.max(
      peakRss,
      rows.reduce((sum, row) => sum + row.rss, 0),
    )
    for (const row of rows) observed.set(row.pid, Math.max(observed.get(row.pid) || 0, row.cpu))
    rssSamples++
  }
  const monitor = setInterval(sample, 100)
  const responseListener = async (response) => {
    if (response.url().endsWith('/export-data')) {
      apiResponse = response
    }
  }
  page.on('response', responseListener)
  try {
    const value = await page.evaluate(
      async (input) => {
        const { createBrowserPdfAdapter } = await import('/adapter.js')
        let last = performance.now(),
          maxGap = 0,
          ticks = 0
        const timer = setInterval(() => {
          const now = performance.now()
          maxGap = Math.max(maxGap, now - last)
          last = now
          ticks++
        }, 20)
        const stages = []
        try {
          const result = await createBrowserPdfAdapter()({
            projectId: input.project,
            client: false,
            options: {
              format: 'pdf',
              scope: 'all',
              locale: input.locale,
              fields: input.fields,
              pageBreakBySection: input.pageBreakBySection,
            },
            onStage: (stage) => stages.push(stage),
          })
          let base64
          if (input.save) {
            const bytes = new Uint8Array(await result.blob.arrayBuffer())
            let binary = ''
            for (let i = 0; i < bytes.length; i += 16384)
              binary += String.fromCharCode(...bytes.subarray(i, i + 16384))
            base64 = btoa(binary)
          }
          return {
            ...result.metrics,
            outputBytes: result.blob.size,
            maxMainThreadGapMs: maxGap,
            heartbeatTicks: ticks,
            stages,
            base64,
          }
        } finally {
          clearInterval(timer)
        }
      },
      { project, locale, fields: fields === 'full' ? full : defaults, save, pageBreakBySection },
    )
    await apiResponse.finished()
    const json = await apiResponse.body()
    sample()
    Object.assign(record, value, {
      observedWindowMs: performance.now() - observedStarted,
      browserBaselineRssBytes: before.reduce((sum, row) => sum + row.rss, 0),
      browserPeakRssBytesSampled: peakRss,
      rssSamples,
      browserCpuSecondsSampled: [...observed].reduce(
        (sum, [pid, cpu]) => sum + cpu - (cpuBefore.get(pid) || 0),
        0,
      ),
      apiBytes: json.length,
      phpPeakBytes: Number(apiResponse.headers()['x-benchmark-php-peak']),
      serverTiming: apiResponse.headers()['server-timing'],
    })
    assert.equal(apiResponse.status(), 200)
    assert.ok(record.outputBytes > 1000)
    const payload = JSON.parse(json.toString())
    assert.equal(payload.document.totals.taskCount, tasks)
    if (save) {
      const name = `${samplePrefix}browser-${profile}-${tasks}-${fields}-${locale}.pdf`
      await writeFile(path.join(output, name), Buffer.from(value.base64, 'base64'))
      await writeFile(
        path.join(output, name.replace('.pdf', '.json')),
        JSON.stringify(payload.document, null, 2) + '\n',
      )
      record.sample = name
    }
    delete record.base64
    return record
  } finally {
    clearInterval(monitor)
    page.off('response', responseListener)
  }
}
try {
  await vite.listen()
  for (let attempt = 0; attempt < 100; attempt++) {
    try {
      if ((await fetch(backendOrigin + '/api/health')).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100))
  }
  browserServer = await chromium.launchServer({ headless: true })
  browserPid = browserServer.process().pid
  browser = await chromium.connect(browserServer.wsEndpoint())
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    locale: 'en-GB',
    acceptDownloads: true,
  })
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  await login(page)
  if (process.env.M61_REFERENCE === '1') {
    for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
      const reference = await page.evaluate(
        async ({ project, locale, fields, apiModuleUrl }) => {
          const { api } = await import(apiModuleUrl)
          const options = { format: 'pdf', scope: 'all', locale, fields }
          const data = await api.request(`/api/admin/projects/${project}/export-data`, {
            method: 'POST',
            body: options,
          })
          const result = await api.request(`/api/admin/projects/${project}/export`, {
            method: 'POST',
            body: options,
            responseType: 'blob',
          })
          const bytes = new Uint8Array(await result.blob.arrayBuffer())
          let binary = ''
          for (let offset = 0; offset < bytes.length; offset += 16384)
            binary += String.fromCharCode(...bytes.subarray(offset, offset + 16384))
          return { base64: btoa(binary), document: data.document }
        },
        {
          project: fixtures['cjk-10'].project,
          locale,
          fields: full,
          apiModuleUrl: '/@fs' + path.join(root, 'src/api/client.js'),
        },
      )
      const name = `dompdf-cjk-10-full-${locale}`
      await writeFile(path.join(output, name + '.pdf'), Buffer.from(reference.base64, 'base64'))
      await writeFile(
        path.join(output, name + '.json'),
        JSON.stringify(reference.document, null, 2) + '\n',
      )
      console.log('Server reference passed', locale)
    }
  } else if (process.env.M61_SMOKE === '1') {
    const record = await generate(page, { save: true, tasks: process.env.M61_MAIN ? 500 : 10 })
    results.push(record)
    console.log(JSON.stringify(record))
  } else if (process.env.M61_COMPAT !== '1') {
    for (const profile of ['mixed', 'cjk'])
      for (const tasks of [10, 100, 500])
        for (const fields of ['default', 'full'])
          for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
            const target =
              origin + '/admin/projects/' + fixtures[profile + '-' + tasks].project + '/export'
            if (page.url() !== target) {
              await page.goto(target)
              await page.locator('.export-form').waitFor()
            }
            const record = await generate(page, {
              profile,
              tasks,
              fields,
              locale,
              save:
                (tasks === 10 && fields === 'full') ||
                (tasks === 500 && profile === 'cjk' && fields === 'full' && locale === 'zh-CN'),
            })
            results.push(record)
            console.log(
              'Passed',
              profile,
              tasks,
              fields,
              locale,
              Math.round(record.renderMs),
              'ms',
              record.outputBytes,
              'bytes',
            )
            await writeFile(
              path.join(output, 'chromium-matrix.json'),
              JSON.stringify({ results, errors }, null, 2) + '\n',
            )
          }
    const edge = await generate(page, {
      profile: 'cjk-edge',
      tasks: 2,
      save: true,
      pageBreakBySection: true,
    })
    await writeFile(path.join(output, 'edge-case.json'), JSON.stringify(edge, null, 2) + '\n')
    console.log('Long Chinese paragraph and unbroken URL passed')
  }
  assert.deepEqual(errors, [])
  await context.close()
  if (process.env.M61_COMPAT === '1') {
    await closeTestBrowser()
    const compatibility = [],
      unavailable = []
    for (const engine of [chromium, firefox, webkit].filter(
      (engine) =>
        !process.env.M61_ENGINES || process.env.M61_ENGINES.split(',').includes(engine.name()),
    )) {
      browserServer = await engine.launchServer({ headless: true })
      browserPid = browserServer.process().pid
      browser = await engine.connect(browserServer.wsEndpoint())
      try {
        for (const width of [1440, 390]) {
          for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
            const local = await browser.newContext({
              viewport: { width, height: width === 390 ? 844 : 1000 },
              locale: { ru: 'ru-RU', en: 'en-GB', tr: 'tr-TR', 'zh-CN': 'zh-CN' }[locale],
              acceptDownloads: true,
              ...(width === 390
                ? { hasTouch: true, ...(engine.name() !== 'firefox' ? { isMobile: true } : {}) }
                : {}),
            })
            let pageTimer
            const screen = await Promise.race([
              local.newPage(),
              new Promise((_, reject) => {
                pageTimer = setTimeout(() => reject(new Error('empty_page_timeout_20s')), 20000)
              }),
            ]).finally(() => clearTimeout(pageTimer))
            screen.on('pageerror', (error) => errors.push(engine.name() + ': ' + error.message))
            await login(screen)
            await screen.goto(origin + '/admin/projects/' + fixtures['cjk-10'].project + '/export')
            await screen.locator('.export-form').waitFor()
            await screen.locator('.language-switcher select').selectOption(locale)
            await screen.waitForFunction((value) => document.documentElement.lang === value, locale)
            await screen.locator('input[name=language][value="' + locale + '"]').check()
            for (const input of await screen.locator('form fieldset').nth(3).locator('input').all())
              await input.check()
            assert.ok(
              await screen.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
            )
            const label = `${engine.name()}-${width}-${locale}`
            await screen.evaluate(async () => {
              document.activeElement?.blur()
              scrollTo(0, 0)
              await new Promise(requestAnimationFrame)
              await new Promise(requestAnimationFrame)
            })
            await screen.screenshot({
              path: path.join(output, label + '-admin.png'),
              fullPage: false,
            })
            const download = screen.waitForEvent('download', { timeout: 60000 })
            const uiStarted = Date.now()
            await screen.locator('form button[type=submit]').click()
            const file = await download
            await file.saveAs(path.join(output, label + '-ui.pdf'))
            assert.equal(await file.failure(), null)
            const record = {
              engine: engine.name(),
              version: browser.version(),
              width,
              locale,
              uiDownloadMs: Date.now() - uiStarted,
              originalContent: true,
            }
            if (engine.name() === 'chromium' && width === 1440 && locale === 'en') {
              await screen.evaluate(() => caches.delete('projectbrief-pdf-public-fonts-v1'))
              await screen.route('**/*NotoSansSC-Regular*', (route) => route.abort())
              await screen.locator('form button[type=submit]').click()
              await screen.locator('form [role=alert]').waitFor()
              await screen.unroute('**/*NotoSansSC-Regular*')
              const retried = screen.waitForEvent('download')
              await screen.locator('form button[type=submit]').click()
              assert.equal(await (await retried).failure(), null)
              record.errorRetry = true
            }
            if (locale === 'zh-CN') {
              await screen.goto(
                origin + '/admin/projects/' + fixtures['cjk-500'].project + '/export',
              )
              await screen.locator('.export-form').waitFor()
              Object.assign(record, {
                mainCase: await generate(screen, {
                  tasks: 500,
                  save: true,
                  samplePrefix: label + '-',
                }),
              })
              const cancellation = await screen.evaluate(async (projectId) => {
                const { createBrowserPdfAdapter } = await import('/adapter.js')
                const controller = new AbortController()
                try {
                  await createBrowserPdfAdapter()({
                    projectId,
                    options: { format: 'pdf', scope: 'all', locale: 'zh-CN' },
                    signal: controller.signal,
                    onStage: (stage) => {
                      if (stage === 'generating') controller.abort()
                    },
                  })
                  return false
                } catch (error) {
                  return error.name === 'AbortError'
                }
              }, fixtures['cjk-500'].project)
              assert.equal(cancellation, true)
              record.cancelledInWorker = true
              if (engine.name() === 'chromium') {
                for (const input of await screen
                  .locator('form fieldset')
                  .nth(3)
                  .locator('input')
                  .all())
                  await input.check()
                const pendingDownload = screen.waitForEvent('download')
                await screen.locator('form button[type=submit]').click()
                await screen.locator('form [role=status]').waitFor()
                await screen.locator('form [role=status]').scrollIntoViewIfNeeded()
                await screen.screenshot({ path: path.join(output, label + '-progress.png') })
                assert.equal(await (await pendingDownload).failure(), null)
                await screen.locator('form button[type=submit]').click()
                await screen.locator('form button[type=button]').waitFor()
                await screen.locator('form button[type=button]').click()
                await screen.locator('form [role=status]').waitFor({ state: 'detached' })
                assert.equal(await screen.locator('form button[type=submit]').isEnabled(), true)
                record.cancelledInUi = true
              }
            }
            if (engine.name() === 'chromium') {
              await screen.goto(origin + '/access/' + fixtures['cjk-10'].token)
              await screen.waitForURL(origin + '/project/' + fixtures['cjk-10'].project)
              await screen.goto(origin + '/project/' + fixtures['cjk-10'].project + '/export')
              await screen.locator('.export-form').waitFor()
              assert.equal(await screen.locator('form fieldset').nth(3).locator('input').count(), 6)
              const payload = await screen.evaluate(
                async ({ project, fields, locale, apiModuleUrl }) => {
                  const { api } = await import(apiModuleUrl)
                  return api.request('/api/client/project/' + project + '/export-data', {
                    method: 'POST',
                    body: { format: 'pdf', scope: 'all', locale, fields },
                  })
                },
                {
                  project: fixtures['cjk-10'].project,
                  fields: full,
                  locale,
                  apiModuleUrl: '/@fs' + path.join(root, 'src/api/client.js'),
                },
              )
              assert.doesNotMatch(
                JSON.stringify(payload),
                /PRIVATE_INTERNAL_NOTES|PRIVATE_STORAGE_PATH|estimateHours|developerNotes|token_hash|workspace_id|"price"/,
              )
              await screen.evaluate(async () => {
                document.activeElement?.blur()
                scrollTo(0, 0)
                await new Promise(requestAnimationFrame)
                await new Promise(requestAnimationFrame)
              })
              await screen.screenshot({
                path: path.join(output, label + '-client.png'),
                fullPage: false,
              })
              record.clientFieldsSafe = true
              for (const input of await screen
                .locator('form fieldset')
                .nth(3)
                .locator('input')
                .all())
                await input.check()
              const clientDownload = screen.waitForEvent('download')
              await screen.locator('form button[type=submit]').click()
              const clientFile = await clientDownload
              await clientFile.saveAs(path.join(output, label + '-client-ui.pdf'))
              await writeFile(
                path.join(output, label + '-client-ui.json'),
                JSON.stringify(payload.document, null, 2) + '\n',
              )
            }
            assert.equal(
              await screen.evaluate(() =>
                [...Object.values(localStorage), ...Object.values(sessionStorage)].some((value) =>
                  /PRIVATE_INTERNAL_NOTES|Website improvement|原始中文需求/.test(value),
                ),
              ),
              false,
            )
            assert.equal(
              await screen.evaluate(async () => {
                const cache = await caches.open('projectbrief-pdf-public-fonts-v1')
                return (await cache.keys()).every((request) =>
                  new URL(request.url).pathname.endsWith('.ttf'),
                )
              }),
              true,
            )
            record.publicFontCacheSafe = true
            compatibility.push(record)
            await writeFile(
              path.join(output, 'compatibility.json'),
              JSON.stringify({ results: compatibility, errors, unavailable }, null, 2) + '\n',
            )
            console.log('Compatibility passed', label)
            await local.close()
          }
        }
      } catch (error) {
        if (engine.name() !== 'webkit') throw error
        unavailable.push({
          engine: 'webkit',
          stage: 'empty-page-before-application',
          reason: error.message,
          status: 'not verified',
        })
        console.log('WebKit unavailable:', error.message)
      } finally {
        await closeTestBrowser()
      }
    }
    await writeFile(
      path.join(output, 'compatibility.json'),
      JSON.stringify(
        {
          results: compatibility,
          errors,
          unavailable,
          status: unavailable.length ? 'partial' : 'passed',
        },
        null,
        2,
      ) + '\n',
    )
    assert.deepEqual(errors, [])
  }
} finally {
  await closeTestBrowser()
  await vite.close()
  backend.kill('SIGTERM')
  await writeFile(path.join(output, 'backend-errors.log'), backendLog)
  await rm(scratch, { recursive: true, force: true })
}
