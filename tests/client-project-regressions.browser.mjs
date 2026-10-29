import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = fileURLToPath(new URL('..', import.meta.url))
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-client-regressions-'))
const app = path.join(scratch, 'app')
const baseline = process.env.PROJECT_REGRESSION_BASELINE === '1'
const output = path.join(
  root,
  'artifacts/client-project-regressions',
  baseline ? 'before' : 'after',
)
await mkdir(app)
await mkdir(output, { recursive: true })
let backend, frontend, browser
const results = [],
  errors = []
async function port() {
  const s = net.createServer()
  await new Promise((resolve) => s.listen(0, '127.0.0.1', resolve))
  const result = s.address().port
  await new Promise((resolve) => s.close(resolve))
  return result
}
const apiPort = await port(),
  webPort = await port(),
  origin = `http://127.0.0.1:${webPort}`
const env = {
  PATH: process.env.PATH,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(scratch, 'test.sqlite'),
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'client_regression_session',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'array',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'null',
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
async function call(context, url) {
  const response = await context.request.get(origin + url, {
    headers: { Accept: 'application/json' },
  })
  assert.ok(response.ok(), url + ' ' + response.status())
  return response.json()
}
async function context(locale = 'en', width = 1440) {
  const context = await browser.newContext({
    viewport: { width, height: 980 },
    reducedMotion: 'reduce',
  })
  await context.addInitScript((locale) => {
    try {
      localStorage.setItem('projectbrief.locale', locale)
      localStorage.setItem('projectbrief.theme', 'light')
    } catch {}
  }, locale)
  context.on('page', (page) => page.on('pageerror', (error) => errors.push(String(error))))
  return context
}
async function settled(page) {
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.evaluate(() => document.fonts.ready)
  while (await page.locator('.toast-close').count())
    await page.locator('.toast-close').first().click()
  await page.evaluate(() => {
    document.activeElement?.blur()
    window.scrollTo(0, 0)
  })
  await page.waitForTimeout(220)
}
async function layout(page, name) {
  await settled(page)
  const metrics = await page.evaluate(() => {
    const topbar = document.querySelector('.app-topbar')?.getBoundingClientRect()
    const workspace = document.querySelector('.workspace')?.getBoundingClientRect()
    const header = document.querySelector('.project-header')?.getBoundingClientRect()
    return {
      width: innerWidth,
      gap: workspace.top - topbar.bottom,
      headingGap: header.top - topbar.bottom,
      marginTop: getComputedStyle(document.querySelector('.workspace')).marginTop,
      overflow: document.documentElement.scrollWidth > innerWidth + 1,
    }
  })
  if (!baseline) {
    assert.ok(metrics.gap >= -1 && metrics.gap <= 1, JSON.stringify(metrics))
    assert.ok(metrics.headingGap <= 36, JSON.stringify(metrics))
    assert.equal(metrics.overflow, false)
  }
  results.push({ test: name, ...metrics })
  await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true })
}
async function createTask(page, title, section) {
  await page.locator('#idea-title').fill(title)
  if (section) await page.locator('#idea-location').fill(section)
  await page.locator('#idea-description').fill('Task description / Описание / Açıklama / 描述')
  await page.locator('form button[type=submit]').click()
  await page.waitForURL((url) => !url.pathname.includes('/task/new'))
  await page.locator('.task-card').filter({ hasText: title }).first().waitFor()
}
async function login(context, role) {
  const page = await context.newPage()
  await page.goto(origin + '/admin/login')
  await page.locator('#admin-email').fill(role + '@example.test')
  await page.locator('#admin-password').fill('Regression-test-password!')
  await page.locator('form button[type=submit]').click()
  await page.waitForURL('**/admin/security')
  const csrf = await call(context, '/api/csrf')
  const proof = await context.request.post(origin + '/api/security/challenge', {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf.token },
    data: { recovery_code: 'regression-' + role + '@example.test' },
  })
  assert.ok(proof.ok(), await proof.text())
  return page
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
  ])
    await cp(path.join(root, 'api', entry), path.join(app, entry), {
      recursive: true,
      filter: (f) => !/(?:^|\/)(?:\.DS_Store|cache\/[^/]+|[^/]*\.sqlite[^/]*)$/.test(f),
    })
  await mkdir(path.join(app, 'tests'))
  await cp(
    path.join(root, 'api/tests/client-project-regressions-fixture.php'),
    path.join(app, 'tests/client-project-regressions-fixture.php'),
  )
  for (const dir of [
    'bootstrap/cache',
    'storage/framework/views',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/logs',
  ])
    await mkdir(path.join(app, dir), { recursive: true })
  await writeFile(path.join(app, '.env'), '')
  await writeFile(env.DB_DATABASE, '')
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/client-project-regressions-fixture.php']))
  backend = spawn(
    'php',
    ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${apiPort}`, '-t', 'public', 'public/index.php'],
    { cwd: app, env, stdio: 'ignore' },
  )
  frontend = await createServer({
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
  await frontend.listen()
  browser = await chromium.launch({ headless: true })
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`http://127.0.0.1:${apiPort}/api/health`)).ok) break
    } catch {}
    await new Promise((r) => setTimeout(r, 50))
  }
  for (const locale of baseline ? ['ru'] : ['ru', 'en', 'tr', 'zh-CN']) {
    const ctx = await context(locale),
      page = await ctx.newPage(),
      project = fixture.projects[locale]
    await page.goto(origin + '/access/' + project.access)
    await page.waitForURL(origin + '/project/' + project.id)
    await page.locator('.project-empty-state').waitFor()
    await layout(page, locale + '-empty-desktop')
    await page.setViewportSize({ width: 390, height: 844 })
    await layout(page, locale + '-empty-mobile')
    if (baseline) {
      results.push({
        test: 'missing-empty-project-action',
        actions: await page.locator('a[href$="/task/new"]').count(),
      })
      await ctx.close()
      continue
    }
    await page.locator('.project-empty-state a').click()
    await page.waitForURL(origin + '/project/' + project.id + '/task/new')
    assert.equal(await page.locator('#idea-location').getAttribute('role'), 'combobox')
    const firstSection = 'First section / Первый раздел / İlk bölüm / 第一个分区'
    await createTask(page, 'First task ' + locale, firstSection)
    let data = (await call(ctx, '/api/client/project/' + project.id)).data
    assert.equal(data.sections.length, 1)
    assert.equal(data.sections[0].name, firstSection)
    let tasks = (await call(ctx, '/api/client/tasks')).data
    assert.equal(tasks.length, 1)
    assert.equal(tasks[0].displayNumber, '1.1')
    await page.reload()
    await page
      .getByText('First task ' + locale, { exact: true })
      .first()
      .waitFor()
    await layout(page, locale + '-populated-mobile')
    await page.setViewportSize({ width: 1440, height: 980 })
    await layout(page, locale + '-populated-desktop')
    await page.locator('.project-header a[href$="/task/new"]').click()
    await page.locator('#idea-location').fill('First section')
    await page.locator('[role=option]').filter({ hasText: firstSection }).click()
    await createTask(page, 'Second task ' + locale)
    tasks = (await call(ctx, '/api/client/tasks')).data
    assert.deepEqual(
      tasks.map((task) => task.displayNumber),
      ['1.1', '1.2'],
    )
    assert.equal((await call(ctx, '/api/client/project/' + project.id)).data.sections.length, 1)
    results.push({
      test: 'empty-client-create-section-and-task-then-reuse-section',
      locale,
      passed: true,
    })
    await ctx.close()
  }
  if (!baseline) {
    const client = await context(),
      p = await client.newPage()
    await p.goto(origin + '/access/' + fixture.existing.access)
    await p.waitForURL(origin + '/project/' + fixture.existing.id)
    await p.getByText('Existing task', { exact: true }).first().waitFor()
    await layout(p, 'existing-client-desktop')
    await p.locator('.project-header a[href$="/task/new"]').click()
    await createTask(p, 'Added to existing project', 'Existing client new section')
    const tasks = (await call(client, '/api/client/tasks')).data
    assert.ok(
      tasks.some(
        (task) =>
          task.id === fixture.existing.taskId && task.description === 'Keep this task unchanged',
      ),
    )
    await client.close()
    for (const role of ['owner', 'developer']) {
      const ctx = await context(),
        page = await login(ctx, role),
        project = fixture.projects[role]
      await page.goto(origin + '/admin/projects/' + project.id + '/brief')
      await page.locator('.project-empty-state a').click()
      await page.waitForURL('**/brief/task/new')
      if (role === 'developer') {
        assert.equal(await page.locator('#idea-location').evaluate((el) => el.tagName), 'SELECT')
        await page.locator('#idea-location').selectOption({ value: 'Общее' })
      }
      await createTask(page, 'New task by ' + role, role === 'owner' ? 'Owner section' : null)
      await page.reload()
      await page
        .locator('.task-card')
        .filter({ hasText: 'New task by ' + role })
        .first()
        .waitFor()
      assert.equal((await call(ctx, '/api/admin/projects/' + project.id + '/tasks')).data.length, 1)
      await page.locator('.project-header a[href$="/task/new"]').waitFor()
      results.push({ test: 'existing-task-form-and-empty-project', role, passed: true })
      await ctx.close()
    }
    assert.deepEqual(errors, [])
  }
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify({ baseline, results, javascriptErrors: errors, localOnly: true }, null, 2) +
      '\n',
  )
  console.log(
    JSON.stringify({ baseline, results: results.length, javascriptErrors: errors.length }),
  )
} finally {
  await browser?.close()
  await frontend?.close()
  backend?.kill('SIGTERM')
  await rm(scratch, { recursive: true, force: true })
}
