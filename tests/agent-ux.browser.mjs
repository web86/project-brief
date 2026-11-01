import { startWebhookReceiver } from './agent-ux.webhook.mjs'
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
const liveEnvPath = process.env.AGENT_UX_GROQ_TEST_ENV
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

const hotfix = process.env.AGENT_UX_HOTFIX === '1'
const refinement = process.env.AGENT_UX_RELEASE910 === '1' || hotfix
const mysql = !refinement || hotfix
const smtp =
  refinement && !hotfix
    ? await (await import('./release910.browser-steps.mjs')).startLocalSmtp()
    : null
const packageRoot = process.env.AGENT_UX_PACKAGE_ROOT
const exampleScript = packageRoot
  ? path.join(packageRoot, 'agent-api/examples/collaboration.mjs')
  : path.join(root, 'docs/agent-ux-shared-keys/examples/collaboration.mjs')
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-editor-'))
const app = path.join(scratch, 'project-brief-app'),
  output = path.join(
    '/tmp',
    hotfix
      ? packageRoot
        ? 'project-brief-hotfix-package-qa'
        : 'project-brief-hotfix-source-qa'
      : refinement
        ? packageRoot
          ? 'project-brief-release910-package-qa'
          : 'project-brief-release910-source-qa'
        : liveEnvPath
          ? 'project-brief-agent-ux-groq-qa'
          : packageRoot
            ? 'project-brief-agent-ux-package-qa'
            : 'project-brief-agent-ux-qa',
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
  MAIL_MAILER: refinement && !hotfix ? 'smtp' : 'array',
  ...(refinement && !hotfix
    ? {
        MAIL_HOST: '127.0.0.1',
        MAIL_PORT: String(smtp.port),
        MAIL_FROM_ADDRESS: 'qa@example.test',
        MAIL_FROM_NAME: 'Synthetic QA',
        MAIL_SCHEME: 'smtp',
        MAIL_USERNAME: '',
        MAIL_PASSWORD: '',
      }
    : {}),
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
let backend, frontend, browser, receiver
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
  if (packageRoot && !mysql) {
    const file = path.join(app, 'tests/live-sync-fixture.php')
    await writeFile(
      file,
      (await readFile(file, 'utf8')).replace(
        "require __DIR__.'/../vendor/autoload.php';",
        "require __DIR__.'/qa-autoload.php';",
      ),
    )
  }
  const previousMigrations = path.join(scratch, 'previous-migrations')
  await mkdir(previousMigrations)
  for (const file of await readdir(path.join(app, 'database/migrations'))) {
    if (file < '2026_11_01_111241_add_shared_agent_credentials.php')
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
  const fixture = JSON.parse(
    php([
      'tests/live-sync-fixture.php',
      ...(hotfix ? ['hotfix'] : refinement ? ['release910'] : []),
    ]),
  )
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  if (!refinement) {
    await writeFile(
      path.join(app, 'tests/controlled-webhook-dns.php'),
      `<?php
class AgentUxControlledDnsProvider extends \\Illuminate\\Support\\ServiceProvider {
  public function register(): void {
    if (!app()->environment('testing') || !in_array(config('database.connections.mysql.database'), ['projectbrief_m96_browser_qa','projectbrief_m96_package_qa','projectbrief_m99_groq_qa'], true)) { throw new \\RuntimeException('Disposable QA environment required'); }
    $this->app->bind(\\App\\Services\\WebhookDestination::class, fn () => new class extends \\App\\Services\\WebhookDestination {
      public function addresses(string $host): array {
        $file=base_path('tests/controlled-webhook-dns.json');
        $data=is_file($file)?json_decode(file_get_contents($file),true):[];
        return ($data['host']??null)===$host ? $data['addresses'] : parent::addresses($host);
      }
    });
    \\Illuminate\\Support\\Facades\\Http::globalMiddleware(fn ($handler) => function ($request, $options) use ($handler) {
      if (($options['verify']??true)!==true) { throw new \\RuntimeException('TLS verification must remain enabled'); }
      $options['verify']='/etc/ssl/cert.pem';
      return $handler($request, $options)->then(null, function ($error) {
        $context=method_exists($error,'getHandlerContext')?$error->getHandlerContext():[];
        file_put_contents(base_path('tests/http-diagnostics.json'),json_encode(['errorType'=>get_class($error),'errorFile'=>basename($error->getFile()),'errorLine'=>$error->getLine(),'previousType'=>$error->getPrevious()?get_class($error->getPrevious()):null,'previousFile'=>$error->getPrevious()?basename($error->getPrevious()->getFile()):null,'previousLine'=>$error->getPrevious()?->getLine(),'errno'=>$context['errno']??null,'tlsVerifyResult'=>$context['ssl_verify_result']??null,'httpCode'=>$context['http_code']??null,'knownError'=>in_array($error->getMessage(),['response_too_large','curl_required'],true)?$error->getMessage():null]));
        throw $error;
      });
    });
  }
}`,
    )
    const providersFile = path.join(app, 'bootstrap/providers.php')
    await writeFile(
      providersFile,
      (await readFile(providersFile, 'utf8'))
        .replace('<?php', '<?php\nrequire_once __DIR__."/../tests/controlled-webhook-dns.php";')
        .replace('return [', 'return [\n    \\AgentUxControlledDnsProvider::class,'),
    )
  }
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
  context.setDefaultNavigationTimeout(30000)
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
  if (hotfix) {
    await (
      await import('./agent-webhook-hotfix.browser-steps.mjs')
    ).runHotfix({ page, context, origin, fixture, call, output, packageRoot })
  } else if (refinement) {
    await (
      await import('./release910.browser-steps.mjs')
    ).runRelease910({ browser, context, page, origin, fixture, call, output, packageRoot, smtp })
  } else {
    const scopes = [
      'agent:profile',
      'agent:tasks:read',
      'agent:tasks:report',
      'agent:runs:read',
      'agent:runs:write',
      'agent:messages:read',
      'agent:messages:create',
      'agent:attachments:read',
      'agent:attachments:create',
    ]
    const taskUrl = `/admin/projects/${fixture.project}/brief/task/${fixture.task}`
    await page.goto(origin + taskUrl)
    await page.locator('.task-heading').waitFor()
    assert.equal(
      await page.locator('[data-task-agents], [data-agent-chat]').count(),
      0,
      'Ordinary projects have no empty AI sections',
    )
    await page.goto(origin + '/admin/settings/integrations')
    assert.equal(await page.locator('#api-key-create-form').count(), 0)
    await page.getByRole('button', { name: 'Create API', exact: true }).click()
    await page.locator('#token-type').selectOption('agent')
    await page.locator('#token-name').fill('Shared trusted QA key')
    for (const scope of scopes)
      await page.locator(`#api-key-create-form input[value="${scope}"]`).check()
    await page.locator('button[form="api-key-create-form"]').click()
    await page.locator('.api-key-reveal').waitFor()
    const token = await page.locator('.api-key-reveal').innerText()
    await page
      .locator('#api-key-dialog')
      .locator('xpath=ancestor::dialog')
      .getByRole('button', { name: 'Close', exact: true })
      .click()
    const key = (await call('/api/admin/integrations')).tokens.find(
      (item) => item.name === 'Shared trusted QA key',
    )
    assert.equal(key.shared, true)
    assert.equal(key.linkedAgents.length, 0)
    const agentIds = []
    await page.locator('#integration-tab-agents').click()
    for (const [index, name] of ['Analyst One', 'Analyst Two'].entries()) {
      await page.getByRole('button', { name: 'Create agent', exact: true }).click()
      const dialog = page.locator('#agent-editor-title').locator('xpath=ancestor::dialog')
      await dialog.locator('#agent-name').fill(name)
      await dialog
        .locator('#agent-instructions')
        .fill('AGENT-V1: Review synthetic requirements and preserve authorization.')
      await dialog.locator(`#agent-project-options input[value="${fixture.project}"]`).check()
      await dialog.locator('#agent-credential').selectOption(key.id)
      assert.equal(await dialog.locator('#agent-kind, [data-agent-permissions]').count(), 0)
      await dialog.locator('button[form="agent-editor"][type="submit"]').click()
      await dialog.waitFor({ state: 'hidden' })
      agentIds.push(
        (await call('/api/admin/integrations/agents')).data.find((agent) => agent.name === name).id,
      )
    }
    const [agentId, secondAgent] = agentIds
    const project = (await call('/api/admin/projects/' + fixture.project)).data
    assert.equal(project.agents.length, 2)
    const listed = (await call('/api/admin/projects')).data.find(
      (item) => item.id === fixture.project,
    )
    assert.equal(listed.agentsCount, 2)
    const headers = (agent) => ({
      Authorization: `Bearer ${token}`,
      'X-ProjectBrief-Agent-ID': agent,
    })
    for (const agent of agentIds)
      assert.equal(
        (await fetch(origin + '/api/v1/agent/me', { headers: headers(agent) })).status,
        200,
      )
    assert.equal(
      (await fetch(origin + '/api/v1/agent/me', { headers: { Authorization: `Bearer ${token}` } }))
        .status,
      403,
    )
    results.push(
      'Shared key created before registrations; two explicit linked identities, scope-only setup, automatic memberships and factual project counter',
    )
    await call(`/api/admin/projects/${fixture.project}/agent-instructions`, 'PATCH', {
      instructions: 'PROJECT-V1: Vue/PHP, English reports, no secrets.',
    })

    receiver = await startWebhookReceiver()
    const destinationProbe = path.join(app, 'tests/destination-probe.php')
    await writeFile(
      destinationProbe,
      `<?php require __DIR__.'/../vendor/autoload.php'; $app=require __DIR__.'/../bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); try { app(App\\Services\\WebhookDestination::class)->resolve($argv[1]); echo json_encode(['valid'=>true]); } catch (Throwable $e) { $host=parse_url($argv[1],PHP_URL_HOST); $ips=app(App\\Services\\WebhookDestination::class)->addresses($host); echo json_encode(['valid'=>false,'dnsAnswers'=>count($ips),'allPublic'=>count($ips)>0 && count(array_filter($ips,fn($ip)=>app(App\\Services\\WebhookDestination::class)->publicIp($ip)))===count($ips)]); }`,
    )
    await writeFile(
      path.join(app, 'tests/controlled-webhook-dns.json'),
      JSON.stringify({ host: new URL(receiver.url).hostname, addresses: ['127.0.0.1'] }),
    )
    assert.equal(
      JSON.parse(php([destinationProbe, receiver.url])).valid,
      false,
      'Original SSRF validator still rejects private DNS answers',
    )
    await writeFile(
      path.join(app, 'tests/controlled-webhook-dns.json'),
      JSON.stringify({ host: new URL(receiver.url).hostname, addresses: receiver.addresses }),
    )
    let receiverReady = false,
      probeError,
      destinationStatus
    for (let attempt = 0; attempt < 25; attempt++) {
      try {
        const response = await receiver.probe()
        destinationStatus = JSON.parse(php([destinationProbe, receiver.url]))
        if (response.headers.get('x-projectbrief-qa') === 'controlled' && destinationStatus.valid) {
          receiverReady = true
          break
        }
      } catch (error) {
        probeError = error.cause?.code || error.code || error.name
      }
      await new Promise((resolve) => setTimeout(resolve, 2000))
    }
    assert.ok(
      receiverReady,
      'Controlled HTTPS receiver/DNS did not become ready: ' +
        JSON.stringify({ destinationStatus, probeError, tunnel: receiver.diagnostics() }),
    )
    await page.locator('#integration-tab-webhooks').click()
    await page.getByRole('button', { name: 'Create webhook', exact: true }).click()
    await page.locator('#hook-event-type').selectOption('agent')
    await page.locator('#hook-name').fill('Controlled HTTPS receiver')
    await page.locator('#hook-url').fill(receiver.url)
    for (const event of ['agent.task.assigned', 'agent.run.requested', 'agent.run.cancelled'])
      await page.locator(`#create-webhooks input[value="${event}"]`).check()
    await page.locator('button[form="create-webhooks"]').click()
    await page.locator('.integration-secret-value').waitFor()
    receiver.setSecret(await page.locator('.integration-secret-value').innerText())
    await page.locator('dialog[open]').getByRole('button', { name: 'Close', exact: true }).click()
    const hook = (await call('/api/admin/integrations')).webhooks.find(
      (item) => item.name === 'Controlled HTTPS receiver',
    )
    async function drain(expectTransient = false) {
      const child = spawn(
        'php',
        [
          '-d',
          'curl.cainfo=/etc/ssl/cert.pem',
          'artisan',
          'integrations:webhooks',
          '--limit=20',
          '--seconds=30',
        ],
        { cwd: app, env, stdio: ['ignore', 'pipe', 'pipe'] },
      )
      let out = '',
        err = ''
      child.stdout.on('data', (data) => (out += data))
      child.stderr.on('data', (data) => (err += data))
      assert.equal(
        await new Promise((resolve) => child.on('close', resolve)),
        expectTransient ? 1 : 0,
        err +
          (await readFile(path.join(app, 'tests/http-diagnostics.json'), 'utf8').catch(() => '')),
      )
      if (expectTransient) assert.match(err, /remote_transient http=503/)
      else assert.equal(err.trim(), '')
      assert.equal(out.trim(), '')
    }
    const metadataScript = path.join(app, 'tests/webhook-state.php')
    await writeFile(
      metadataScript,
      `<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if(!app()->environment('testing')||!in_array(config('database.connections.mysql.database'),['projectbrief_m96_browser_qa','projectbrief_m96_package_qa','projectbrief_m99_groq_qa'],true)){throw new RuntimeException('Disposable DB required');}
$q=Illuminate\\Support\\Facades\\DB::table('webhook_deliveries')->whereIn('webhook_endpoint_id',Illuminate\\Support\\Facades\\DB::table('webhook_endpoints')->where('uuid',$argv[1])->select('id'));
if(($argv[2]??'')==='advance'){$q->where('status','pending')->update(['available_at'=>now()]);}
echo json_encode($q->orderBy('id')->get(['status','http_status','attempts','error_code']));`,
    )
    await page.goto(origin + taskUrl)
    await page.locator('[data-task-agents]').waitFor()
    assert.equal(await page.locator('[data-task-agents]').count(), 1)
    assert.equal(await page.locator('[data-agent-chat]').count(), 0)
    await page.locator('[data-task-agents] select').selectOption(agentId)
    await page.locator('.agent-assign-form button').click()
    await page.locator('.agent-follow-up textarea').waitFor()
    const initial = (await call('/api/admin/tasks/' + fixture.task + '/agent-collaboration'))
      .data[0]
    assert.equal(
      initial.runs.length,
      0,
      'Compact assignment defers execution until the first instruction',
    )
    await drain(true)
    let deliveries = JSON.parse(php([metadataScript, hook.id]))
    assert.equal(deliveries[0].http_status, 503)
    php([metadataScript, hook.id, 'advance'])
    await drain()
    deliveries = JSON.parse(php([metadataScript, hook.id]))
    assert.equal(deliveries[0].http_status, 204)
    assert.equal(deliveries[0].attempts, 2)
    assert.equal(receiver.received[0].delivery, receiver.received[1].delivery)
    assert.equal(receiver.received[0].raw, receiver.received[1].raw)
    assert.equal(receiver.processed.size, 1)
    assert.equal((await receiver.replayLast()).status, 204)
    assert.equal(receiver.processed.size, 1, 'Receiver does not process the duplicate event twice')
    results.push(
      'Real public HTTPS webhook: original DNS/SSRF/TLS checks, raw-body HMAC, HTTP 503 then 204 with the same delivery ID and payload',
    )

    const instruction = liveEnvPath
      ? 'FIRST-PRIVATE-INSTRUCTION: Review a synthetic contact form. Give two ambiguities and three acceptance criteria.'
      : 'FIRST-PRIVATE-INSTRUCTION\n' +
        Array.from({ length: 35 }, (_, i) => `Requirement ${i + 1}: inspect keyboard access.`).join(
          '\n',
        )
    await page.locator('.agent-follow-up textarea').fill(instruction)
    await page.locator('.agent-follow-up input[type=file]').setInputFiles({
      name: 'synthetic-brief.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('SYNTHETIC-PRIVATE-FILE: keyboard navigation only.'),
    })
    await page.locator('.agent-follow-up button[type=submit]').click()
    await page.waitForFunction(
      () => document.querySelector('.agent-follow-up textarea')?.value === '',
    )
    const current = () => call('/api/admin/tasks/' + fixture.task + '/agent-collaboration')
    const first = (await current()).data.find((row) => !row.revokedAt).runs[0]
    const firstContext = (
      await (
        await fetch(origin + `/api/v1/agent/tasks/${fixture.task}/runs/${first.id}`, {
          headers: headers(agentId),
        })
      ).json()
    ).data
    assert.equal(
      firstContext.context.agentInstruction,
      'AGENT-V1: Review synthetic requirements and preserve authorization.',
    )
    assert.equal(
      firstContext.context.projectInstruction,
      'PROJECT-V1: Vue/PHP, English reports, no secrets.',
    )
    assert.equal(firstContext.context.instruction, instruction.replaceAll('\n', '\r\n'))
    const messages = (
      await (
        await fetch(origin + `/api/v1/agent/tasks/${fixture.task}/messages`, {
          headers: headers(agentId),
        })
      ).json()
    ).data
    assert.equal(messages[0].attachments.length, 1)
    const privateFile = messages[0].attachments[0]
    assert.equal(
      (await fetch(origin + privateFile.downloadUrl, { headers: headers(agentId) })).status,
      200,
    )
    assert.equal(
      (await fetch(origin + privateFile.downloadUrl, { headers: headers(secondAgent) })).status,
      404,
    )
    await drain()
    await page.getByRole('button', { name: 'Check new messages', exact: true }).first().click()
    async function runExecutor() {
      const child = spawn('node', [exampleScript], {
        cwd: root,
        env: {
          PATH: process.env.PATH,
          PROJECT_BRIEF_URL: origin,
          PROJECT_BRIEF_TOKEN: token,
          PROJECT_BRIEF_AGENT_ID: agentId,
          AGENT_READ_TEXT_FILES: '1',
          AGENT_PROVIDER: liveEnvPath ? 'groq' : 'deterministic',
          ...providerEnvironment,
          ...(process.env.NODE_EXTRA_CA_CERTS
            ? { NODE_EXTRA_CA_CERTS: process.env.NODE_EXTRA_CA_CERTS }
            : {}),
        },
        stdio: ['ignore', 'pipe', 'pipe'],
      })
      let out = '',
        err = ''
      child.stdout.on('data', (data) => (out += data))
      child.stderr.on('data', (data) => (err += data))
      const exitCode = await new Promise((resolve) => child.on('close', resolve))
      assert.ok(out.trim(), 'External executor returns an outcome')
      const result = JSON.parse(out)
      providerRequests += result.providerRequests || 0
      providerMetrics.push(...(result.providerMetrics || []))
      assert.equal(exitCode, 0, err)
      assert.equal(result.outcomes.filter((row) => row.state === 'submitted').length, 1)
    }
    await runExecutor()
    await page.locator('[data-agent-chat] .agent-chat-heading > button').first().click()
    await page.locator('.agent-dialogue-message.agent').waitFor()
    if (!liveEnvPath) {
      const firstMessage = page.locator('.agent-dialogue-message.agent').first()
      await firstMessage.getByRole('button', { name: 'Expand', exact: true }).click()
      assert.equal(await firstMessage.locator('.agent-message-text.is-collapsed').count(), 0)
      await firstMessage.getByRole('button', { name: 'Collapse', exact: true }).click()
    }
    const agentSettings = (await call('/api/admin/integrations/agents')).data.find(
      (agent) => agent.id === agentId,
    )
    await call('/api/admin/integrations/agents/' + agentId, 'PATCH', {
      name: agentSettings.name,
      kind: agentSettings.kind,
      active: true,
      projectIds: agentSettings.projectIds,
      permissions: agentSettings.permissions,
      credentialId: key.id,
      instructions: 'AGENT-V2: Refine the synthetic acceptance criteria.',
    })
    await call(`/api/admin/projects/${fixture.project}/agent-instructions`, 'PATCH', {
      instructions: 'PROJECT-V2: Preserve keyboard access and English output.',
    })
    await page
      .locator('.agent-follow-up textarea')
      .fill('SECOND-PRIVATE-INSTRUCTION: refine acceptance criteria and ask one question.')
    await page.locator('.agent-follow-up button[type=submit]').click()
    await page.waitForFunction(
      () => document.querySelector('.agent-follow-up textarea')?.value === '',
    )
    const second = (await current()).data.find((row) => !row.revokedAt).runs[0]
    const secondContext = (
      await (
        await fetch(origin + `/api/v1/agent/tasks/${fixture.task}/runs/${second.id}`, {
          headers: headers(agentId),
        })
      ).json()
    ).data
    assert.equal(
      secondContext.context.agentInstruction,
      'AGENT-V2: Refine the synthetic acceptance criteria.',
    )
    assert.equal(
      secondContext.context.projectInstruction,
      'PROJECT-V2: Preserve keyboard access and English output.',
    )
    assert.ok(secondContext.context.history.some((message) => message.author === 'agent'))
    const frozen = (
      await (
        await fetch(origin + `/api/v1/agent/tasks/${fixture.task}/runs/${first.id}`, {
          headers: headers(agentId),
        })
      ).json()
    ).data
    assert.deepEqual(frozen.context, firstContext.context)
    assert.equal(frozen.contextVersion, firstContext.contextVersion)
    await drain()
    await runExecutor()
    await page.locator('[data-agent-chat] .agent-chat-heading > button').first().click()
    await page.waitForFunction(
      () => document.querySelectorAll('.agent-dialogue-message.agent').length === 2,
    )
    assert.equal((await call('/api/admin/tasks/' + fixture.task)).data.status, 'new')
    assert.equal(await page.locator('.internal-agent-results,.agent-run-history').count(), 0)
    await page
      .locator('.agent-dialogue-message.agent')
      .last()
      .getByRole('button', { name: 'Share with client', exact: true })
      .click()
    await page
      .locator('#agent-publication-text')
      .fill('Human-reviewed acceptance criteria for the client.')
    await page.locator('button[form=agent-result-publication]').click()
    await page.locator('.agent-share-dialog').waitFor({ state: 'hidden' })
    await page
      .locator('.agent-dialogue-message.agent')
      .last()
      .getByRole('button', { name: 'Share with team', exact: true })
      .click()
    await page.locator('#agent-publication-text').fill('Reviewed private team summary.')
    await page.locator('button[form=agent-result-publication]').click()
    await page.locator('.agent-share-dialog').waitFor({ state: 'hidden' })
    const client = await browser.newContext()
    await client.request.get(origin + '/access/' + fixture.token)
    const publicTask = await (
      await client.request.get(origin + '/api/client/tasks/' + fixture.task)
    ).json()
    assert.ok(JSON.stringify(publicTask).includes('Human-reviewed acceptance criteria'))
    for (const privateText of [
      'PRIVATE-INSTRUCTION',
      'Reviewed private team summary',
      'synthetic-brief.txt',
    ])
      assert.ok(!JSON.stringify(publicTask).includes(privateText))
    assert.equal((await client.request.get(origin + privateFile.downloadUrl)).status(), 401)
    assert.equal(
      (
        await client.request.get(origin + '/api/client/attachments/' + privateFile.id + '/download')
      ).status(),
      404,
    )
    await client.close()
    results.push(
      'Two independent Node executions through a shared key; exact three-level contexts, future-only updates, frozen history, private chat files and explicit separate client/team copies',
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
    const collaboration = (await current()).data.find((row) => !row.revokedAt)
    const runUrl = `/api/admin/tasks/${fixture.task}/agent-assignments/${collaboration.id}/runs`
    // Keep the existing 30/minute settings throttle active during concurrency QA.
    await new Promise((resolve) => setTimeout(resolve, 61000))
    const sameRequest = { instruction: 'CONCURRENT-REQUEST', submissionId: crypto.randomUUID() }
    const csrf = (await (await context.request.get(origin + '/api/csrf')).json()).token
    const creates = await Promise.all(
      Array.from({ length: 5 }, () =>
        context.request.post(origin + runUrl, {
          headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
          data: sameRequest,
        }),
      ),
    )
    assert.ok(
      creates.every((response) => response.status() === 201),
      JSON.stringify(creates.map((response) => response.status())),
    )
    const ids = await Promise.all(creates.map(async (response) => (await response.json()).data.id))
    assert.equal(new Set(ids).size, 1)
    const concurrentRun = ids[0]
    const claims = await Promise.all(
      Array.from({ length: 5 }, (_, i) =>
        fetch(origin + `/api/v1/agent/tasks/${fixture.task}/runs/${concurrentRun}/start`, {
          method: 'POST',
          headers: {
            ...headers(agentId),
            'Content-Type': 'application/json',
            'Idempotency-Key': 'parallel-' + i,
          },
          body: JSON.stringify({ assignmentId: collaboration.id, runId: concurrentRun }),
        }),
      ),
    )
    assert.equal(claims.filter((response) => response.status === 200).length, 1)
    assert.equal(claims.filter((response) => response.status === 409).length, 4)
    await call(`/api/admin/tasks/${fixture.task}/agent-runs/${concurrentRun}/cancel`, 'POST', {})
    results.push(
      'MariaDB concurrent same-submission requests create one run; competing shared-key claims execute once',
    )
    if (liveEnvPath) assert.equal(providerRequests, 2)
    let captures = 0
    if (process.env.AGENT_UX_SKIP_VISUALS !== '1') {
      const surfaces = [
        ['keys', '/admin/settings/integrations', '#integration-panel-tokens'],
        ['projects', '/admin', '.admin-project-card'],
        ['team', `/admin/projects/${fixture.project}/team`, '[data-team-agents]'],
        ['task', taskUrl, '[data-agent-chat]'],
        ['editor', taskUrl + '/edit', '#idea-description[contenteditable]'],
      ]
      for (const lang of ['ru', 'en', 'tr', 'zh-CN'])
        for (const mode of ['light', 'dark', 'system'])
          for (const width of [1440, 390]) {
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
            async function capture(name) {
              await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
              await page.evaluate(() => document.fonts.ready)
              await page.waitForTimeout(220)
              assert.ok(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
                `${name} ${lang} ${mode} ${width}`,
              )
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
            }
            for (const [name, url, ready] of surfaces) {
              await page.goto(origin + url)
              await page.locator(ready).first().waitFor()
              await capture(name)
              if (name === 'keys') {
                await page
                  .locator('#integration-panel-tokens .integration-section-heading button')
                  .click()
                await page.locator('#token-type').selectOption('agent')
                const scopeBox = await page.locator('.api-key-scopes input').first().boundingBox()
                assert.ok(
                  scopeBox.width >= 14 && scopeBox.height >= 14,
                  'Scopes keep usable checkbox dimensions',
                )
                await capture('api-key-modal')
                await page.keyboard.press('Escape')
                await page.locator('#integration-tab-agents').click()
                await page
                  .locator('[data-agent-id]')
                  .first()
                  .getByRole('button', { name: /Configure|Настроить|Yapılandır|配置/ })
                  .click()
                await capture('agent-settings')
                await page.keyboard.press('Escape')
                await page.locator('#integration-tab-webhooks').click()
                await page
                  .locator('#integration-panel-webhooks .integration-section-heading button')
                  .click()
                await page.locator('#hook-event-type').selectOption('agent')
                assert.equal(
                  await page
                    .locator('#webhook-editor')
                    .locator('xpath=ancestor::dialog')
                    .getByRole('heading')
                    .count(),
                  1,
                )
                await capture('webhook-modal')
                await page.keyboard.press('Escape')
              }
              if (name === 'task') {
                const management = await page.locator('.developer-panel').boundingBox(),
                  agentBox = await page.locator('[data-task-agents]').boundingBox(),
                  chat = await page.locator('[data-agent-chat]').boundingBox(),
                  comments = await page.locator('.comments-panel').boundingBox()
                assert.ok(agentBox.y >= management.y + management.height - 1)
                assert.ok(comments.y >= chat.y + chat.height - 1)
                if (width === 1440) assert.ok(agentBox.x >= chat.x + chat.width - 1)
                const long = page
                  .locator('.agent-dialogue-message.agent')
                  .filter({ has: page.locator('.agent-message-text.is-collapsed') })
                  .first()
                if (await long.count()) {
                  await long.locator('button[aria-expanded]').click()
                  await capture('expanded-reply')
                }
                await page
                  .locator('.agent-dialogue-message.agent')
                  .last()
                  .locator('.agent-message-actions button')
                  .last()
                  .click()
                await page.locator('.agent-share-dialog details summary').click()
                assert.ok(await page.locator('#agent-publication-text').isVisible())
                assert.ok(await page.locator('button[form=agent-result-publication]').isVisible())
                await capture('share')
                await page.keyboard.press('Escape')
              }
              if (name === 'editor') {
                await page.locator('.ai-disclosure-control button').focus()
                assert.ok(await page.locator('.ai-disclosure-tooltip').isVisible())
                await capture('ai-tooltip')
                await page.keyboard.press('Escape')
                await page.locator('.ai-button').click()
                await page.locator('#ai-description').waitFor()
                await capture('ai-proposal')
                await page.keyboard.press('Escape')
              }
            }
          }
    }
    // Restore English for action assertions after the localization matrix.
    await page.evaluate(() => localStorage.setItem('projectbrief.locale', 'en'))
    await page.goto(origin + taskUrl)
    await page.locator('[data-task-agents] button').first().click()
    await page.waitForFunction(
      () => !document.querySelector('[data-task-agents] .agent-compact-row'),
    )
    await page.waitForFunction(
      () =>
        !document.querySelector('[data-agent-chat] .agent-follow-up') &&
        document.querySelectorAll('.agent-dialogue-message.agent').length === 2,
    )
    assert.equal(await page.locator('[data-agent-chat] .agent-follow-up').count(), 0)
    assert.equal(await page.locator('.agent-dialogue-message.agent').count(), 2)
    const before = receiver.received.length
    // The full visual matrix can outlast fresh MFA; obtain a real new synthetic proof.
    await call('/api/security/confirm', 'POST', { recovery_code: 'Live-browser-code-2' })
    await call(`/api/admin/projects/${fixture.project}/team/agents/${agentId}`, 'DELETE')
    await drain()
    assert.equal(
      receiver.received.length,
      before,
      'Pending deliveries are cancelled after access revocation',
    )
    const state = (await current()).data[0]
    assert.ok(state.revokedAt)
    results.push(
      'Assignment removal keeps read-only chat/history; project access revocation cancels pending webhook delivery without another network send',
    )
    const developer = await browser.newContext()
    const devPage = await developer.newPage()
    await devPage.goto(origin + '/admin/login')
    await devPage.locator('#admin-email').fill('developer@example.test')
    await devPage.locator('#admin-password').fill('Local-test-password!')
    await devPage.locator('form button[type=submit]').click()
    await devPage.waitForURL('**/admin/security')
    await devPage.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
    await devPage.locator('#security-code').fill('Live-browser-code-0')
    await devPage.getByRole('button', { name: 'Confirm identity', exact: true }).click()
    await devPage.locator('.onboarding-continue, .admin-project-card').first().waitFor()
    await devPage.goto(origin + taskUrl)
    await devPage.getByRole('link', { name: 'Edit', exact: true }).click()
    await devPage.locator('#idea-title').fill('Developer-reviewed synthetic requirement')
    await devPage.locator('.idea-form button[type=submit]').click()
    await devPage.waitForURL('**' + taskUrl)
    const preApproval = (await call('/api/admin/tasks/' + fixture.task)).data
    assert.equal(preApproval.title, 'Developer-reviewed synthetic requirement')
    const clientApproval = await browser.newContext()
    await clientApproval.request.get(origin + '/access/' + fixture.token)
    const clientCsrf = (await (await clientApproval.request.get(origin + '/api/csrf')).json()).token
    assert.equal(
      (
        await clientApproval.request.post(
          origin + '/api/client/tasks/' + fixture.task + '/approve',
          {
            headers: { 'X-CSRF-TOKEN': clientCsrf, Accept: 'application/json' },
          },
        )
      ).status(),
      200,
    )
    const late = await context.request.patch(origin + '/api/admin/tasks/' + fixture.task, {
      headers: {
        'X-CSRF-TOKEN': (await (await context.request.get(origin + '/api/csrf')).json()).token,
        Accept: 'application/json',
      },
      data: { title: 'Late stale edit', editVersion: preApproval.editVersion },
    })
    assert.equal(late.status(), 403)
    await devPage.reload()
    assert.equal(await devPage.getByRole('link', { name: 'Edit', exact: true }).count(), 0)
    await developer.close()
    await clientApproval.close()
    results.push(
      'Assigned developer edits the rich task before agreement; approval hides editing and server rejects a stale description save',
    )
    await call('/api/security/confirm', 'POST', { recovery_code: 'Live-browser-code-1' })
    await page.goto(origin + '/admin/settings/integrations')
    const sharedCard = page
      .locator('#integration-panel-tokens .integration-item')
      .filter({ hasText: 'Shared trusted QA key' })
    page.once('dialog', (dialog) => dialog.accept())
    await sharedCard.getByRole('button', { name: 'Revoke', exact: true }).click()
    await sharedCard.waitFor({ state: 'hidden' })
    await page.locator('#integration-tab-agents').click()
    await page
      .locator(`[data-agent-id="${secondAgent}"]`)
      .getByRole('button', { name: 'Configure', exact: true })
      .click()
    assert.equal(await page.locator(`#agent-credential option[value="${key.id}"]`).count(), 0)
    await page.keyboard.press('Escape')
    const afterRevoke = await call('/api/admin/integrations')
    assert.ok(!afterRevoke.tokens.some((item) => item.id === key.id))
    for (const agent of agentIds)
      assert.equal(
        (await fetch(origin + '/api/v1/agent/me', { headers: headers(agent) })).status,
        401,
      )
    results.push(
      'Shared-key revocation stops both agents immediately and removes the key from active lists and selectors while preserving audit/history',
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
  }
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
  await smtp?.close()
  await receiver?.close()
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
