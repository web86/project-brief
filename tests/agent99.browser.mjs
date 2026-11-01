import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { cp, mkdir, mkdtemp, rm, symlink, writeFile, readdir } from 'node:fs/promises'
import { randomBytes } from 'node:crypto'
import { parseEnv } from 'node:util'
import { readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const qaStarted = Date.now()
const liveEnvPath = process.env.M99_GROQ_TEST_ENV
let providerEnvironment = {},
  providerRequests = 0
const providerMetrics = []
if (liveEnvPath) {
  assert.ok(
    !path.resolve(liveEnvPath).startsWith(root + path.sep),
    'Test env must remain outside the repository',
  )
  const content = await readFile(liveEnvPath, 'utf8')
  assert.ok(content.length < 65536)
  const parsed = parseEnv(content)
  assert.ok(
    parsed.GROQ_API_KEY && parsed.GROQ_MODEL,
    'Dedicated test provider settings are required',
  )
  providerEnvironment = { GROQ_API_KEY: parsed.GROQ_API_KEY, GROQ_MODEL: parsed.GROQ_MODEL }
}

const mysql = true
const packageRoot = process.env.M99_PACKAGE_ROOT
const exampleScript = packageRoot
  ? path.join(packageRoot, 'agent-api/examples/collaboration.mjs')
  : path.join(root, 'docs/milestone-9-9/examples/collaboration.mjs')
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-editor-'))
const app = path.join(scratch, 'project-brief-app'),
  output = path.join(
    '/tmp',
    liveEnvPath
      ? 'project-brief-m99-groq-qa'
      : packageRoot
        ? 'project-brief-m99-package-qa'
        : 'project-brief-m99-qa',
  )
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
  DB_DATABASE: liveEnvPath
    ? 'projectbrief_m99_groq_qa'
    : mysql
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
    if (file < '2026_11_01_074535_add_agent_collaboration_and_task_formatting.php')
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
    if (!localStorage.getItem('projectbrief.locale'))
      localStorage.setItem('projectbrief.locale', 'en')
    if (!localStorage.getItem('projectbrief.theme'))
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
  const scopes = [
    'agent:profile',
    'agent:tasks:read',
    'agent:tasks:report',
    'agent:runs:read',
    'agent:runs:write',
    'agent:messages:read',
    'agent:messages:create',
  ]
  const taskUrl = `/admin/projects/${fixture.project}/brief/task/${fixture.task}`
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-agents').click()
  await page.getByRole('button', { name: 'Create agent', exact: true }).click()
  const dialog = page.locator('dialog[open]')
  await dialog.locator('#agent-name').fill('Collaboration Analyst')
  await dialog
    .locator('#agent-instructions')
    .fill('Act as a technical analyst. Do not invent client decisions.')
  await dialog.locator(`#agent-project-options input[value="${fixture.project}"]`).check()
  await dialog.locator('button[form="agent-editor"][type="submit"]').click()
  await page.locator('#agent-created-title').waitFor()
  const agentId = (await call('/api/admin/integrations/agents')).data[0].id
  const project = (await call('/api/admin/projects/' + fixture.project)).data
  assert.ok(
    project.agents.some((agent) => agent.id === agentId),
    'new grant adds membership without visiting Team',
  )
  await page
    .locator('dialog[open]')
    .getByRole('button', { name: 'Create API key', exact: true })
    .click()
  await page.locator('#create-tokens select').first().waitFor()
  await page.locator('#token-name').fill('Collaboration QA key')
  for (const scope of scopes)
    await page.locator(`#create-tokens input[type="checkbox"][value="${scope}"]`).check()
  await page.locator('#create-tokens button[type="submit"]').click()
  await page.locator('.integration-secret-value').waitFor()
  const token = await page.locator('.integration-secret-value').innerText()
  assert.ok(token.startsWith('pb_'))
  await page.locator('dialog[open]').getByRole('button', { name: 'Close', exact: true }).click()
  const key = (await call('/api/admin/integrations')).tokens[0]
  assert.equal(key.type, 'agent')
  assert.equal(key.agentId, agentId)
  results.push(
    'registration automatically adds membership; unified bound credential issued with fresh MFA',
  )
  await call(`/api/admin/projects/${fixture.project}/agent-instructions`, 'PATCH', {
    instructions: 'Use Vue/PHP. Report in English; preserve access boundaries.',
  })
  await page.goto(origin + taskUrl)
  await page.locator('[data-task-agents] select').selectOption(agentId)
  await page
    .locator('[data-task-agents] textarea')
    .fill(
      liveEnvPath
        ? 'Review a synthetic contact-form task. Identify two ambiguities and three acceptance criteria. Do not write code.'
        : 'FIRST-PRIVATE-INSTRUCTION\n' +
            Array.from(
              { length: 35 },
              (_, i) => `Requirement ${i + 1}: inspect keyboard access.`,
            ).join('\n'),
    )
  await page.locator('.agent-assign-form button').click()
  await page.locator('.agent-assignment').waitFor()
  async function runExecutor() {
    const child = spawn('node', [exampleScript], {
      cwd: root,
      env: {
        PATH: process.env.PATH,
        PROJECT_BRIEF_URL: origin,
        PROJECT_BRIEF_TOKEN: token,
        AGENT_PROVIDER: liveEnvPath ? 'groq' : 'deterministic',
        ...providerEnvironment,
        ...(liveEnvPath && process.env.NODE_EXTRA_CA_CERTS
          ? { NODE_EXTRA_CA_CERTS: process.env.NODE_EXTRA_CA_CERTS }
          : {}),
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    let outputText = '',
      errorText = ''
    child.stdout.on('data', (data) => (outputText += data))
    child.stderr.on('data', (data) => (errorText += data))
    const code = await new Promise((resolve) => child.on('close', resolve))
    const result = JSON.parse(outputText)
    providerRequests += result.providerRequests || 0
    providerMetrics.push(...(result.providerMetrics || []))
    assert.equal(code, 0, errorText)
    assert.equal(result.outcomes.filter((row) => row.state === 'submitted').length, 1)
  }
  await runExecutor()
  await page.reload()
  await page
    .locator('[data-task-agents]')
    .getByRole('button', { name: 'Share with client', exact: true })
    .click()
  await page.locator('.agent-share-dialog details summary').click()
  if (!liveEnvPath)
    assert.ok(
      await page
        .locator('.agent-source-preview')
        .evaluate((node) => node.scrollHeight > node.clientHeight),
    )
  assert.ok(await page.locator('#agent-publication-text').isVisible())
  assert.ok(await page.locator('button[form="agent-result-publication"]').isVisible())
  await page.keyboard.press('Escape')
  await page.locator('.agent-conversation > summary').click()
  await page.locator('.agent-conversation .agent-dialogue-message.agent').waitFor()
  const thread = page.locator('.agent-thread').first()
  await thread
    .locator('textarea')
    .fill('SECOND-PRIVATE-INSTRUCTION: refine acceptance criteria and ask one question.')
  await thread.locator('form button[type="submit"]').click()
  await page.waitForFunction(
    () => document.querySelector('.agent-follow-up textarea')?.value === '',
  )
  await runExecutor()
  await page.locator('.agent-conversation > button').click()
  await page.waitForFunction(
    () => document.querySelectorAll('.agent-dialogue-message.agent').length === 2,
  )
  const updated = (await call('/api/admin/tasks/' + fixture.task)).data
  assert.equal(updated.agentAssignments[0].reports.length, 2)
  assert.equal(updated.status, 'new')
  assert.ok(!updated.description.includes('SECOND-PRIVATE'))
  await page.reload()
  await page
    .locator('[data-task-agents]')
    .getByRole('button', { name: 'Share with client', exact: true })
    .click()
  await page
    .locator('#agent-publication-text')
    .fill('Human-reviewed acceptance criteria for the client.')
  assert.equal(await page.locator('.agent-share-dialog input[type="checkbox"]:checked').count(), 0)
  await page.locator('button[form="agent-result-publication"]').click()
  await page.waitForFunction(
    () => !document.querySelector('#agent-share-title')?.closest('dialog').open,
  )
  const client = await browser.newContext()
  await client.request.get(origin + '/access/' + fixture.token)
  const publicTask = await (
    await client.request.get(origin + '/api/client/tasks/' + fixture.task)
  ).json()
  assert.ok(JSON.stringify(publicTask).includes('Human-reviewed acceptance criteria'))
  assert.ok(!JSON.stringify(publicTask).includes('PRIVATE-INSTRUCTION'))
  const privateRequest = await client.request.get(
    origin + '/api/admin/tasks/' + fixture.task + '/agent-collaboration',
  )
  assert.ok([401, 403].includes(privateRequest.status()))
  await client.close()
  results.push(
    'independent Node executor completes two private runs; first version preserved; explicit human client publication',
  )
  await page.goto(origin + taskUrl + '/edit')
  await page.locator('#idea-description[contenteditable]').waitFor()
  await page.locator('#idea-description').evaluate((node) => {
    node.focus()
    node.innerHTML =
      '<p><strong>Formatted requirement</strong></p><ul><li>Keyboard access</li></ul><p><a href="https://example.com/spec">Specification</a></p>'
    node.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }))
  })
  await page.locator('#idea-description').evaluate((node) => {
    node.focus()
    const range = document.createRange()
    range.selectNodeContents(node)
    range.collapse(false)
    const selection = window.getSelection()
    selection.removeAllRanges()
    selection.addRange(range)
  })
  await page.keyboard.insertText(' UndoProbe')
  await page.getByRole('button', { name: 'Undo', exact: true }).click()
  assert.ok(!(await page.locator('#idea-description').innerText()).includes('UndoProbe'))
  await page.getByRole('button', { name: 'Redo', exact: true }).click()
  assert.ok((await page.locator('#idea-description').innerText()).includes('UndoProbe'))
  await page.getByRole('button', { name: 'Undo', exact: true }).click()
  let aiRequests = 0
  await page.route('**/ai/task-assistant', async (route) => {
    aiRequests++
    await new Promise((resolve) => setTimeout(resolve, 250))
    await route.fulfill({
      json: {
        title: 'Accessible navigation',
        description: 'Formatted requirement\n• Confirm keyboard access',
        expectedResult: 'Reviewed acceptance criteria',
        questions: [],
        suggestedIdeas: [],
      },
    })
  })
  await page.locator('.ai-button').dblclick()
  await page.locator('#ai-description').waitFor()
  assert.equal(aiRequests, 1)
  assert.ok((await page.locator('#idea-description').innerText()).includes('Specification'))
  await page
    .locator('.ai-dialog')
    .getByRole('button', { name: 'Apply to task', exact: true })
    .click()
  assert.ok(await page.locator('#idea-description strong').count())
  await page.locator('.idea-form button[type="submit"]').click()
  await page.waitForURL('**' + taskUrl)
  const formatted = (await call('/api/admin/tasks/' + fixture.task)).data
  assert.ok(formatted.descriptionHtml.includes('<strong>Formatted requirement</strong>'))
  assert.ok(formatted.descriptionHtml.includes('<ul>'))
  results.push(
    'WYSIWYG save, safe HTML, AI single immediate request, explicit apply and retained unchanged formatting',
  )
  const collaboration = (
    await call('/api/admin/tasks/' + fixture.task + '/agent-collaboration')
  ).data.find((row) => !row.revokedAt)
  const runUrl = `/api/admin/tasks/${fixture.task}/agent-assignments/${collaboration.id}/runs`
  const sameRequest = { instruction: 'CONCURRENT-REQUEST', submissionId: crypto.randomUUID() }
  const csrf = (await (await context.request.get(origin + '/api/csrf')).json()).token
  const createResponses = await Promise.all(
    Array.from({ length: 5 }, () =>
      context.request.post(origin + runUrl, {
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        data: sameRequest,
      }),
    ),
  )
  assert.ok(createResponses.every((response) => response.status() === 201))
  const createdIds = await Promise.all(
    createResponses.map(async (response) => (await response.json()).data.id),
  )
  assert.equal(new Set(createdIds).size, 1)
  const concurrentRun = createdIds[0]
  const claims = await Promise.all(
    Array.from({ length: 5 }, (_, i) =>
      fetch(origin + `/api/v1/agent/tasks/${fixture.task}/runs/${concurrentRun}/start`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Idempotency-Key': `parallel-start-${i}`,
        },
        body: JSON.stringify({ assignmentId: collaboration.id, runId: concurrentRun }),
      }),
    ),
  )
  assert.equal(claims.filter((response) => response.status === 200).length, 1)
  assert.equal(claims.filter((response) => response.status === 409).length, 4)
  await call(`/api/admin/tasks/${fixture.task}/agent-runs/${concurrentRun}/cancel`, 'POST', {})
  results.push('MariaDB parallel retry creates one run; only one executor can claim it')
  if (liveEnvPath) assert.equal(providerRequests, 2, 'Exactly two controlled model calls')
  let captures = 0
  if (process.env.M99_SKIP_VISUALS !== '1') {
    const surfaces = [
      ['keys', '/admin/settings/integrations', '.integration-primary-tabs'],
      [
        'project-instructions',
        `/admin/projects/${fixture.project}/settings`,
        '#project-agent-instructions',
      ],
      ['team', `/admin/projects/${fixture.project}/team`, '[data-team-agents]'],
      ['task', taskUrl, '[data-task-agents]'],
      ['editor', taskUrl + '/edit', '#idea-description[contenteditable]'],
    ]
    for (const lang of ['ru', 'en', 'tr', 'zh-CN'])
      for (const mode of ['light', 'dark', 'system'])
        for (const width of [1440, 390]) {
          // Respect the real integration-management throttle while visiting every localized surface.
          if (mode === 'light' && width === 1440) await page.waitForTimeout(35000)
          await page.setViewportSize({ width, height: 980 })
          await page.emulateMedia({ colorScheme: mode === 'light' ? 'light' : 'dark' })
          await page.evaluate(
            ({ lang, mode }) => {
              localStorage.setItem('projectbrief.locale', lang)
              localStorage.setItem('projectbrief.theme', mode)
            },
            { lang, mode },
          )
          for (const [name, url, ready] of surfaces) {
            await page.goto(origin + url)
            await page.locator(ready).first().waitFor()
            if (name === 'task') {
              await page.locator('.agent-conversation > summary').click()
              await page.locator('.agent-thread').first().waitFor()
              await page.locator('.agent-run-history > summary').first().click()
            }
            await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
            await page.evaluate(() => document.fonts.ready)
            await page.waitForTimeout(220)
            assert.ok(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
              `${name} ${lang} ${width}`,
            )
            if (name === 'task') {
              const agentsBox = await page.locator('.task-agent-work').boundingBox()
              const commentsBox = await page.locator('.comments-panel').boundingBox()
              assert.ok(
                commentsBox.y >= agentsBox.y + agentsBox.height - 1,
                'Agent dialogue and client comments do not overlap',
              )
            }
            assert.equal(await page.locator('html').getAttribute('lang'), lang)
            assert.equal(
              await page.locator('html').getAttribute('data-theme'),
              mode === 'system' ? 'dark' : mode,
            )
            await page.screenshot({
              path: path.join(output, `${name}-${lang}-${mode}-${width}.png`),
              fullPage: true,
            })
            captures++
            if (name === 'keys') {
              await page.locator('#integration-tab-agents').click()
              await page.locator('[data-agent-id]').first().getByRole('button').first().click()
              await page.locator('dialog[open] [data-agent-connection] summary').click()
              assert.equal(await page.locator('#agent-token-expiry').count(), 0)
              await page.screenshot({
                path: path.join(output, `agent-settings-${lang}-${mode}-${width}.png`),
                fullPage: true,
              })
              captures++
              await page.keyboard.press('Escape')
            }
            if (name === 'task') {
              await page.locator('[data-task-agents] .agent-result > button').first().click()
              await page.locator('#agent-full-report').waitFor()
              await page.screenshot({
                path: path.join(output, `full-report-${lang}-${mode}-${width}.png`),
                fullPage: true,
              })
              captures++
              await page.keyboard.press('Escape')
              await page.locator('[data-task-agents] .agent-result > button').last().click()
              await page.locator('.agent-share-dialog details summary').click()
              assert.ok(await page.locator('#agent-publication-text').isVisible())
              await page.screenshot({
                path: path.join(output, `share-${lang}-${mode}-${width}.png`),
                fullPage: true,
              })
              captures++
              await page.keyboard.press('Escape')
            }
            if (name === 'editor') {
              await page.locator('.ai-disclosure-control button').focus()
              assert.ok(await page.locator('.ai-disclosure-tooltip').isVisible())
              await page.screenshot({
                path: path.join(output, `ai-tooltip-${lang}-${mode}-${width}.png`),
                fullPage: true,
              })
              captures++
              await page.keyboard.press('Escape')
              assert.equal(await page.locator('.ai-disclosure-tooltip').isVisible(), false)
              await page.locator('.ai-button').click()
              await page.locator('#ai-description').waitFor()
              await page.screenshot({
                path: path.join(output, `ai-proposal-${lang}-${mode}-${width}.png`),
                fullPage: true,
              })
              captures++
              await page.keyboard.press('Escape')
            }
          }
        }
  }
  await call('/api/security/confirm', 'POST', { recovery_code: 'Live-browser-code-1' })
  await page.goto(origin + '/admin/settings/integrations')
  await call(`/api/admin/projects/${fixture.project}/team/agents/${agentId}`, 'DELETE')
  const settings = (await call('/api/admin/integrations/agents')).data.find(
    (agent) => agent.id === agentId,
  )
  await call('/api/admin/integrations/agents/' + agentId, 'PATCH', {
    name: settings.name,
    description: settings.description,
    instructions: settings.instructions,
    kind: settings.kind,
    active: settings.active,
    projectIds: settings.projectIds,
    permissions: settings.permissions,
  })
  const withoutMember = (await call('/api/admin/projects/' + fixture.project)).data
  assert.ok(
    !withoutMember.agents.some((agent) => agent.id === agentId),
    'unchanged save does not restore a removed membership',
  )
  const denied = await fetch(origin + '/api/v1/agent/tasks/' + fixture.task + '/messages', {
    headers: { Authorization: `Bearer ${token}` },
  })
  assert.equal(denied.status, 404)
  await call('/api/admin/integrations/tokens/' + key.id, 'DELETE')
  const revoked = await fetch(origin + '/api/v1/agent/me', {
    headers: { Authorization: `Bearer ${token}` },
  })
  assert.equal(revoked.status, 401)
  results.push(
    'grant/membership/token revocation denies access immediately, without hidden membership restoration',
  )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'QA.json'),
    JSON.stringify(
      {
        ok: true,
        package: !!packageRoot,
        results,
        captures,
        errors,
        elapsedMs: Date.now() - qaStarted,
        providerRequests,
        providerMetrics,
        provider: liveEnvPath ? 'groq' : 'deterministic',
      },
      null,
      2,
    ),
  )
  console.log(JSON.stringify({ ok: true, results, captures, output }))
} catch (error) {
  console.error(
    JSON.stringify({
      pageErrors: errors,
      failure: error.message,
      providerRequests,
      providerMetrics,
    }),
  )
  if (browser) {
    const page = browser.contexts()[0]?.pages()[0]
    if (page) {
      await writeFile(path.join(output, 'failure.txt'), await page.locator('body').innerText())
      await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
    }
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
