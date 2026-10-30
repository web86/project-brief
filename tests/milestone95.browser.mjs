import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { cp, mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises'
import { randomBytes } from 'node:crypto'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const mysql = process.env.M95_QA_MYSQL === '1'
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-editor-'))
const app = path.join(scratch, 'app'),
  output = path.join(tmpdir(), mysql ? 'project-brief-m95-mariadb-qa' : 'project-brief-m95-qa')
await mkdir(app)
await mkdir(output, { recursive: true })
async function freePort() {
  const socket = net.createServer()
  await new Promise((resolve) => socket.listen(0, '127.0.0.1', resolve))
  const port = socket.address().port
  await new Promise((resolve) => socket.close(resolve))
  return port
}
const apiPort = await freePort(),
  webPort = await freePort(),
  origin = `http://127.0.0.1:${webPort}`
const env = {
  PATH: process.env.PATH,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: mysql ? 'mysql' : 'sqlite',
  DB_DATABASE: mysql ? 'projectbrief_m95_browser_qa' : path.join(scratch, 'test.sqlite'),
  ...(mysql
    ? {
        DB_HOST: '127.0.0.1',
        DB_PORT: process.env.M95_QA_MYSQL_PORT || '32769',
        DB_USERNAME: 'briefqa',
        DB_PASSWORD: 'Disposable-m95-only',
        PHP_CLI_SERVER_WORKERS: '4',
      }
    : {}),
  DB_URL: '',
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'agreement_editor_qa',
  SESSION_SECURE_COOKIE: 'false',
  LOG_CHANNEL: 'null',
  MAIL_MAILER: 'array',
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
let backend, frontend, browser
const errors = [],
  results = []
try {
  for (const entry of [
    'app',
    'config',
    'database',
    'lang',
    'resources',
    'routes',
    'bootstrap',
    'public',
    'artisan',
    'composer.json',
    'composer.lock',
  ])
    await cp(path.join(root, 'api', entry), path.join(app, entry), {
      recursive: true,
      filter: (name) => !/(?:^|\/)(?:\.DS_Store|cache\/[^/]+|[^/]*\.sqlite[^/]*)$/.test(name),
    })
  await symlink(path.join(root, 'api/vendor'), path.join(app, 'vendor'))
  for (const entry of [
    'tests',
    'bootstrap/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/app/private',
    'storage/logs',
  ])
    await mkdir(path.join(app, entry), { recursive: true })
  await cp(
    path.join(root, 'api/tests/live-sync-fixture.php'),
    path.join(app, 'tests/live-sync-fixture.php'),
  )
  await writeFile(path.join(app, '.env'), '')
  if (!mysql) await writeFile(env.DB_DATABASE, '')
  if (mysql) {
    const file = path.join(app, 'tests/live-sync-fixture.php')
    const { readFile } = await import('node:fs/promises')
    const fixtureSource = (await readFile(file, 'utf8')).replace(
      "config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')",
      "config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'projectbrief_m95_browser_qa'",
    )
    await writeFile(file, fixtureSource)
  }
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/live-sync-fixture.php']))
  backend = spawn('php', ['-S', `127.0.0.1:${apiPort}`, '-t', 'public', 'public/index.php'], {
    cwd: app,
    env,
    stdio: 'ignore',
    detached: mysql,
  })
  frontend = await createServer({
    root,
    cacheDir: path.join(scratch, 'vite-cache'),
    mode: 'test',
    define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
    server: {
      host: '127.0.0.1',
      port: webPort,
      strictPort: true,
      hmr: false,
      proxy: Object.fromEntries(
        ['/api', '/access/', '/branding-assets/'].map((prefix) => [
          prefix,
          `http://127.0.0.1:${apiPort}`,
        ]),
      ),
    },
    logLevel: 'error',
  })
  await frontend.listen()
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({
    viewport: { width: 1440, height: 980 },
    reducedMotion: 'reduce',
  })
  await context.addInitScript(() => {
    localStorage.setItem('projectbrief.locale', 'en')
    localStorage.setItem('projectbrief.theme', 'light')
  })
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  async function call(url, method = 'GET', data) {
    const headers = { Accept: 'application/json' }
    if (method !== 'GET')
      headers['X-CSRF-TOKEN'] = (
        await (await context.request.get(origin + '/api/csrf')).json()
      ).token
    const response = await context.request.fetch(origin + url, {
      method,
      headers,
      ...(data ? { data } : {}),
    })
    assert.ok(response.ok(), `${url}: ${response.status()} ${await response.text()}`)
    return response.json()
  }
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill('owner@example.test')
  await page.locator('#admin-password').fill('Local-test-password!')
  await page.locator('form button[type=submit]').click()
  await page.waitForURL('**/admin/security')
  await call('/api/security/challenge', 'POST', { recovery_code: 'Live-browser-code-0' })
  const projectRoot = '/api/admin/projects/' + fixture.project
  const initial = await call('/api/admin/settings/agreement')
  assert.deepEqual(initial.data.draft.enabledLocales, ['ru'])
  await page.goto(origin + '/admin/settings/agreement')
  await page.locator('.editor-content').waitFor()
  assert.equal(await page.locator('.editor-content h2').count(), 9)
  assert.equal(await page.locator('.editor-tools button').count(), 9)
  page.once('dialog', (dialog) => dialog.accept())
  await page.getByRole('button', { name: 'Fill with example', exact: true }).click()
  assert.equal(await page.locator('.editor-content h2').count(), 9)
  page.once('dialog', (dialog) => dialog.accept())
  await page.locator('.agreement-language-controls input[type=checkbox]').first().click()
  assert.equal(
    await page.locator('.agreement-language-controls input[type=checkbox]').first().isChecked(),
    true,
  )
  const switches = page.locator('.agreement-language-controls input[type=checkbox]')
  await switches.nth(1).check()
  await page.getByRole('tab', { name: 'EN', exact: true }).click()
  await page.locator('.editor-content').fill('English agreement: safe link example.')
  await page.getByRole('button', { name: 'Save and publish', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Saved' }).waitFor()
  const published = await call('/api/admin/settings/agreement')
  assert.deepEqual(published.data.published.enabledLocales, ['ru', 'en'])
  assert.equal(
    published.data.published.texts.en[0].content[0].text,
    'English agreement: safe link example.',
  )
  results.push('Templates, language toggle, editor changes, publish and reload')
  const admins = await call('/api/admin/administrators')
  const developer = admins.members.find((row) => row.user?.email === 'developer@example.test')
  assert.ok(developer)
  await call('/api/admin/administrators/members/' + developer.id, 'PATCH', {
    projects: [fixture.project],
    isProjectSuperadmin: true,
  })
  await page.goto(origin + '/admin/projects/' + fixture.project + '/team')
  await page.locator('.project-team-member').waitFor()
  assert.ok(
    (await page.locator('.project-team-member').textContent()).includes('Northline Developer'),
  )
  await page.goto(origin + '/admin/projects/' + fixture.project + '/discussions')
  await page.locator('.team-chat').waitFor()
  const post = await call(projectRoot + '/team-chat', 'POST', {
    text: 'Private team message — русский / Türkçe / 中文',
    submissionId: crypto.randomUUID(),
  })
  await page.locator('.team-chat-heading button').click()
  await page.locator('#team-' + post.data.id).waitFor()
  await page.locator('.team-chat textarea').fill('Sent from the real chat composer')
  await page.locator('.team-chat .comment-form button[type=submit]').click()
  await page.getByText('Sent from the real chat composer', { exact: true }).waitFor()
  const payload = { text: 'Concurrent retry from two tabs', submissionId: crypto.randomUUID() }
  const otherTab = await context.newPage()
  await otherTab.goto(origin + '/admin/projects/' + fixture.project + '/discussions')
  await otherTab.locator('.team-chat').waitFor()
  const postFromTab = (tab) =>
    tab.evaluate(
      async ({ root, payload }) => {
        const csrf = await (
          await fetch('/api/csrf', { headers: { Accept: 'application/json' } })
        ).json()
        const response = await fetch(root + '/team-chat', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf.token,
          },
          body: JSON.stringify(payload),
        })
        return { status: response.status, body: await response.json() }
      },
      { root: projectRoot, payload },
    )
  const attempts = await Promise.all([postFromTab(page), postFromTab(otherTab)])
  assert.equal(attempts[0].status, 201)
  assert.equal(attempts[1].status, 201)
  assert.equal(attempts[0].body.data.id, attempts[1].body.data.id)
  await otherTab.locator('.team-chat-heading button').click()
  await otherTab.locator('#team-' + attempts[0].body.data.id).waitFor()
  assert.equal((await call(projectRoot + '/discussions?kind=general')).data.length, 0)
  results.push('Team card, private chat read/send and concurrent retry in two real tabs')
  const invitation = await call('/api/admin/administrators/invitations', 'POST', {
    email: 'm95-invited@example.test',
    name: 'Invited colleague',
    sendEmail: false,
    generateLink: true,
    projects: [],
  })
  const invitedContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    reducedMotion: 'reduce',
  })
  await invitedContext.addInitScript(() => {
    localStorage.setItem('projectbrief.locale', 'en')
    localStorage.setItem('projectbrief.theme', 'dark')
  })
  const invited = await invitedContext.newPage()
  invited.on('pageerror', (e) => errors.push(e.message))
  await invited.goto(invitation.link)
  await invited.locator('.invitation-page form').waitFor()
  await invited.locator('.workspace-brand-logo').waitFor()
  assert.ok((await invited.locator('.workspace-brand-logo').textContent()).includes('Northline'))
  await invited.reload()
  await invited.locator('.invitation-page form').waitFor()
  assert.ok((await invited.locator('.workspace-brand-logo').textContent()).includes('Northline'))
  results.push('Verified invitation branding and token-free refresh')
  const clientContext = await browser.newContext()
  const clientPage = await clientContext.newPage()
  await clientPage.goto(origin + '/access/' + fixture.token)
  await clientPage.waitForURL('**/project/**')
  await clientPage.goto(origin + '/project/' + fixture.project + '/discussions')
  await clientPage.locator('.discussion-tabs').waitFor()
  assert.equal(await clientPage.locator('.team-chat').count(), 0)
  assert.equal(await clientPage.locator('.discussion-tabs [role=tab]').count(), 1)
  const denied = await clientContext.request.get(origin + projectRoot + '/team-chat')
  assert.equal(denied.status(), 401)
  assert.ok(!(await clientPage.content()).includes('Private team message'))
  results.push('Client UI and direct private API denied')
  const pages = [
    ['/admin/settings/agreement', 'agreement', '.editor-content'],
    ['/admin/administrators', 'administrators', '.administrator-card'],
    ['/admin/projects/' + fixture.project + '/team', 'team', '.project-team-member'],
    ['/admin/projects/' + fixture.project + '/discussions', 'chat', '.team-chat'],
    ['/admin/security', 'security', '.account-security-panel'],
    ['/w/' + fixture.workspace + '/agreement', 'public', '.agreement-document'],
  ]
  let captures = 0
  for (const lang of ['ru', 'en', 'tr', 'zh-CN'])
    for (const width of [1440, 390])
      for (const mode of ['light', 'dark', 'system']) {
        await page.setViewportSize({ width, height: 980 })
        await page.emulateMedia({ colorScheme: mode === 'light' ? 'light' : 'dark' })
        await page.evaluate(
          ({ lang, mode }) => {
            localStorage.setItem('projectbrief.locale', lang)
            localStorage.setItem('projectbrief.theme', mode)
          },
          { lang, mode },
        )
        for (const [url, name, ready] of pages) {
          await page.goto(origin + url)
          await page.locator(ready).first().waitFor()
          if (name === 'public') {
            await page.locator('.workspace-brand-logo').waitFor()
            assert.ok(
              (await page.locator('.workspace-brand-logo').textContent()).includes('Northline'),
            )
          }
          await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
          await page.evaluate(
            async ({ lang, mode }) => {
              ;(await import('/src/i18n/index.js')).setManualLocale(lang)
              ;(await import('/src/services/theme.js')).theme.select(mode)
            },
            { lang, mode },
          )
          assert.equal(await page.locator('html').getAttribute('lang'), lang)
          assert.equal(
            await page.locator('html').getAttribute('data-theme'),
            mode === 'light' ? 'light' : 'dark',
          )
          await page.evaluate(() => document.fonts.ready)
          await page.waitForTimeout(230)
          assert.ok(
            await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
            name + ' ' + lang + ' overflow ' + width,
          )
          await page.screenshot({
            path: path.join(output, `${name}-${lang}-${width}-${mode}.png`),
            fullPage: true,
          })
          captures++
          if (name === 'administrators') {
            await page
              .locator('.administrator-card')
              .filter({ hasText: 'developer@example.test' })
              .getByRole('button')
              .first()
              .click()
            const modal = page.locator('.administrator-dialog')
            await modal.waitFor()
            assert.equal(await modal.locator('[role=switch]').count(), 1)
            assert.ok(await modal.evaluate((el) => el.getBoundingClientRect().right <= innerWidth))
            await page.screenshot({
              path: path.join(output, `administrator-modal-${lang}-${width}-${mode}.png`),
              fullPage: true,
            })
            captures++
            await page.keyboard.press('Escape')
            await modal.waitFor({ state: 'hidden' })
          }
          if (name === 'team') {
            await page.locator('.project-team-invite > summary').click()
            assert.ok(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
            )
            await page.screenshot({
              path: path.join(output, `project-invite-${lang}-${width}-${mode}.png`),
              fullPage: true,
            })
            captures++
          }
        }
      }
  await invited.screenshot({
    path: path.join(output, 'invitation-en-390-dark.png'),
    fullPage: true,
  })
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'RESULT.json'),
    JSON.stringify({ passed: true, results, captures: captures + 1, errors }, null, 2),
  )
  console.log(JSON.stringify({ passed: true, results, captures: captures + 1, output }))
} finally {
  await browser?.close()
  await frontend?.close()
  if (backend) {
    try {
      if (mysql) process.kill(-backend.pid, 'SIGTERM')
      else backend.kill('SIGTERM')
    } catch {}
  }
  await rm(scratch, { recursive: true, force: true })
}
