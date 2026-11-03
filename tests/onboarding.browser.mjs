import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, readFile, cp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-platform-plans-'))
const packageRoot = process.env.UX_PACKAGE_ROOT
const phpBin = process.env.PHP_BIN || 'php'
const publicRoot = path.join(scratch, 'domains/brief.web86.site')
const app = path.join(scratch, packageRoot ? 'project-brief-app' : 'app'),
  output =
    process.env.UX_BROWSER_OUTPUT_DIRECTORY ||
    path.join(root, 'artifacts/milestone-10-0-phase-4b1/browser')
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
  origin = `http://127.0.0.1:${packageRoot ? backendPort : webPort}`
const env = {
  ...process.env,
  APP_ENV: 'testing',
  REGISTRATION_ENABLED: 'true',
  ONBOARDING_SUBJECT_RETENTION_DAYS: '365',
  GITHUB_LOGIN_CLIENT_ID: 'synthetic-browser-client',
  GITHUB_LOGIN_CLIENT_SECRET: 'synthetic-browser-secret',
  GITHUB_LOGIN_CALLBACK: origin + '/auth/github/callback',
  QA_MAIL_FILE: path.join(scratch, 'mail.json'),
  APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_URL: origin,
  FRONTEND_URL: origin,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(scratch, 'test.sqlite'),
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_COOKIE: 'platform_qa',
  SESSION_DOMAIN: '',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'file',
  MAIL_MAILER: 'array',
  LOG_CHANNEL: 'null',
  BCRYPT_ROUNDS: '4',
  AI_ENABLED: 'false',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function php(args) {
  const result = spawnSync(phpBin, args, { cwd: app, env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
let backend,
  vite,
  browser,
  counter = 0
const errors = [],
  results = []
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

  const fixture = JSON.parse(php(['tests/platform-plans-fixture.php']))
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
  if (packageRoot) {
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
        host: '127.0.0.1',
        port: webPort,
        strictPort: true,
        proxy: {
          '/api': `http://127.0.0.1:${backendPort}`,
          '/auth': {
            target: `http://127.0.0.1:${backendPort}`,
            hostRewrite: `127.0.0.1:${webPort}`,
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
  browser = await chromium.launch({ headless: true })
  const password = 'Browser-registration-only!2026'
  const ownerContext = await browser.newContext()
  const ownerPage = await ownerContext.newPage()
  ownerPage.on('pageerror', (e) => errors.push(String(e)))
  await ownerContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  await ownerPage.goto(origin + '/register')
  await ownerPage.locator('.registration-form input[type=email]').fill('browser-owner@example.test')
  await ownerPage.locator('.registration-form input[type=password]').nth(0).fill(password)
  await ownerPage.locator('.registration-form input[type=password]').nth(1).fill(password)
  await ownerPage.locator('.registration-form button[type=submit]').click()
  await ownerPage.waitForURL('**/admin/verify-email')
  const mail = JSON.parse(await readFile(env.QA_MAIL_FILE, 'utf8'))
  assert.equal(mail.email, 'browser-owner@example.test')
  await ownerPage.goto(mail.url)
  await ownerPage.locator('.onboarding-form input[type=email]').first().fill(mail.email)
  await ownerPage.locator('.onboarding-form input[type=password]').fill(password)
  await ownerPage.locator('.onboarding-form button[type=submit]').first().click()
  await ownerPage.waitForURL('**/admin/security')
  assert.equal(new URL(ownerPage.url()).hash, '')
  await ownerPage.getByRole('button', { name: 'Set up authenticator', exact: true }).click()
  const uri = await ownerPage.locator('#totp-uri').inputValue()
  const code = php(['tests/browser-totp.php', new URL(uri).searchParams.get('secret')]).trim()
  await ownerPage.locator('#totp-confirm').fill(code)
  await ownerPage
    .locator('form')
    .filter({ has: ownerPage.locator('#totp-confirm') })
    .locator('button[type=submit]')
    .click()
  await ownerPage
    .getByRole('button', { name: 'I have saved my recovery codes', exact: true })
    .click()
  await ownerPage.waitForURL('**/admin/onboarding')
  assert.equal((await call(ownerContext, '/api/admin/projects')).status(), 403)
  assert.equal(
    (await (await call(ownerContext, '/api/onboarding/state')).json()).state,
    'ready_for_trial_activation',
  )
  await ownerPage.locator('summary').click()
  await ownerPage.locator('.social-connections').waitFor()
  assert.equal(await ownerPage.locator('.social-connection-row').count(), 3)
  await capture(ownerPage, 'en-ready-connections.png')
  results.push({
    scenario:
      'email signup → owned proof → real MFA enrollment → pending activation; no workspace access',
    passed: true,
  })
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
  await ownerPage.waitForURL('**/admin/onboarding?authError=*')
  await ownerPage.locator('.social-connections [role=alert]').waitFor()
  cancelLink = false
  await ownerPage
    .locator('.social-connection-row')
    .filter({ hasText: 'GitHub' })
    .getByRole('button')
    .click()
  await ownerPage.waitForURL('**/admin/onboarding?connected=github')
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
  await ownerContext.close()

  const socialContext = await browser.newContext()
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
      const context = await browser.newContext({
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
        diagnostics: true,
        history: true,
      })
      await context.close()
    }
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'result.json'),
    JSON.stringify(
      { package: !!packageRoot, results, consoleErrors: errors, liveProviderRequests: 0 },
      null,
      2,
    ) + '\n',
  )
  console.log(
    JSON.stringify({
      passed: true,
      scenarios: results.length,
      screenshots: 50,
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
  backend?.kill('SIGTERM')
  await rm(scratch, { recursive: true, force: true })
}
