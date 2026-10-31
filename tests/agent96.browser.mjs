import assert from 'node:assert/strict'
import { runWebhookQa } from './agent96.webhook.mjs'
import { spawn, spawnSync } from 'node:child_process'
import { cp, mkdir, mkdtemp, rm, symlink, writeFile, readdir } from 'node:fs/promises'
import { randomBytes } from 'node:crypto'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const mysql = true
const packageRoot = process.env.M96_PACKAGE_ROOT
const exampleScript = packageRoot
  ? path.join(packageRoot, 'agent-api/examples/agent.mjs')
  : path.join(root, 'docs/milestone-9-6/examples/agent.mjs')
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-editor-'))
const app = path.join(scratch, 'project-brief-app'),
  output = path.join('/tmp', packageRoot ? 'project-brief-m96-package-qa' : 'project-brief-m96-qa')
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
  origin = `http://127.0.0.1:${packageRoot ? apiPort : webPort}`
const env = {
  PATH: process.env.PATH,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: mysql ? 'mysql' : 'sqlite',
  DB_DATABASE: mysql
    ? packageRoot
      ? 'projectbrief_m96_package_qa'
      : 'projectbrief_m96_browser_qa'
    : path.join(scratch, 'test.sqlite'),
  ...(mysql
    ? {
        DB_HOST: '127.0.0.1',
        DB_PORT: process.env.M96_QA_MYSQL_PORT || '32770',
        DB_USERNAME: 'briefqa',
        DB_PASSWORD: 'Disposable-m96-only',
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
  assert.ok(!result.stdout.trimStart().startsWith('In '), result.stdout)
  return result.stdout
}
let backend, frontend, browser
const errors = [],
  results = []
try {
  if (packageRoot) {
    await cp(path.join(packageRoot, 'private/project-brief-app'), app, { recursive: true })
    await cp(path.join(packageRoot, 'public'), path.join(scratch, 'domains/brief.web86.site'), {
      recursive: true,
    })
    await symlink(path.join(root, 'api/vendor/fakerphp'), path.join(app, 'vendor/fakerphp'))
    await cp(path.join(root, 'api/database/factories'), path.join(app, 'database/factories'), {
      recursive: true,
    })
    // QA-only factory autoload; the extracted production runtime remains otherwise unchanged.
    const loader = `<?php\nspl_autoload_register(function ($class) { foreach (['Database\\\\Factories\\\\' => __DIR__.'/../database/factories/', 'Faker\\\\' => __DIR__.'/../vendor/fakerphp/faker/src/Faker/'] as $prefix => $dir) { if (str_starts_with($class, $prefix)) { $path = $dir.str_replace('\\\\', '/', substr($class, strlen($prefix))).'.php'; if (is_file($path)) { require_once $path; } } } });\nrequire __DIR__.'/../vendor/autoload.php';\n`
    await mkdir(path.join(app, 'tests'), { recursive: true })
    await writeFile(path.join(app, 'tests/qa-autoload.php'), loader)
  }
  if (!packageRoot)
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
  if (!packageRoot) await symlink(path.join(root, 'api/vendor'), path.join(app, 'vendor'))
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
    let fixtureSource = (await readFile(file, 'utf8')).replace(
      "config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')",
      `config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== '${env.DB_DATABASE}'`,
    )
    if (packageRoot)
      fixtureSource = fixtureSource.replace(
        "require __DIR__.'/../vendor/autoload.php';",
        "require __DIR__.'/qa-autoload.php';",
      )
    await writeFile(file, fixtureSource)
  }
  const previousMigrations = path.join(scratch, 'previous-migrations')
  await mkdir(previousMigrations)
  for (const file of await readdir(path.join(app, 'database/migrations'))) {
    if (file < '2026_10_30_213603_add_external_agents.php')
      await cp(path.join(app, 'database/migrations', file), path.join(previousMigrations, file))
  }
  php([
    'artisan',
    'migrate:fresh',
    '--path=' + previousMigrations,
    '--realpath',
    '--force',
    '--no-interaction',
  ])
  const fixture = JSON.parse(php(['tests/live-sync-fixture.php']))
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const publicDir = packageRoot
    ? path.join(scratch, 'domains/brief.web86.site')
    : path.join(app, 'public')
  const routerFile = path.join(scratch, 'router.php')
  await writeFile(
    routerFile,
    `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/index.html' && !preg_match('~^/(api|access|sanctum)(/|$)~', $path) && is_file($_SERVER['DOCUMENT_ROOT'].$path)) { return false; }
require $_SERVER['DOCUMENT_ROOT'].'/index.php';
`,
  )
  backend = spawn('php', ['-S', `127.0.0.1:${apiPort}`, '-t', publicDir, routerFile], {
    cwd: app,
    env,
    stdio: 'ignore',
    detached: mysql,
  })
  if (!packageRoot)
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
  if (frontend) await frontend.listen()
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
  await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await page.locator('#security-code').fill('Live-browser-code-0')
  await page.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await page.locator('.onboarding-continue').waitFor()
  const projectRoot = '/api/admin/projects/' + fixture.project
  const longProject = 'Long-project-' + 'НазваниеÇ中文'.repeat(12)
  await call('/api/admin/projects/' + fixture.reviewed, 'PATCH', { title: longProject })
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-agents').click()
  assert.deepEqual(
    await page
      .locator('.integration-primary-tabs [role=tab]')
      .evaluateAll((nodes) => nodes.map((n) => n.id)),
    ['tokens', 'webhooks', 'sso', 'agents', 'operations'].map((n) => 'integration-tab-' + n),
  )
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 980 })
    for (const tab of ['tokens', 'webhooks', 'sso', 'operations', 'agents']) {
      await page.locator('#integration-tab-' + tab).click()
      assert.equal(
        await page.locator('#integration-tab-' + tab).getAttribute('aria-selected'),
        'true',
      )
    }
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1))
  }
  await page.getByRole('button', { name: 'Create agent', exact: true }).click()
  const dialog = page.locator('dialog[open]')
  assert.equal(await dialog.locator('#agent-project-options input:checked').count(), 0)
  for (const [kind, hint] of [
    ['ai', 'Uses AI'],
    ['automation', 'defined scenario'],
    ['service', 'another system'],
  ]) {
    await dialog.locator('#agent-kind').selectOption(kind)
    assert.ok((await dialog.locator('#agent-kind-hint').textContent()).includes(hint))
    assert.equal(await dialog.locator('#agent-project-options input:checked').count(), 0)
  }
  await dialog.locator('#agent-kind').selectOption('ai')
  await dialog.getByLabel('Search projects').fill('NO-SUCH-PROJECT')
  assert.ok((await dialog.textContent()).includes('No projects found.'))
  await dialog.getByLabel('Search projects').fill('Northline')
  await dialog.getByLabel('Name', { exact: true }).fill('Demo independent executor')
  await dialog
    .getByLabel('Description', { exact: true })
    .fill('Node.js executor — русский / Türkçe / 中文')
  await dialog
    .getByRole('group', { name: 'Allowed projects', exact: true })
    .getByRole('checkbox', { name: 'Northline — Live collaboration', exact: true })
    .check()
  await dialog.getByLabel('Search projects').fill('')
  await dialog.getByRole('checkbox', { name: longProject, exact: true }).check()
  await page.setViewportSize({ width: 390, height: 844 })
  assert.ok(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth + 1))
  assert.ok(
    await dialog
      .locator('.agent-project-options')
      .evaluate((el) => el.getBoundingClientRect().height <= 240),
  )
  await dialog.getByRole('button', { name: 'Remove project ' + longProject, exact: true }).click()
  await page.setViewportSize({ width: 1440, height: 980 })
  await dialog.getByRole('button', { name: 'Clear selection', exact: true }).click()
  assert.equal(await dialog.locator('#agent-project-options input:checked').count(), 0)
  await dialog
    .getByRole('checkbox', { name: 'Northline — Live collaboration', exact: true })
    .check()
  await page.route('**/api/admin/integrations/agents', async (route) => {
    if (route.request().method() === 'POST')
      return route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'QA save error', errors: { name: ['QA save error'] } }),
      })
    return route.continue()
  })
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  await dialog.getByRole('alert').waitFor()
  assert.equal(
    await dialog.getByLabel('Name', { exact: true }).inputValue(),
    'Demo independent executor',
  )
  assert.equal(await dialog.locator('#agent-project-options input:checked').count(), 1)
  page.once('dialog', (confirmation) => confirmation.dismiss())
  await page.keyboard.press('Escape')
  assert.equal(await page.locator('dialog[open]').count(), 1)
  await page.unroute('**/api/admin/integrations/agents')
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  const card = page.locator('[data-agent-id]').first()
  await card.waitFor()
  const agentId = await card.getAttribute('data-agent-id')
  assert.ok((await card.textContent()).includes('1 allowed projects'))
  assert.ok((await card.textContent()).includes('No API requests yet'))
  await card.getByRole('button', { name: 'How to connect?', exact: true }).click()
  assert.equal(await dialog.locator('ol li').count(), 5)
  assert.ok((await dialog.textContent()).includes('agent-api/examples/agent.mjs'))
  await page.keyboard.press('Escape')
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  assert.equal(await dialog.locator('#agent-project-options').isVisible(), false)
  await dialog.getByLabel('Description', { exact: true }).fill('Unsaved draft')
  page.once('dialog', (confirmation) => confirmation.dismiss())
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
  page.once('dialog', (confirmation) => confirmation.dismiss())
  await page.mouse.click(1, 1)
  assert.equal(await page.locator('dialog[open]').count(), 1)
  assert.equal(
    await dialog.getByLabel('Description', { exact: true }).inputValue(),
    'Unsaved draft',
  )
  page.once('dialog', (confirmation) => confirmation.accept())
  await dialog.locator('.modal-header button').click()
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  await page.locator('dialog[open] summary').click()
  await page.getByRole('button', { name: 'Issue / rotate token', exact: true }).click()
  await page.waitForFunction(() =>
    document.querySelector('.agent-secret')?.value?.startsWith('pb_'),
  )
  const token = await page.locator('.agent-secret').inputValue()
  assert.match(token, /^pb_[a-f0-9]{64}$/)
  await page
    .locator('dialog[open]')
    .getByRole('button', { name: 'Close dialog', exact: true })
    .last()
    .click()
  assert.equal(await page.locator('.agent-secret').count(), 1) // Empty, closed dialog retained by BaseDialog.
  assert.equal(await page.locator('.agent-secret').inputValue(), '')
  const identityBefore = await (
    await fetch(origin + '/api/v1/agent/me', {
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    })
  ).json()
  for (const kind of ['automation', 'service', 'ai']) {
    await card.getByRole('button', { name: 'Configure', exact: true }).click()
    await dialog.locator('#agent-kind').selectOption(kind)
    await dialog.getByRole('button', { name: 'Save', exact: true }).click()
    await page.waitForFunction(
      () => !document.querySelector('#agent-editor-title')?.closest('dialog').open,
    )
    const identity = await (
      await fetch(origin + '/api/v1/agent/me', {
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
      })
    ).json()
    assert.deepEqual(identity.data.scopes, identityBefore.data.scopes)
    assert.deepEqual(identity.data.projectIds, identityBefore.data.projectIds)
    assert.equal((await call('/api/admin/integrations/agents')).data[0].projectCount, 0)
  }
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  await dialog.getByRole('button', { name: 'Clear selection', exact: true }).click()
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  await page.waitForFunction(
    () => !document.querySelector('#agent-editor-title')?.closest('dialog').open,
  )
  const deniedTeam = await context.request.post(origin + projectRoot + '/team/agents/' + agentId, {
    headers: {
      Accept: 'application/json',
      'X-CSRF-TOKEN': (await (await context.request.get(origin + '/api/csrf')).json()).token,
    },
  })
  assert.ok([403, 404, 422].includes(deniedTeam.status()))
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  await dialog.locator('.agent-picker-toggle').click()
  await dialog
    .getByRole('checkbox', { name: 'Northline — Live collaboration', exact: true })
    .check()
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  await page.waitForFunction(
    () => !document.querySelector('#agent-editor-title')?.closest('dialog').open,
  )
  assert.equal((await call('/api/admin/integrations/agents')).data[0].projectCount, 0)
  await page.goto(origin + '/admin/projects/' + fixture.project + '/team')
  await page
    .locator('[data-team-agents]')
    .getByRole('button', { name: 'Add executor', exact: true })
    .click()
  await page.locator('#team-agent-choice').selectOption(agentId)
  await page
    .locator('[data-team-agents]')
    .getByRole('button', { name: 'Add executor', exact: true })
    .click()
  await page.locator('[data-team-agents] .project-team-member').waitFor()
  await page
    .locator('[data-team-agents]')
    .getByRole('button', { name: 'Remove from project', exact: true })
    .click()
  await page.locator('[data-team-agents] .project-team-member').waitFor({ state: 'detached' })
  const removed = await (
    await fetch(origin + '/api/v1/agent/me', {
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    })
  ).json()
  assert.deepEqual(removed.data.projectIds, [])
  await page
    .locator('[data-team-agents]')
    .getByRole('button', { name: 'Add executor', exact: true })
    .click()
  await page.locator('#team-agent-choice').selectOption(agentId)
  await page
    .locator('[data-team-agents]')
    .getByRole('button', { name: 'Add executor', exact: true })
    .click()
  await page.locator('[data-team-agents] .project-team-member').waitFor()
  const taskUrl = '/admin/projects/' + fixture.project + '/brief/task/' + fixture.task
  await page.goto(origin + taskUrl)
  await page.locator('[data-task-agents] select').selectOption(agentId)
  assert.ok(
    await page
      .locator('[data-task-agents] .agent-assign-form')
      .evaluate(
        (el) =>
          Math.abs(
            el.querySelector('select').getBoundingClientRect().bottom -
              el.querySelector('button').getBoundingClientRect().bottom,
          ) <= 2,
      ),
  )
  await page
    .locator('[data-task-agents]')
    .getByRole('button', { name: 'Assign', exact: true })
    .click()
  await page.locator('.agent-assignment').waitFor()
  const before = (await call('/api/admin/tasks/' + fixture.task)).data
  const executor = spawn('node', [exampleScript], {
    cwd: scratch,
    env: { PATH: process.env.PATH, PROJECT_BRIEF_URL: origin, PROJECT_BRIEF_AGENT_TOKEN: token },
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  let executorOutput = '',
    executorError = ''
  executor.stdout.on('data', (data) => (executorOutput += data))
  executor.stderr.on('data', (data) => (executorError += data))
  const code = await new Promise((resolve) => executor.once('exit', resolve))
  assert.equal(code, 0, executorError)
  assert.equal(JSON.parse(executorOutput).results.length, 1)
  const after = (await call('/api/admin/tasks/' + fixture.task)).data
  assert.equal(after.status, before.status)
  assert.equal(after.clientApproved, before.clientApproved)
  assert.equal(after.agentAssignments[0].state, 'submitted')
  assert.ok(
    after.comments.some(
      (c) => c.author === 'agent' && c.authorName === 'Demo independent executor',
    ),
  )
  await page.reload()
  await page.locator('.comment.agent').first().waitFor()
  assert.ok(
    (await page.locator('.comment.agent').first().textContent()).includes(
      'Demo independent executor',
    ),
  )
  results.push(
    'Real owner UI registration, one-time credential, project team and task assignment, independent Node executor, agent authorship and unchanged human workflow',
  )
  const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json' }
  async function agentCall(path, options = {}) {
    return fetch(origin + '/api/v1/agent/' + path, {
      ...options,
      headers: { ...headers, ...options.headers },
    })
  }
  const detail = await (await agentCall('tasks/' + fixture.task)).json()
  const assignmentId = detail.data.assignmentId
  assert.ok(!JSON.stringify(detail).includes('Original private technical notes'))
  assert.equal((await fetch(origin + '/api/v1/projects', { headers })).status, 403)
  const report = { assignmentId, state: 'running', summary: 'Concurrent identical report' }
  const concurrent = await Promise.all(
    Array.from({ length: 6 }, () =>
      agentCall('tasks/' + fixture.task + '/reports', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'concurrent-report' },
        body: JSON.stringify(report),
      }),
    ),
  )
  for (const response of concurrent) assert.equal(response.status, 201)
  const reportIds = await Promise.all(
    concurrent.map(async (response) => (await response.json()).data.id),
  )
  assert.equal(new Set(reportIds).size, 1)
  const form = new FormData()
  form.set('assignmentId', assignmentId)
  form.append('attachments[]', new Blob(['Independent executor result.']), 'result.txt')
  const uploaded = await agentCall('tasks/' + fixture.task + '/attachments', {
    method: 'POST',
    headers: { 'Idempotency-Key': 'result-file' },
    body: form,
  })
  assert.equal(uploaded.status, 201, await uploaded.clone().text())
  const attachmentId = (await uploaded.json()).data.attachments[0].id
  const downloaded = await context.request.get(
    origin + '/api/admin/attachments/' + attachmentId + '/download',
  )
  assert.equal(downloaded.status(), 200)
  assert.equal(await downloaded.text(), 'Independent executor result.')
  results.push(
    'Real HTTP bearer/scopes/privacy, six concurrent idempotent reports, multipart upload and human-only authorized file download',
  )
  const secondTask = await call(projectRoot + '/tasks', 'POST', {
    title: 'Unassigned task',
    location: 'Website',
    description: 'Do not disclose',
    priority: 'normal',
  })
  assert.equal((await agentCall('tasks/' + secondTask.data.id)).status, 404)
  await call('/api/admin/tasks/' + fixture.task + '/agents', 'DELETE', { agentId })
  assert.equal((await agentCall('tasks/' + fixture.task)).status, 404)
  await call('/api/admin/tasks/' + fixture.task + '/agents', 'POST', { agentId })
  assert.equal(
    (
      await agentCall('tasks/' + fixture.task + '/reports', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'concurrent-report' },
        body: JSON.stringify(report),
      })
    ).status,
    409,
  )
  const foreignTask = await call('/api/admin/projects/' + fixture.reviewed + '/tasks', 'POST', {
    title: 'Other project task',
    location: 'Website',
    description: 'Outside the agent grant',
    priority: 'normal',
  })
  assert.equal((await agentCall('tasks/' + foreignTask.data.id)).status, 404)
  results.push(
    'Unassigned and foreign-project tasks denied; immediate assignment revocation; stale-generation replay rejected after reassignment',
  )
  for (const state of ['pending', 'running', 'submitted', 'failed', 'cancelled']) {
    const current = (await call('/api/admin/tasks/' + fixture.task)).data.agentAssignments[0]
    if (state !== 'pending') {
      const response = await agentCall('tasks/' + fixture.task + '/reports', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'ux-' + state },
        body: JSON.stringify({
          assignmentId: current.id,
          state,
          summary: 'Latest UX report ' + state,
          pullRequestUrl: 'https://example.test/pull/1',
          resultUrl: 'https://example.test/result',
        }),
      })
      assert.equal(response.status, 201, await response.clone().text())
    }
    await page.goto(origin + taskUrl)
    await page.locator('.agent-execution').waitFor()
    assert.ok(
      (await page.locator('.agent-execution').textContent()).includes(
        {
          pending: 'Waiting to start',
          running: 'Running',
          submitted: 'Result submitted',
          failed: 'Failed',
          cancelled: 'Cancelled',
        }[state],
      ),
    )
    if (state !== 'pending') {
      assert.equal(await page.locator('.agent-latest-report').count(), 1)
      assert.equal(await page.locator('.agent-latest-report a').count(), 3)
      assert.ok(
        (await page.locator('.agent-report-preview').textContent()).includes(
          'Latest UX report ' + state,
        ),
      )
      assert.ok(
        !(await page.locator('.agent-latest-report').textContent()).includes(
          'Concurrent identical report',
        ),
      )
    }
  }
  const unchanged = (await call('/api/admin/tasks/' + fixture.task)).data
  assert.equal(unchanged.status, before.status)
  assert.equal(unchanged.clientApproved, before.clientApproved)
  results.push(
    'UX tabs, all executor types, searchable grants, empty search, clear selection, API error draft retention, dirty cancellation, connection guide, all five execution states and only latest linked report',
  )
  const agent = (await call('/api/admin/integrations/agents')).data[0]
  const visuals = [
    ['/admin/settings/integrations', 'agents', '[data-agent-settings]'],
    ['/admin/projects/' + fixture.project + '/team', 'team', '[data-team-agents]'],
    [taskUrl, 'task', '[data-task-agents]'],
  ]
  let captures = 0
  if (!packageRoot && process.env.M96_SKIP_VISUALS !== '1')
    for (const [url, name, ready] of visuals) {
      await page.goto(origin + url)
      if (name === 'agents') await page.locator('#integration-tab-agents').click()
      await page.locator(ready).first().waitFor()
      if (name === 'agents') await page.locator('[data-agent-id]').first().waitFor()
      await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
      for (const lang of ['ru', 'en', 'tr', 'zh-CN'])
        for (const width of [1440, 390])
          for (const mode of ['light', 'dark', 'system']) {
            await page.setViewportSize({ width, height: 980 })
            await page.emulateMedia({ colorScheme: mode === 'light' ? 'light' : 'dark' })
            await page.evaluate(
              async ({ lang, mode }) => {
                ;(await import('/src/i18n/index.js')).setManualLocale(lang)
                ;(await import('/src/services/theme.js')).theme.select(mode)
              },
              { lang, mode },
            )
            await page.evaluate(async () => document.fonts.ready)
            await page.waitForTimeout(250)
            assert.equal(await page.locator('html').getAttribute('lang'), lang)
            assert.equal(
              await page.locator('html').getAttribute('data-theme'),
              mode === 'light' ? 'light' : 'dark',
            )
            assert.ok(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
              `${name} ${lang} ${width} overflow`,
            )
            await page.screenshot({
              path: path.join(output, `${name}-${lang}-${width}-${mode}.png`),
              fullPage: true,
            })
            captures++
            if (name === 'agents') {
              await page.locator('[data-agent-id]').first().getByRole('button').first().click()
              await page.locator('dialog[open]').waitFor()
              assert.ok(
                await page.locator('dialog[open]').evaluate((el) => {
                  const rect = el.getBoundingClientRect(),
                    footer = el.querySelector('.modal-footer').getBoundingClientRect(),
                    header = el.querySelector('.modal-header').getBoundingClientRect()
                  return (
                    rect.height <= innerHeight * 0.9 + 2 &&
                    footer.bottom <= innerHeight &&
                    header.top >= 0 &&
                    footer.bottom <= rect.bottom + 1
                  )
                }),
                `fixed dialog actions ${lang} ${width}`,
              )
              await page.screenshot({
                path: path.join(output, `dialog-${lang}-${width}-${mode}.png`),
                fullPage: true,
              })
              captures++
              assert.ok(
                await page
                  .locator('dialog[open]')
                  .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
              )
              await page.keyboard.press('Escape')
            }
          }
    }
  const rotated = await call('/api/admin/integrations/agents/' + agentId + '/tokens', 'POST', {
    scopes: agent.tokens.find((credential) => !credential.revokedAt).scopes,
    replace: true,
  })
  assert.equal((await agentCall('me')).status, 401)
  headers.Authorization = 'Bearer ' + rotated.secret
  assert.equal((await agentCall('me')).status, 200)
  results.push('Real HTTP credential rotation: former bearer rejected, replacement accepted')
  results.push(await runWebhookQa({ app, env, agentId, taskId: fixture.task }))
  await call('/api/admin/integrations/agents/' + agentId, 'PATCH', {
    name: agent.name,
    description: agent.description,
    kind: agent.kind,
    active: false,
    projectIds: agent.projectIds,
    webhookUrl: null,
  })
  assert.equal((await agentCall('me')).status, 401)
  const rejected = spawn('node', [exampleScript], {
    env: {
      PATH: process.env.PATH,
      PROJECT_BRIEF_URL: origin,
      PROJECT_BRIEF_AGENT_TOKEN: rotated.secret,
    },
    stdio: 'ignore',
  })
  assert.equal(await new Promise((resolve) => rejected.once('exit', resolve)), 1)
  results.push('Owner deactivation revokes credential; independent executor fails with HTTP 401')
  // Keep the real 30/minute integration settings limit enabled across QA phases.
  await page.waitForTimeout(55_000)
  await page.setViewportSize({ width: 1440, height: 980 })
  await page.evaluate(() => localStorage.setItem('projectbrief.locale', 'en'))
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-agents').click()
  for (const kind of ['ai', 'automation', 'service']) {
    await page.getByRole('button', { name: 'Create agent', exact: true }).click()
    await dialog.getByLabel('Name', { exact: true }).fill('Kind QA ' + kind)
    await dialog.locator('#agent-kind').selectOption(kind)
    assert.equal(await dialog.locator('#agent-project-options input:checked').count(), 0)
    await dialog.getByRole('button', { name: 'Save', exact: true }).click()
    const created = page
      .locator('[data-agent-id]')
      .filter({ has: page.getByText('Kind QA ' + kind, { exact: true }) })
    await created.waitFor()
    assert.ok((await created.textContent()).includes('0 allowed projects'))
    await created.getByRole('button', { name: 'Configure', exact: true }).click()
    page.once('dialog', (confirmation) => confirmation.dismiss())
    await dialog
      .locator('.modal-footer')
      .getByRole('button', { name: 'Delete', exact: true })
      .click()
    assert.equal(await page.locator('dialog[open]').count(), 1)
    page.once('dialog', (confirmation) => confirmation.accept())
    await dialog
      .locator('.modal-footer')
      .getByRole('button', { name: 'Delete', exact: true })
      .click()
    await created.waitFor({ state: 'detached' })
  }
  results.push(
    'All five tabs at 390/1440; three kinds created/edited with unchanged scopes and no automatic membership; owner grant revoke/regrant; team remove/re-add; inline task picker; confirmed editor deletion',
  )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify(
      {
        runtime: packageRoot ? 'extracted-production-zip' : 'source',
        database: 'MariaDB 11.8.9',
        results,
        captures,
        errors,
      },
      null,
      2,
    ),
  )
  console.log(JSON.stringify({ ok: true, results, captures, output }))
} catch (error) {
  console.error(JSON.stringify({ pageErrors: errors, failure: error.message }))
  if (browser) {
    const p = browser.contexts()[0]?.pages()[0]
    if (p) await writeFile(path.join(output, 'failure.txt'), await p.locator('body').innerText())
  }
  throw error
} finally {
  await browser?.close()
  await frontend?.close()
  if (backend?.pid) {
    try {
      process.kill(-backend.pid, 'SIGTERM')
    } catch {
      backend.kill('SIGTERM')
    }
  }
  await rm(scratch, { recursive: true, force: true })
}
