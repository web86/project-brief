import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer } from 'vite'
import ru from '../src/locales/ru.js'
import en from '../src/locales/en.js'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = new URL('../docs/milestone-4-8-3/', import.meta.url)
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone483-e2e-'))
const database = path.join(scratch, 'test.sqlite')
await writeFile(database, '')
const origin = 'http://127.0.0.1:5194'
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
    port: 5194,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/release/**'] },
    proxy: { '/api': 'http://127.0.0.1:8019', '/access/': 'http://127.0.0.1:8019' },
  },
  logLevel: 'error',
})
let backend, browser
const results = [],
  errors = []
async function call(page, url) {
  return page.evaluate(
    async (url) => (await fetch(url, { headers: { Accept: 'application/json' } })).json(),
    url,
  )
}
async function navigate(page, url) {
  await page.evaluate(async (url) => {
    const { default: router } = await import('/src/router/index.js')
    await router.push(url)
  }, url)
}
try {
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(run(['tests/milestone483-fixture.php']))
  backend = spawn(
    'php',
    [
      '-S',
      '127.0.0.1:8019',
      root + '/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    { cwd: root + '/api/public', env, stdio: ['ignore', 'ignore', 'pipe'] },
  )
  backend.stderr.on('data', () => {})
  await vite.listen()
  for (let i = 0; i < 50; i++) {
    try {
      if ((await fetch('http://127.0.0.1:8019/api/health')).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100))
  }
  browser = await chromium.launch({ headless: true })
  for (const persona of ['owner', 'developer', 'client'])
    for (const width of [1440, 390])
      for (const lang of ['ru', 'en'])
        for (const theme of ['light', 'dark']) {
          run(['tests/milestone483-fixture.php', 'reset'])
          const context = await browser.newContext({
            viewport: { width, height: width === 390 ? 844 : 1000 },
            locale: lang === 'ru' ? 'ru-RU' : 'en-GB',
            colorScheme: theme,
            hasTouch: width === 390,
            reducedMotion: 'reduce',
          })
          await context.addInitScript(
            ({ lang, theme }) => {
              localStorage.setItem('projectbrief.locale', lang)
              localStorage.setItem('projectbrief.theme', theme)
            },
            { lang, theme },
          )
          const page = await context.newPage(),
            messages = lang === 'ru' ? ru : en
          page.on('pageerror', (error) => errors.push(error.message))
          const discussionRequests = []
          page.on('request', (request) => {
            if (new URL(request.url()).pathname.endsWith('/discussions'))
              discussionRequests.push(request.method())
          })
          const viewRoot =
            persona === 'client'
              ? `/project/${fixture.project}`
              : `/admin/projects/${fixture.project}`
          const apiRoot =
            persona === 'client'
              ? `/api/client/project/${fixture.project}`
              : `/api/admin/projects/${fixture.project}`
          if (persona === 'client') {
            await page.goto(origin + '/access/' + fixture.token)
            await page.waitForURL(origin + viewRoot)
            await page.locator('.project-header').waitFor()
          } else {
            await page.goto(origin + '/admin/login')
            await page.locator('#admin-email').fill(persona + '@example.test')
            await page.locator('#admin-password').fill('Local-e2e-password!')
            await page.locator('form button[type=submit]').click()
            await page.waitForURL(origin + '/admin')
          }
          await navigate(page, viewRoot + '/discussions')
          const tabs = page.getByRole('tab'),
            panel = () => page.locator('[role=tabpanel]:visible')
          await tabs.first().waitFor()
          assert.equal(await tabs.count(), 2)
          assert.equal(await tabs.first().getAttribute('aria-selected'), 'true')
          assert.equal(await panel().count(), 1)
          assert.deepEqual(await tabs.locator('.discussion-badge').allTextContents(), ['70', '70'])
          assert.equal(discussionRequests.length, 2)
          assert.equal(await page.locator('.discussion-page .filter-tabs').count(), 0)
          const label = `${persona}-${width}-${lang}-${theme}`
          const shot = async (kind) => {
            await page.evaluate(() => {
              document.activeElement?.blur()
              window.scrollTo(0, 0)
            })
            await page.screenshot({ path: new URL(`${kind}-${label}.png`, output).pathname })
          }
          await shot('general')
          await tabs.nth(1).click()
          assert.equal(await panel().count(), 1)
          assert.equal(await panel().locator('.discussion-item').count(), 25)
          assert.equal(discussionRequests.length, 2, 'Tab selection makes no request')
          assert.ok(
            await panel()
              .getByRole('link', { name: messages.discussions.openTask })
              .first()
              .getAttribute('href'),
          )
          await shot('tasks')
          const geometry = await tabs.evaluateAll((nodes) =>
            nodes.map((node) => ({
              x: node.getBoundingClientRect().x,
              y: node.getBoundingClientRect().y,
              width: node.getBoundingClientRect().width,
            })),
          )
          assert.equal(geometry[0].y, geometry[1].y)
          assert.ok(Math.abs(geometry[0].width - geometry[1].width) <= 1)
          assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
          await tabs.nth(1).focus()
          await page.keyboard.press('ArrowLeft')
          assert.equal(await tabs.first().getAttribute('aria-selected'), 'true')
          assert.equal(await tabs.first().evaluate((node) => node === document.activeElement), true)
          const draft = 'E2E sent ' + label
          await page.locator('#project-comment').fill(draft)
          await tabs.nth(1).click()
          await tabs.first().click()
          assert.equal(await page.locator('#project-comment').inputValue(), draft)
          assert.equal((await call(page, apiRoot + '/discussions')).unreadCount, 140)
          await panel()
            .getByRole('button', { name: messages.discussions.more, exact: true })
            .click()
          await page.waitForFunction(
            () =>
              document
                .querySelector('#discussion-panel-general')
                .querySelectorAll('.discussion-item').length === 50,
          )
          await tabs.nth(1).click()
          assert.equal(await panel().locator('.discussion-item').count(), 25)
          await panel()
            .getByRole('button', { name: messages.discussions.more, exact: true })
            .click()
          await page.waitForFunction(
            () =>
              document.querySelector('#discussion-panel-tasks').querySelectorAll('.discussion-item')
                .length === 50,
          )
          await panel()
            .getByRole('button', { name: messages.discussions.markRead, exact: true })
            .first()
            .click()
          await page.waitForFunction(
            () =>
              document.querySelector('#discussion-tab-tasks .discussion-badge')?.textContent ===
              '69',
          )
          const globalBadge =
            persona === 'client'
              ? page.locator('.client-sidebar .unread-badge, #client-drawer .unread-badge')
              : page.locator('.project-panel-nav .discussion-badge')
          assert.ok(await globalBadge.count())
          if (persona === 'client')
            assert.ok(
              (
                await globalBadge.evaluateAll((nodes) =>
                  nodes.map((node) => node.getAttribute('aria-label')),
                )
              ).every((text) => text.includes('139')),
            )
          else assert.ok((await globalBadge.allTextContents()).every((text) => text === '139'))
          await panel()
            .getByRole('button', { name: messages.discussions.markTab, exact: true })
            .click()
          await page.waitForFunction(
            () => !document.querySelector('#discussion-tab-tasks .discussion-badge'),
          )
          assert.equal(await tabs.first().locator('.discussion-badge').innerText(), '70')
          await tabs.first().click()
          await panel()
            .getByRole('button', { name: messages.discussions.markTab, exact: true })
            .click()
          await page.waitForFunction(
            () => !document.querySelector('.discussion-tabs .discussion-badge'),
          )
          await page.locator('.comment-form button[type=submit]').click()
          await page.getByText(draft, { exact: true }).waitFor()
          assert.equal(await page.locator('#project-comment').inputValue(), '')
          for (const [kind, target] of [
            ['tasks', fixture.taskTarget],
            ['general', fixture.generalTarget],
          ]) {
            await navigate(page, viewRoot + '/discussions#comment-' + target)
            await page.locator('#comment-' + target).waitFor()
            assert.equal(
              await page.locator('#discussion-tab-' + kind).getAttribute('aria-selected'),
              'true',
            )
            assert.equal(
              await page
                .locator('#comment-' + target)
                .evaluate((node) => node === document.activeElement),
              true,
            )
            assert.equal((await call(page, apiRoot + '/discussions')).unreadCount, 0)
            await panel()
              .getByRole('button', { name: messages.discussions.showLatest, exact: true })
              .click()
          }
          await navigate(
            page,
            viewRoot + '/discussions#comment-00000000-0000-4000-8000-000000000000',
          )
          await page
            .getByRole('alert')
            .filter({ hasText: messages.discussions.targetUnavailable })
            .waitFor()
          if (persona !== 'client') {
            await navigate(page, `/admin/projects/${fixture.second}/discussions`)
            await page.waitForFunction(
              () =>
                document.querySelector('#discussion-tab-general .discussion-badge')?.textContent ===
                '2',
            )
            assert.equal(
              await page.locator('.discussion-item').filter({ hasText: 'E2E sent' }).count(),
              0,
            )
            assert.equal(await page.locator('#discussion-tab-tasks .discussion-badge').count(), 0)
          }
          assert.deepEqual(errors, [])
          results.push({
            persona,
            width,
            language: lang,
            theme,
            passed: true,
            countsBeyondPagination: true,
            scopedRead: true,
            keyboard: true,
            drafts: true,
            independentPagination: true,
            deepLinks: true,
          })
          await context.close()
        }
  console.log(`PASS ${results.length} real Laravel discussion configurations`)
} catch (error) {
  console.error(error)
  process.exitCode = 1
  if (browser)
    for (const context of browser.contexts())
      for (const page of context.pages())
        await page.screenshot({ path: new URL('failure.png', output).pathname }).catch(() => {})
} finally {
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify({ realLaravelApi: true, results, errors }, null, 2) + '\n',
  )
  await browser?.close()
  backend?.kill()
  await vite.close()
  await rm(scratch, { recursive: true, force: true })
}
