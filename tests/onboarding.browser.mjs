import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, readFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import https from 'node:https'
import http from 'node:http'
import { createServer } from 'vite'
const { chromium, webkit, devices } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-platform-plans-'))
const packageRoot = process.env.UX_PACKAGE_ROOT
const phpBin = process.env.PHP_BIN || 'php'
const publicRoot = path.join(scratch, 'domains/brief.web86.site')
const app = path.join(scratch, packageRoot ? 'project-brief-app' : 'app'),
  output =
    process.env.UX_BROWSER_OUTPUT_DIRECTORY ||
    path.join(root, 'artifacts/phase-4b1-ux-polish/after')
await mkdir(app)
await mkdir(output, { recursive: true })
async function port() {
  const server = net.createServer()
  await new Promise((r) => server.listen(0, '127.0.0.1', r))
  const value = server.address().port
  await new Promise((r) => server.close(r))
  return value
}
const backendPort = await port(),
  webPort = await port(),
  origin = `https://localhost:${webPort}`
const env = {
  ...process.env,
  APP_ENV: 'testing',
  REGISTRATION_ENABLED: 'true',
  ONBOARDING_SUBJECT_RETENTION_DAYS: '365',
  GITHUB_LOGIN_CLIENT_ID: 'synthetic-browser-client',
  GITHUB_LOGIN_CLIENT_SECRET: 'synthetic-browser-secret',
  GITHUB_LOGIN_CALLBACK: origin + '/auth/github/callback',
  QA_MAIL_FILE: path.join(scratch, 'mail.json'),
  QA_STATE_FILE: path.join(scratch, 'ui-config.json'),
  GOOGLE_LOGIN_CLIENT_ID: 'synthetic-browser-google',
  GOOGLE_LOGIN_CLIENT_SECRET: 'synthetic-browser-secret',
  GOOGLE_LOGIN_CALLBACK: origin + '/auth/google/callback',
  YANDEX_LOGIN_CLIENT_ID: 'synthetic-browser-yandex',
  YANDEX_LOGIN_CLIENT_SECRET: 'synthetic-browser-secret',
  YANDEX_LOGIN_CALLBACK: origin + '/auth/yandex/callback',
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_URL: origin,
  PASSKEY_ORIGIN: origin,
  PASSKEY_RP_ID: 'localhost',
  FRONTEND_URL: origin,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(scratch, 'test.sqlite'),
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'platform_qa',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'file',
  MAIL_MAILER: 'smtp',
  MAIL_HOST: 'example.test',
  MAIL_FROM_ADDRESS: 'sender@example.test',
  LOG_CHANNEL: 'null',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args, input) {
  const result = spawnSync(phpBin, args, { cwd: app, env, encoding: 'utf8', input })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
let backend,
  tlsServer,
  vite,
  browser,
  counter = 0
const errors = [],
  results = [],
  captures = []
async function createContext(options = {}) {
  return browser.newContext({ ignoreHTTPSErrors: true, ...options })
}
async function call(context, url, method = 'GET', data) {
  let headers = { Accept: 'application/json' }
  if (method !== 'GET')
    headers['X-CSRF-TOKEN'] = (await (await context.request.get(origin + '/api/csrf')).json()).token
  return context.request.fetch(origin + url, { method, data, headers })
}
async function capture(page, name) {
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.locator('.loading-overlay').waitFor({ state: 'hidden' })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(220)
  await page.screenshot({ path: path.join(output, name), fullPage: true })
  captures.push(name)
}
async function login(context, fixture, owner = false) {
  const response = await call(context, '/api/admin/login', 'POST', {
    email: owner ? fixture.owner : fixture.admin,
    password: fixture.password,
  })
  assert.equal(response.status(), 200, await response.text())
  const challenge = await call(context, '/api/security/challenge', 'POST', {
    recovery_code: owner ? 'test-recovery-code' : `qa-recovery-${++counter}`,
  })
  assert.equal(challenge.status(), 200, await challenge.text())
}
try {
  if (packageRoot) {
    await cp(path.join(packageRoot, 'private/project-brief-app'), app, { recursive: true })
    await cp(path.join(packageRoot, 'public'), publicRoot, { recursive: true })
    await cp(path.join(root, 'api/database/factories'), path.join(app, 'database/factories'), {
      recursive: true,
    })
  } else {
    for (const item of [
      'app',
      'config',
      'lang',
      'resources',
      'routes',
      'database',
      'bootstrap',
      'public',
      'artisan',
      'composer.json',
      'composer.lock',
    ])
      await cp(path.join(root, 'api', item), path.join(app, item), {
        recursive: true,
        filter: (f) => !/(?:\.sqlite[^/]*|\/cache\/[^/]+)$/.test(f),
      })
    await cp(path.join(root, 'api/vendor'), path.join(app, 'vendor'), { recursive: true })
  }
  await mkdir(path.join(app, 'tests'))
  await cp(
    path.join(root, 'api/tests/platform-plans-fixture.php'),
    path.join(app, 'tests/platform-plans-fixture.php'),
  )
  for (const dir of [
    'bootstrap/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
  ])
    await mkdir(path.join(app, dir), { recursive: true })
  await writeFile(path.join(app, '.env'), '')
  await writeFile(env.DB_DATABASE, '')
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  if (packageRoot) {
    const loader = String.raw`<?php
spl_autoload_register(function ($class) { foreach (['Database\\Factories\\' => __DIR__.'/../database/factories/', 'Faker\\' => SOURCE_FAKER] as $prefix => $dir) { if (str_starts_with($class, $prefix)) { $path=$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if (is_file($path)) { require_once $path; } } } });
require __DIR__.'/../vendor/autoload.php';
`.replace('SOURCE_FAKER', JSON.stringify(path.join(root, 'api/vendor/fakerphp/faker/src/Faker/')))
    await writeFile(path.join(app, 'tests/qa-autoload.php'), loader)
    const fixturePath = path.join(app, 'tests/platform-plans-fixture.php')
    await writeFile(
      fixturePath,
      (await readFile(fixturePath, 'utf8')).replace(
        "require __DIR__.'/../vendor/autoload.php';",
        "require __DIR__.'/qa-autoload.php';",
      ),
    )
  }

  const fixtureFile = path.join(app, 'tests/platform-plans-fixture.php')
  await writeFile(
    fixtureFile,
    (await readFile(fixtureFile, 'utf8'))
      .replace(
        '$primary = User::factory()',
        "$legacy = User::factory()->owner()->create(['email'=>'compatibility@example.test','password'=>'Plans-QA-only!84']);\n$primary = User::factory()",
      )
      .replace(
        "['admin' => $primary->email",
        "['legacy' => $legacy->email, 'admin' => $primary->email",
      ),
  )
  const fixture = JSON.parse(php(['tests/platform-plans-fixture.php']))
  php(['artisan', 'plans:inventory', '--apply', '--no-interaction'])
  const providersPath = path.join(app, 'bootstrap/providers.php')
  await writeFile(
    providersPath,
    (await readFile(providersPath, 'utf8')).replace(
      '    AppServiceProvider::class,',
      '    AppServiceProvider::class, App\\Providers\\BrowserOnboardingFixture::class,',
    ),
  )
  await writeFile(
    path.join(app, 'app/Providers/BrowserOnboardingFixture.php'),
    String.raw`<?php
namespace App\Providers;
class BrowserOnboardingFixture extends \Illuminate\Support\ServiceProvider {
 public function boot(): void {
  if (!app()->environment('testing') || !str_contains(config('database.connections.sqlite.database'), 'brief-platform-plans-')) { throw new \RuntimeException('Scratch fixture only'); }
  $this->app->bind(\App\Services\AccountMail::class, fn() => new class extends \App\Services\AccountMail {
   public function send(string $email, string $heading, string $message, ?string $url=null, string $locale='en', ?array $branding=null, ?string $actionLabel=null): bool {
    file_put_contents(getenv('QA_MAIL_FILE'), json_encode(['url'=>$url,'email'=>$email,'locale'=>$locale])); return true;
   }
  });
  $overrides = is_file(getenv('QA_STATE_FILE')) ? json_decode(file_get_contents(getenv('QA_STATE_FILE')),true) : [];
  if (isset($overrides['registrationEnabled'])) config(['onboarding.registration_enabled'=>$overrides['registrationEnabled']]);
  if (isset($overrides['providers'])) foreach (['google','yandex','github'] as $provider) if (!in_array($provider,$overrides['providers'],true)) config(['onboarding.providers.'.$provider.'.client_secret'=>null]);
  \Illuminate\Support\Facades\Http::preventStrayRequests();
  \Illuminate\Support\Facades\Http::fake([
   'https://github.com/login/oauth/access_token' => fn($r) => \Illuminate\Support\Facades\Http::response(['access_token'=>$r['code'],'token_type'=>'bearer']),
   'https://api.github.com/user' => fn($r) => \Illuminate\Support\Facades\Http::response(['id'=>str_contains($r->header('Authorization')[0] ?? '', 'synthetic-link-code') ? 840000124 : 840000123,'login'=>'synthetic-browser-name']),
   'https://api.github.com/user/emails*' => \Illuminate\Support\Facades\Http::response([['email'=>'browser-social@example.test','primary'=>true,'verified'=>true]])
  ]);
 }
}`,
  )
  await writeFile(
    path.join(app, 'tests/browser-totp.php'),
    String.raw`<?php require __DIR__.'/../vendor/autoload.php'; echo (new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp($argv[1]);`,
  )
  await writeFile(
    path.join(app, 'tests/browser-ux-owner.php'),
    String.raw`<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || !str_contains(\Illuminate\Support\Facades\DB::getDatabaseName(),'brief-platform-plans-')) throw new \RuntimeException('Scratch fixture only');
$user=\App\Models\User::create(['name'=>'Synthetic UX owner','email'=>$argv[1],'password'=>\Illuminate\Support\Facades\Hash::make('Browser-registration-only!2026'),'preferred_locale'=>$argv[2]]);
app(\App\Services\AuthOnboarding::class)->pending($user);$user->forceFill(['email_verified_at'=>now()])->save();app(\App\Services\AuthOnboarding::class)->prepare($user);
`,
  )
  const certificate = path.join(scratch, 'localhost.pem'),
    certificateKey = path.join(scratch, 'localhost-key.pem')
  assert.equal(
    spawnSync(
      'openssl',
      [
        'req',
        '-x509',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-keyout',
        certificateKey,
        '-out',
        certificate,
        '-days',
        '1',
        '-subj',
        '/CN=localhost',
      ],
      { stdio: 'ignore' },
    ).status,
    0,
  )
  const tls = { key: await readFile(certificateKey), cert: await readFile(certificate) }
  if (packageRoot) {
    tlsServer = https.createServer(tls, (request, response) => {
      const upstream = http.request(
        {
          hostname: '127.0.0.1',
          port: backendPort,
          path: request.url,
          method: request.method,
          headers: { ...request.headers, 'x-forwarded-proto': 'https' },
        },
        (incoming) => {
          const headers = { ...incoming.headers }
          if (headers.location) {
            const destination = new URL(headers.location, origin)
            headers.location = origin + destination.pathname + destination.search
          }
          response.writeHead(incoming.statusCode, headers)
          incoming.pipe(response)
        },
      )
      upstream.on('error', () => {
        response.writeHead(502)
        response.end()
      })
      request.pipe(upstream)
    })
    await new Promise((resolve) => tlsServer.listen(webPort, '127.0.0.1', resolve))
    const routerFile = path.join(scratch, 'server.php')
    await writeFile(
      routerFile,
      `<?php $public=__DIR__.'/domains/brief.web86.site'; $uri=urldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/'); if($uri!=='/' && is_file($public.$uri)) { return false; } require $public.'/index.php';`,
    )
    backend = spawn(phpBin, ['-S', `127.0.0.1:${backendPort}`, '-t', publicRoot, routerFile], {
      cwd: app,
      env,
      stdio: 'ignore',
    })
  } else {
    backend = spawn(
      phpBin,
      ['artisan', 'serve', '--host=127.0.0.1', `--port=${backendPort}`, '--no-reload'],
      { cwd: app, env, stdio: 'ignore' },
    )
    vite = await createServer({
      root,
      mode: 'test',
      define: {
        'import.meta.env.VITE_DATA_SOURCE': JSON.stringify('api'),
        'import.meta.env.VITE_API_BASE_URL': JSON.stringify(''),
      },
      server: {
        https: tls,
        hmr: false,
        host: '127.0.0.1',
        port: webPort,
        strictPort: true,
        proxy: {
          '/api': `http://127.0.0.1:${backendPort}`,
          '/auth': {
            changeOrigin: true,
            target: `http://127.0.0.1:${backendPort}`,
            configure: (proxy) =>
              proxy.on('proxyRes', (response) => {
                if (response.headers.location) {
                  const destination = new URL(response.headers.location, origin)
                  response.headers.location = origin + destination.pathname + destination.search
                }
              }),
          },
        },
      },
    })
    await vite.listen()
  }
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(`http://127.0.0.1:${backendPort}/api/health`)).ok) break
    } catch {}
    await new Promise((r) => setTimeout(r, 100))
  }
  if (process.env.UX_SELECT_WEBKIT === '1') {
    php([
      '-r',
      String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'brief-platform-plans-')) exit(90);
$user = App\Models\User::where('email', $argv[1])->firstOrFail();
$workspace = $user->workspaceMembers()->firstOrFail()->workspace;
$workspace->update(['branding' => ['visual' => ['primaryColor' => '#0f766e']]]);
`,
      fixture.admin,
    ])
    const webkitBrowser = process.env.UX_WEBKIT_ENDPOINT
      ? await webkit.connect(process.env.UX_WEBKIT_ENDPOINT, { exposeNetwork: '<loopback>' })
      : await webkit.launch({ headless: true })
    try {
      await writeFile(env.QA_STATE_FILE, JSON.stringify({ registrationEnabled: true }))
      for (const [device, options] of [
        ['desktop', { viewport: { width: 1440, height: 900 } }],
        ['ipad', devices['iPad (gen 11)']],
      ]) {
        const context = await webkitBrowser.newContext({
          ...options,
          ignoreHTTPSErrors: true,
          colorScheme: 'dark',
        })
        await context.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
        await login(context, fixture)
        const page = await context.newPage()
        page.on('pageerror', (error) => errors.push(String(error)))
        for (const [screen, pathname, marker] of [
          ['projects', '/admin', '.project-list-controls'],
          ['workspaces', '/platform?section=workspaces', '#platform-period'],
          ['inventory', '/platform/plans/inventory', '.inventory-controls select'],
          ['registration', '/platform/plans/registration', '.registration-diagnostics dl'],
          ['register', '/register', '.registration-form'],
          ['security', '/admin/account/security', '.account-security-panel'],
        ]) {
          await page.goto(origin + pathname)
          await page.locator(marker).first().waitFor({ timeout: 15_000 })
          if (screen === 'projects' && (await page.locator('.project-filter-trigger').isVisible()))
            await page.locator('.project-filter-trigger').click()
          if (screen === 'registration') {
            await page.getByText('Operator approval', { exact: true }).waitFor()
            assert.equal(await page.getByText(/Phase 4B\.2 lifecycle/).count(), 0)
          }
          const heights = new Map()
          for (const theme of ['light', 'dark', 'system']) {
            const themeSelect = page.locator('.theme-switcher select:visible').first()
            const openPlatformMenu =
              !(await themeSelect.isVisible()) && pathname.startsWith('/platform')
            if (openPlatformMenu) await page.locator('.platform-mobile-bar button').click()
            await themeSelect.selectOption(theme)
            const resolved = theme === 'light' ? 'light' : 'dark'
            await page.locator('html[data-theme="' + resolved + '"]').waitFor()
            const selects = await page.locator('select:visible').evaluateAll((elements) =>
              elements.map((el) => {
                const css = getComputedStyle(el)
                const rect = el.getBoundingClientRect()
                return {
                  key: el.getAttribute('aria-label') || el.id || el.parentElement.className,
                  brand: css.getPropertyValue('--action').trim(),
                  appearance: css.getPropertyValue('-webkit-appearance'),
                  padding: parseFloat(css.paddingRight),
                  caret: css.backgroundImage,
                  lineHeight: parseFloat(css.lineHeight),
                  height: rect.height,
                  width: rect.width,
                  opacity: Number(css.opacity),
                  decorativePointer: el.parentElement.querySelector('svg')
                    ? getComputedStyle(el.parentElement.querySelector('svg')).pointerEvents
                    : null,
                }
              }),
            )
            assert.ok(selects.length >= 2, device + ' ' + screen)
            if (screen === 'projects') {
              assert.ok(
                selects.some((row) => row.key === 'All statuses' && row.brand === '#0f766e'),
              )
              if (device === 'desktop') assert.ok(selects.some((row) => row.key === 'Workspace'))
            }
            for (const [index, select] of selects.entries()) {
              assert.equal(select.appearance, 'none', JSON.stringify(select))
              if (!select.opacity) {
                assert.equal(select.caret, 'none')
                assert.equal(select.decorativePointer, 'none')
              } else {
                assert.ok(
                  select.padding >= 30 && select.width > select.padding,
                  JSON.stringify(select),
                )
                assert.match(select.caret, /^url\(/)
                assert.ok(Number.isFinite(select.lineHeight) && select.lineHeight < select.height)
              }
              if (heights.has(index))
                assert.equal(select.height, heights.get(index), device + ' ' + screen)
              else heights.set(index, select.height)
            }
            const filename = `webkit-${device}-${screen}-${theme}.png`
            await page.screenshot({ path: path.join(output, filename), timeout: 15_000 })
            captures.push(filename)
            if (openPlatformMenu) await page.locator('.platform-drawer[open] > button').click()
          }
          if (screen === 'projects') {
            await page.locator('.project-filters-extra select').first().selectOption('active')
            await page.waitForURL('**/admin?status=active')
            if (await page.locator('.admin-menu-trigger').isVisible()) {
              await page.locator('.admin-menu-trigger').click()
              const workspace = page.locator('.admin-drawer[open] .sidebar-workspace select')
              await workspace.waitFor()
              assert.equal(
                await workspace.evaluate((el) =>
                  getComputedStyle(el).getPropertyValue('-webkit-appearance'),
                ),
                'none',
              )
              assert.match(
                await workspace.evaluate((el) => getComputedStyle(el).backgroundImage),
                /^url\(/,
              )
            }
          }
        }
        await context.close()
        results.push({
          scenario:
            'WebKit ' + device + ' native select appearance and stable heights in three themes',
          passed: true,
        })
      }
    } finally {
      await webkitBrowser.close()
    }
  }
  browser = await chromium.launch({ headless: true })
  const password = 'Browser-registration-only!2026'
  await writeFile(env.QA_STATE_FILE, JSON.stringify({ registrationEnabled: false }))
  const issuer = await createContext()
  await login(issuer, fixture)
  const csrf = (await (await issuer.request.get(origin + '/api/csrf')).json()).token
  const issued = await issuer.request.post(origin + '/api/platform/registration-canary/invite', {
    headers: {
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrf,
      'Idempotency-Key': 'browser-canary-registration',
    },
    data: { email: 'browser-owner@example.test' },
  })
  assert.equal(issued.status(), 201)
  const invitation = (await issued.json()).data.url
  await issuer.close()
  const ownerContext = await createContext()
  const ownerPage = await ownerContext.newPage()
  ownerPage.on('pageerror', (e) => errors.push(String(e)))
  const unexpectedBusiness = []
  ownerPage.on('request', (request) => {
    const pathname = new URL(request.url()).pathname
    if (
      /^\/api\/admin\//.test(pathname) &&
      !/^\/api\/admin\/(?:account(?:\/|$)|login$|logout$|password\/)/.test(pathname)
    )
      unexpectedBusiness.push(pathname)
  })
  await ownerContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  await ownerPage.goto(origin + '/register')
  await ownerPage.locator('.registration-closed').waitFor()
  await ownerPage.goto(invitation)
  assert.equal(await ownerPage.locator('.social-sign-in button').count(), 0)
  await ownerPage.locator('.registration-form button[type=submit]').click()
  await ownerPage.locator('#registration-email[aria-invalid=true]').waitFor()
  await ownerPage.locator('.registration-form input[type=email]').fill('browser-owner@example.test')
  await ownerPage.locator('.registration-form input[type=password]').nth(0).fill(password)
  await ownerPage.locator('.registration-form input[type=password]').nth(1).fill(password)
  await ownerPage.locator('.registration-form button[type=submit]').click()
  await ownerPage.waitForURL('**/admin/verify-email')
  const retryContext = await createContext()
  const retryPage = await retryContext.newPage()
  await retryPage.goto(invitation)
  await retryPage.locator('.registration-closed').waitFor()
  await retryContext.close()
  await writeFile(env.QA_STATE_FILE, JSON.stringify({ registrationEnabled: true }))
  await ownerPage.getByRole('heading', { name: 'Check your email' }).waitFor()
  assert.equal(
    await ownerPage.locator('.verification-address').textContent(),
    'browser-owner@example.test',
  )
  assert.equal(await ownerPage.locator('.onboarding-page input').count(), 0)
  assert.equal(await ownerPage.getByRole('button', { name: 'Resend email' }).isDisabled(), true)
  await capture(ownerPage, 'en-email-pending.png')
  const mail = JSON.parse(await readFile(env.QA_MAIL_FILE, 'utf8'))
  assert.equal(mail.email, 'browser-owner@example.test')
  const wrongContext = await createContext()
  await login(wrongContext, fixture)
  const wrongPage = await wrongContext.newPage()
  await wrongPage.goto(mail.url)
  await wrongPage.getByRole('heading', { name: 'This link could not be confirmed' }).waitFor()
  await wrongPage.getByRole('button', { name: 'Use another account' }).click()
  await wrongPage.getByRole('heading', { name: 'Sign in to confirm your email' }).waitFor()
  assert.equal(new URL(wrongPage.url()).hash, new URL(mail.url).hash)
  await wrongContext.close()

  // Only the fragment changes, on the same SPA route.
  await ownerPage.goto(mail.url)
  await ownerPage.getByRole('heading', { name: 'Sign in to confirm your email' }).waitFor()
  assert.equal(await ownerPage.getByRole('button', { name: 'Resend email' }).count(), 0)
  await ownerPage.reload()
  await ownerPage.getByRole('heading', { name: 'Sign in to confirm your email' }).waitFor()
  await ownerPage.locator('.onboarding-form input[type=email]').fill(mail.email)
  await ownerPage.locator('.onboarding-form input[type=password]').fill(password)
  await ownerPage.getByRole('button', { name: 'Sign in', exact: true }).click()
  await ownerPage.waitForURL('**/admin/security')
  assert.equal(new URL(ownerPage.url()).hash, '')
  assert.equal(
    await ownerPage.locator('.passkeys-section, .social-connections, .recovery-section').count(),
    0,
  )
  const replayPage = await ownerContext.newPage()
  await replayPage.goto(mail.url)
  await replayPage.getByRole('heading', { name: 'Email confirmed', exact: true }).waitFor()
  assert.equal(new URL(replayPage.url()).hash, '')
  assert.equal(await replayPage.locator('.onboarding-page form').count(), 0)
  await replayPage.getByRole('button', { name: 'Continue', exact: true }).click()
  await replayPage.waitForURL('**/admin/security')
  await replayPage.close()

  const tabContext = await createContext()
  await tabContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  const tabEmail = 'tab-owner@example.test'
  assert.equal(
    (
      await call(tabContext, '/api/onboarding/register', 'POST', {
        email: tabEmail,
        password,
        password_confirmation: password,
      })
    ).status(),
    202,
  )
  const tabMail = JSON.parse(await readFile(env.QA_MAIL_FILE, 'utf8'))
  assert.equal(tabMail.email, tabEmail)
  assert.equal(
    (await call(tabContext, '/api/admin/login', 'POST', { email: tabEmail, password })).status(),
    200,
  )
  const waitingPage = await tabContext.newPage()
  await waitingPage.goto(origin + '/admin/verify-email#token=' + 'a'.repeat(64))
  await waitingPage.getByRole('heading', { name: 'This link could not be confirmed' }).waitFor()
  await waitingPage.getByRole('button', { name: 'Request a new link' }).click()
  await waitingPage.getByRole('heading', { name: 'Check your email' }).waitFor()
  assert.equal(await waitingPage.getByRole('button', { name: 'Resend email' }).count(), 1)
  const tabPage = await tabContext.newPage()
  await tabPage.goto(tabMail.url)
  await tabPage.waitForURL('**/admin/security')
  assert.equal(await tabPage.locator('.onboarding-form').count(), 0)
  await tabContext.close()
  results.push({
    scenario:
      'verification fragment, reload, wrong session, replay and automatic confirmation in another tab',
    passed: true,
  })
  await capture(ownerPage, 'en-authenticator-step.png')
  await ownerPage.getByRole('button', { name: 'Set up authenticator', exact: true }).click()
  const uri = await ownerPage.locator('#totp-uri').inputValue()
  const code = php(['tests/browser-totp.php', new URL(uri).searchParams.get('secret')]).trim()
  await ownerPage.locator('#totp-confirm').fill(code)
  await ownerPage
    .locator('form')
    .filter({ has: ownerPage.locator('#totp-confirm') })
    .locator('button[type=submit]')
    .click()
  await ownerPage.locator('.security-codes code').first().waitFor()
  const recoveryCodes = await ownerPage.locator('.security-codes code').allTextContents()
  const recoveryCode = recoveryCodes[0]
  assert.equal(
    await ownerPage.getByRole('button', { name: 'Replace authenticator', exact: true }).count(),
    0,
  )
  assert.equal(await ownerPage.locator('.passkeys-section, .social-connections').count(), 0)
  await capture(ownerPage, 'en-recovery-step.png')
  await ownerPage.reload()
  await ownerPage.locator('.recovery-section').waitFor()
  assert.equal(await ownerPage.locator('.authentication-section').count(), 0)
  assert.equal(await ownerPage.locator('.security-codes code').count(), 0)
  await ownerPage.getByRole('button', { name: 'Show recovery codes', exact: true }).click()
  await ownerPage.locator('.security-codes code').first().waitFor()
  assert.deepEqual(await ownerPage.locator('.security-codes code').allTextContents(), recoveryCodes)

  await ownerPage
    .getByRole('button', { name: 'I have saved my recovery codes', exact: true })
    .click()
  await ownerPage.waitForURL('**/admin/onboarding')
  assert.equal((await call(ownerContext, '/api/admin/projects')).status(), 403)
  assert.equal(
    (await (await call(ownerContext, '/api/onboarding/state')).json()).state,
    'ready_for_trial_activation',
  )
  await ownerPage.locator('.readiness-checks').waitFor()
  assert.equal(
    await ownerPage
      .locator('.account-security-panel, .social-connections, .app-toast.toast-success')
      .count(),
    0,
  )
  const mfaReplay = await createContext()
  await mfaReplay.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  const mfaReplayPage = await mfaReplay.newPage()
  await mfaReplayPage.goto(mail.url)
  await mfaReplayPage.locator('.onboarding-form input[type=email]').fill(mail.email)
  await mfaReplayPage.locator('.onboarding-form input[type=password]').fill(password)
  await mfaReplayPage.getByRole('button', { name: 'Sign in', exact: true }).click()
  await mfaReplayPage.locator('#security-code').waitFor()
  await mfaReplayPage.reload()
  await mfaReplayPage.getByRole('button', { name: 'Use a recovery code' }).click()
  await mfaReplayPage.locator('#security-code').fill(recoveryCodes[3])
  await mfaReplayPage.getByRole('button', { name: 'Confirm identity' }).click()
  await mfaReplayPage.getByRole('heading', { name: 'Email confirmed', exact: true }).waitFor()
  assert.equal(await mfaReplayPage.locator('.onboarding-page form').count(), 0)
  await mfaReplay.close()
  await capture(ownerPage, 'en-completion.png')
  await ownerPage.reload()
  await ownerPage.locator('.readiness-checks').waitFor()
  assert.equal(
    await ownerPage.locator('.account-security-panel, .app-toast.toast-success').count(),
    0,
  )
  await ownerPage.getByRole('link', { name: 'Account security', exact: true }).click()
  await ownerPage.waitForURL('**/admin/account/security')
  await ownerPage.locator('.social-connections').waitFor()
  assert.equal(await ownerPage.locator('.social-connection-row').count(), 3)
  assert.equal(await ownerPage.locator('.admin-shell').count(), 0)
  await capture(ownerPage, 'en-ready-connections.png')
  results.push({
    scenario:
      'email signup → owned proof → real MFA enrollment → pending activation; no workspace access',
    passed: true,
  })
  php(['artisan', 'cache:clear', '--no-interaction'])
  let cancelLink = true
  await ownerContext.route('https://github.com/login/oauth/authorize**', async (route) => {
    const authorize = new URL(route.request().url())
    const callback = new URL(origin + '/auth/github/callback')
    callback.searchParams.set('state', authorize.searchParams.get('state'))
    callback.searchParams.set(
      cancelLink ? 'error' : 'code',
      cancelLink ? 'access_denied' : 'synthetic-link-code',
    )
    await route.fulfill({ status: 302, headers: { location: callback.href }, body: '' })
  })
  await ownerPage
    .locator('.social-connection-row')
    .filter({ hasText: 'GitHub' })
    .getByRole('button')
    .click()
  await ownerPage.getByRole('dialog').waitFor()
  await ownerPage
    .getByRole('dialog')
    .getByRole('button', { name: 'Use a recovery code', exact: true })
    .click()
  await ownerPage.getByRole('dialog').locator('#security-code').fill(recoveryCode)
  await ownerPage
    .getByRole('dialog')
    .getByRole('button', { name: 'Confirm identity', exact: true })
    .click()
  await ownerPage.waitForURL('**/admin/account/security?authError=*')
  await ownerPage.locator('.social-connections [role=alert]').waitFor()
  cancelLink = false
  await ownerPage
    .locator('.social-connection-row')
    .filter({ hasText: 'GitHub' })
    .getByRole('button')
    .click()
  await ownerPage.waitForURL('**/admin/account/security?connected=github')
  await ownerPage
    .locator('.social-connection-row')
    .filter({ hasText: 'GitHub' })
    .getByRole('button', { name: 'Disconnect', exact: true })
    .waitFor()
  await capture(ownerPage, 'en-ready-linked-github.png')
  results.push({
    scenario: 'fresh explicit provider link cancellation and success return to connected methods',
    passed: true,
  })
  php(['artisan', 'cache:clear', '--no-interaction'])
  assert.equal(
    (
      await call(ownerContext, '/api/security/confirm', 'POST', { recovery_code: recoveryCodes[1] })
    ).status(),
    200,
  )
  await ownerPage.reload()
  const cdp = await ownerContext.newCDPSession(ownerPage)
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
  await ownerPage
    .locator('.passkeys-section')
    .getByRole('button', { name: 'Add Passkey', exact: true })
    .click()
  await ownerPage.locator('#passkey-name').fill('Synthetic UX key')
  await ownerPage
    .locator('.passkeys-section form')
    .filter({ has: ownerPage.locator('#passkey-name') })
    .locator('button[type=submit]')
    .click()
  await ownerPage
    .locator('.security-passkeys')
    .getByText('Synthetic UX key', { exact: true })
    .waitFor()
  await ownerPage.getByRole('link', { name: 'Account status', exact: true }).click()
  await ownerPage.locator('.readiness-checks').waitFor()
  assert.equal(
    await ownerPage.locator('.account-security-panel, .app-toast.toast-success').count(),
    0,
  )
  results.push({
    scenario: 'optional real virtual Passkey lives in settings and never changes completion',
    passed: true,
  })
  await call(ownerContext, '/api/admin/logout', 'POST')
  php(['artisan', 'cache:clear', '--no-interaction'])
  await ownerPage.goto(origin + '/admin/login')
  await ownerPage.locator('#admin-email').fill('browser-owner@example.test')
  await ownerPage.locator('#admin-password').fill(password)
  await ownerPage.locator('form button[type=submit]').click()
  await ownerPage.waitForURL('**/admin/security')
  await ownerPage.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await ownerPage.locator('#security-code').fill(recoveryCodes[2])
  await ownerPage
    .locator('form')
    .filter({ has: ownerPage.locator('#security-code') })
    .locator('button[type=submit]')
    .click()
  await ownerPage.waitForURL('**/admin/onboarding')
  await ownerPage.locator('.readiness-checks').waitFor()
  assert.equal(await ownerPage.locator('.app-toast.toast-success').count(), 0)
  results.push({
    scenario: 'returning owner after logout/password/MFA goes directly to account status',
    passed: true,
  })
  assert.deepEqual(unexpectedBusiness, [])
  await ownerContext.close()
  php(['artisan', 'cache:clear', '--no-interaction'])
  const recoveryOutput = php(
    ['artisan', 'account:recover-security', 'browser-owner@example.test'],
    'browser-owner@example.test\n',
  )
  const recoveryToken = /\b[a-f0-9]{64}\b/.exec(recoveryOutput)?.[0]
  assert.ok(recoveryToken)
  const recoveredContext = await createContext()
  await recoveredContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  const recoveredPage = await recoveredContext.newPage()
  recoveredPage.on('pageerror', (e) => errors.push(String(e)))
  await recoveredPage.goto(origin + '/admin/login')
  await recoveredPage.locator('#admin-email').fill('browser-owner@example.test')
  await recoveredPage.locator('#admin-password').fill(password)
  await recoveredPage.locator('details summary').click()
  await recoveredPage.locator('#operator-token').fill(recoveryToken)
  await recoveredPage.locator('form button[type=submit]').click()
  await recoveredPage.waitForURL('**/admin/security')
  await recoveredPage.getByRole('button', { name: 'Set up authenticator', exact: true }).click()
  const recoveredUri = await recoveredPage.locator('#totp-uri').inputValue()
  await recoveredPage
    .locator('#totp-confirm')
    .fill(php(['tests/browser-totp.php', new URL(recoveredUri).searchParams.get('secret')]).trim())
  await recoveredPage
    .locator('form')
    .filter({ has: recoveredPage.locator('#totp-confirm') })
    .locator('button[type=submit]')
    .click()
  await recoveredPage
    .getByRole('button', { name: 'I have saved my recovery codes', exact: true })
    .click()
  await recoveredPage.waitForURL('**/admin/onboarding')
  await recoveredPage.locator('.readiness-checks').waitFor()
  assert.equal((await call(recoveredContext, '/api/admin/projects')).status(), 403)
  assert.equal(await recoveredPage.locator('.app-toast.toast-success').count(), 0)
  await capture(recoveredPage, 'security-recovery-completion.png')
  await recoveredContext.close()
  results.push({
    scenario:
      'operator security recovery preserves pending activation and returns through required steps',
    passed: true,
  })

  const socialContext = await createContext()
  await socialContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  let cancel = true,
    external = 0
  await socialContext.route('https://github.com/login/oauth/authorize**', async (route) => {
    external++
    const target = new URL(route.request().url())
    assert.equal(target.searchParams.get('code_challenge_method'), 'S256')
    assert.ok(target.searchParams.get('code_challenge'))
    assert.equal(target.searchParams.get('scope'), 'user:email')
    const callback = new URL(origin + '/auth/github/callback')
    callback.searchParams.set('state', target.searchParams.get('state'))
    callback.searchParams.set(
      cancel ? 'error' : 'code',
      cancel ? 'access_denied' : 'synthetic-browser-code',
    )
    await route.fulfill({ status: 302, headers: { location: callback.href }, body: '' })
  })
  const socialPage = await socialContext.newPage()
  socialPage.on('pageerror', (e) => errors.push(String(e)))
  await socialPage.goto(origin + '/admin/login')
  await socialPage.getByRole('button', { name: 'GitHub', exact: true }).click()
  await socialPage.waitForURL('**/admin/login?authError=*')
  await socialPage.getByRole('alert').waitFor()
  cancel = false
  await socialPage.getByRole('button', { name: 'GitHub', exact: true }).click()
  await socialPage.waitForURL('**/admin/security')
  assert.equal(
    (await (await call(socialContext, '/api/onboarding/state')).json()).emailVerified,
    true,
  )
  assert.equal(external, 2)
  results.push({
    scenario: 'mock GitHub PKCE, cancellation, retry, verified owner primary login',
    passed: true,
  })
  await socialContext.close()

  for (const language of ['en', 'ru', 'tr', 'zh-CN'])
    for (const theme of ['light', 'dark', 'system']) {
      php(['artisan', 'cache:clear', '--no-interaction'])
      const context = await createContext({
        viewport:
          theme === 'light'
            ? { width: 1440, height: 1000 }
            : { width: theme === 'dark' ? 375 : 390, height: 844 },
        colorScheme: theme === 'light' ? 'light' : 'dark',
      })
      await context.addInitScript(
        ({ language, theme }) => {
          localStorage.setItem('projectbrief.locale', language)
          localStorage.setItem('projectbrief.theme', theme)
        },
        { language, theme },
      )
      const page = await context.newPage()
      page.on('pageerror', (e) => errors.push(String(e)))
      for (const pathname of ['/admin/login', '/register', '/admin/verify-email']) {
        await page.goto(origin + pathname)
        await page.locator('h1').waitFor()
        await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
        assert.equal(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
          true,
          language + theme + pathname,
        )
        assert.equal(await page.getByText(/^onboarding\./).count(), 0)
        await capture(page, `${language}-${theme}-${pathname.split('/').at(-1)}.png`)
      }
      await page.goBack()
      await page.waitForURL('**/register')
      await page.goForward()
      await page.waitForURL('**/admin/verify-email')
      const matrixEmail = `matrix-${language.toLowerCase()}-${theme}@example.test`
      php(['tests/browser-ux-owner.php', matrixEmail, language])
      assert.equal(
        (
          await call(context, '/api/admin/login', 'POST', { email: matrixEmail, password })
        ).status(),
        200,
      )
      const unexpected = []
      let personalOnly = true
      page.on('request', (request) => {
        const pathname = new URL(request.url()).pathname
        if (
          personalOnly &&
          /^\/api\/admin\//.test(pathname) &&
          !/^\/api\/admin\/(?:account(?:\/|$)|login$|logout$|password\/)/.test(pathname)
        )
          unexpected.push(pathname)
      })
      await page.goto(origin + '/admin/security')
      await page.locator('.authentication-section').waitFor()
      assert.equal(await page.locator('.passkeys-section, .recovery-section').count(), 0)
      await capture(page, `${language}-${theme}-authenticator.png`)
      await page.locator('.authentication-section > button.button.secondary').click()
      const matrixUri = await page.locator('#totp-uri').inputValue()
      await page
        .locator('#totp-confirm')
        .fill(php(['tests/browser-totp.php', new URL(matrixUri).searchParams.get('secret')]).trim())
      await page
        .locator('form')
        .filter({ has: page.locator('#totp-confirm') })
        .locator('button[type=submit]')
        .click()
      await page.locator('.recovery-section .security-codes').waitFor()
      assert.equal(await page.locator('.authentication-section, .passkeys-section').count(), 0)
      await capture(page, `${language}-${theme}-recovery.png`)
      await page.locator('.recovery-section > button.button.primary').click()
      await page.waitForURL('**/admin/onboarding')
      await page.locator('.readiness-checks').waitFor()
      assert.equal(
        await page.locator('.app-toast.toast-success, .account-security-panel').count(),
        0,
      )
      await capture(page, `${language}-${theme}-completion.png`)
      await page.locator('a[href="/admin/account/security"]').click()
      await page.locator('.social-connections').waitFor()
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
        language + theme + ' security',
      )
      await capture(page, `${language}-${theme}-security.png`)
      if (language === 'en' && theme === 'light') {
        await page.setViewportSize({ width: 768, height: 1024 })
        await page.goto(origin + '/admin/onboarding')
        await page.locator('.readiness-checks').waitFor()
        await capture(page, 'en-tablet-completion.png')
        await page.locator('a[href="/admin/account/security"]').click()
        await page.locator('.social-connections').waitFor()
        assert.equal(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
          true,
        )
        await capture(page, 'en-tablet-security.png')
        await page.setViewportSize({ width: 1440, height: 1000 })
      }

      assert.deepEqual(unexpected, [])
      personalOnly = false
      await call(context, '/api/admin/logout', 'POST')
      php(['artisan', 'cache:clear', '--no-interaction'])
      await login(context, fixture)
      await page.goto(origin + '/platform/plans/registration')
      await page.locator('.registration-diagnostics').waitFor()
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      )
      await capture(page, `${language}-${theme}-diagnostics.png`)
      results.push({
        language,
        theme,
        width: theme === 'light' ? 1440 : theme === 'dark' ? 375 : 390,
        publicScreens: 3,
        mandatorySecuritySteps: 2,
        serverCompletion: true,
        personalSecurity: true,
        diagnostics: true,
        history: true,
      })
      await context.close()
    }
  const legacyContext = await createContext()
  assert.equal(
    (
      await call(legacyContext, '/api/admin/login', 'POST', {
        email: fixture.legacy,
        password: fixture.password,
      })
    ).status(),
    200,
  )
  assert.equal(
    (
      await call(legacyContext, '/api/security/challenge', 'POST', {
        recovery_code: 'test-recovery-code',
      })
    ).status(),
    200,
  )
  assert.equal((await call(legacyContext, '/api/admin/projects')).status(), 200)
  const legacyPage = await legacyContext.newPage()
  await legacyPage.goto(origin + '/admin/account/security')
  await legacyPage.locator('.passkeys-section').waitFor()
  assert.equal(await legacyPage.locator('.security-back').count(), 0)
  await legacyPage.locator('.admin-shell .breadcrumbs').waitFor()
  await legacyPage.locator('.two-factor-section .recovery-section').waitFor()
  await legacyPage.reload()
  await legacyPage.locator('.admin-shell .account-tabs').waitFor()
  await capture(legacyPage, 'compatibility-owner-security.png')
  await legacyContext.close()
  results.push({
    scenario: 'Compatibility owner retains business access and permanent security settings',
    passed: true,
  })
  await writeFile(
    env.QA_STATE_FILE,
    JSON.stringify({ registrationEnabled: true, providers: ['github'] }),
  )
  const closedContext = await createContext({
    viewport: { width: 375, height: 844 },
    colorScheme: 'dark',
  })
  await closedContext.addInitScript(() => {
    localStorage.setItem('projectbrief.locale', 'ru')
    localStorage.setItem('projectbrief.theme', 'dark')
  })
  const closedPage = await closedContext.newPage()
  await closedPage.goto(origin + '/register')
  await closedPage.locator('.registration-form').waitFor()
  await capture(closedPage, 'ru-dark-register-github-only.png')
  await writeFile(env.QA_STATE_FILE, JSON.stringify({ registrationEnabled: false, providers: [] }))
  await closedPage.reload()
  await closedPage.locator('.registration-closed').waitFor()
  assert.equal(await closedPage.locator('.registration-form, .social-sign-in button').count(), 0)
  assert.equal(
    (
      await call(closedContext, '/api/onboarding/register', 'POST', {
        email: 'closed@example.test',
        password,
        password_confirmation: password,
      })
    ).status(),
    403,
  )
  await capture(closedPage, 'ru-registration-closed.png')
  await closedContext.close()
  results.push({
    scenario:
      'server-controlled closed registration and unconfigured providers have no active signup form',
    passed: true,
  })
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'result.json'),
    JSON.stringify(
      {
        package: !!packageRoot,
        results,
        consoleErrors: errors,
        screenshots: captures,
        liveProviderRequests: 0,
      },
      null,
      2,
    ) + '\n',
  )
  console.log(
    JSON.stringify({
      passed: true,
      scenarios: results.length,
      screenshots: captures.length,
      package: !!packageRoot,
    }),
  )
} catch (error) {
  console.error('ORIGINAL FAILURE', error)
  if (browser)
    for (const context of browser.contexts())
      for (const page of context.pages()) {
        try {
          console.log(
            'FAILED PAGE',
            page.url(),
            await page.locator('[role=alert]').allTextContents(),
          )
          await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
        } catch {}
      }
  throw error
} finally {
  await browser?.close()
  await vite?.close()
  if (tlsServer) await new Promise((resolve) => tlsServer.close(resolve))
  backend?.kill('SIGTERM')
  await rm(scratch, { recursive: true, force: true })
}
