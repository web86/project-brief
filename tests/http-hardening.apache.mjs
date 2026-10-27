// Real Apache + mod_php against a disposable production layout. Never targets a live URL.
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createServer } from 'node:net'
import { randomBytes } from 'node:crypto'
import { cp, mkdir, mkdtemp, readFile, writeFile, symlink, rm, stat } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
const root = path.resolve(import.meta.dirname, '..')
const packaged = process.argv[2] && path.resolve(process.argv[2])
const source = packaged ? path.join(packaged, 'private/project-brief-app') : path.join(root, 'api')
const temporary = await mkdtemp(path.join(tmpdir(), 'project-brief-m73-http-'))
const application = path.join(temporary, 'project-brief-app')
const publicRoot = path.join(temporary, 'domains/localhost')
const php = process.env.PHP_BIN || 'php'
const httpd = process.env.APACHE_BIN || '/usr/local/bin/httpd'
const modules = process.env.APACHE_MODULES || '/usr/local/lib/httpd/modules'
const socket = createServer()
await new Promise((resolve) => socket.listen(0, '127.0.0.1', resolve))
const port = socket.address().port
await new Promise((resolve) => socket.close(resolve))
const origin = 'http://127.0.0.1:' + port
const env = {
  ...process.env,
  APP_ENV: 'local',
  APP_DEBUG: 'false',
  APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
  APP_URL: origin,
  FRONTEND_URL: origin,
  PASSKEY_ORIGIN: origin,
  PASSKEY_RP_ID: '127.0.0.1',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(temporary, 'database.sqlite'),
  DB_URL: '',
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'database',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_COOKIE: 'm73_http',
  LOG_CHANNEL: 'daily',
  LOG_LEVEL: 'warning',
  HTTP_BOOTSTRAP_LIMIT_ENABLED: 'false',
  MAIL_MAILER: 'array',
  AI_ENABLED: 'false',
  TELEGRAM_TRANSPORT: 'disabled',
  TELEGRAM_WEBHOOK_TRANSPORT: 'disabled',
}
const results = []
const nativeErrors = process.env.M73_NATIVE_ERRORS === '1'
let server
function cli(args, json = false) {
  const result = spawnSync(php, args, { cwd: application, env, encoding: 'utf8' })
  assert.equal(
    result.status,
    0,
    'Isolated synthetic PHP fixture failed: ' + result.stderr + result.stdout,
  )
  return json ? JSON.parse(result.stdout) : result.stdout
}
const counter = path.join(temporary, 'php-count')
async function entries() {
  return (await stat(counter).catch(() => ({ size: 0 }))).size
}
async function request(url, expected, options = {}) {
  const response = await fetch(origin + url, { redirect: 'manual', ...options })
  // Do not include access-link URLs, cookies, body or bearer in test output.
  assert.equal(response.status, expected, 'Unexpected status for synthetic request')
  return response
}
try {
  await mkdir(application, { recursive: true })
  await mkdir(publicRoot, { recursive: true })
  for (const item of [
    'app',
    'config',
    'lang',
    'routes',
    'database/migrations',
    'resources',
    'artisan',
    'composer.json',
    'composer.lock',
  ]) {
    await cp(path.join(source, item), path.join(application, item), { recursive: true })
  }
  await mkdir(path.join(application, 'bootstrap/cache'), { recursive: true })
  for (const name of ['app.php', 'providers.php'])
    await cp(path.join(source, 'bootstrap', name), path.join(application, 'bootstrap', name))
  await symlink(path.join(source, 'vendor'), path.join(application, 'vendor'), 'dir')
  for (const directory of [
    'storage/app/private',
    'storage/framework/views',
    'storage/framework/sessions',
    'storage/framework/cache/data',
    'storage/logs',
  ])
    await mkdir(path.join(application, directory), { recursive: true })
  const built = packaged ? path.join(packaged, 'public') : path.join(root, 'dist')
  await cp(built, publicRoot, { recursive: true })
  if (!packaged) await cp(path.join(root, 'deployment/public'), publicRoot, { recursive: true })
  await writeFile(env.DB_DATABASE, '')
  cli(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = cli([path.join(root, 'tests/http-hardening.fixture.php'), application], true)
  await writeFile(
    path.join(temporary, 'prepend.php'),
    '<?php file_put_contents(' + JSON.stringify(counter) + ', ".", FILE_APPEND | LOCK_EX);',
  )
  // Existing hostile files must not be served or executed; these are disposable fixtures.
  const forbidden = [
    '.env',
    '.git/config',
    'vendor/private.txt',
    'storage/private.txt',
    'composer.json',
    'probe.php',
    'PROBE.PHTML',
    'probe.phar',
    'probe.php.jpg',
    'probe.php5',
    'probe.cgi',
    'probe.fcgi',
    'probe.pl',
    'probe.py',
    'probe.sh',
    'probe.shtml',
    'nested/index.php',
    'index.php.jpg',
    'backup.sql',
    'snapshot.bak',
    'archive.zip',
    'assets/.hidden/config.json',
    'INDEX.PHP',
  ]
  for (const name of forbidden) {
    // On case-insensitive macOS this name aliases the real front controller.
    if (name === 'INDEX.PHP' && (await stat(path.join(publicRoot, name)).catch(() => null)))
      continue
    await mkdir(path.dirname(path.join(publicRoot, name)), { recursive: true })
    await writeFile(path.join(publicRoot, name), 'SYNTHETIC_PUBLIC_TRAP')
  }
  await mkdir(path.join(publicRoot, '.well-known/acme-challenge'), { recursive: true })
  await writeFile(path.join(publicRoot, '.well-known/acme-challenge/canary'), 'acme-canary')
  const config = [
    'ServerRoot "' + temporary + '"',
    'ServerName localhost',
    'Listen 127.0.0.1:' + port,
    'PidFile "' + path.join(temporary, 'httpd.pid') + '"',
    ...[
      'mpm_prefork',
      'authz_core',
      'unixd',
      'dir',
      'mime',
      'rewrite',
      'headers',
      'log_config',
    ].map((name) => 'LoadModule ' + name + '_module "' + modules + '/mod_' + name + '.so"'),
    'LoadModule php_module "' +
      (process.env.APACHE_PHP_MODULE || path.join(modules, 'libphp.so')) +
      '"',
    'DocumentRoot "' + publicRoot + '"',
    'TypesConfig /usr/local/etc/httpd/mime.types',
    'ErrorLog "' + path.join(temporary, 'error.log') + '"',
    'LogLevel warn',
    // Deliberately inherit dynamic error pages; optional native-error configuration must stop recursion.
    ...(nativeErrors ? ['ErrorDocument 403 /index.php', 'ErrorDocument 404 /index.php'] : []),
    '<Directory "' + publicRoot + '">',
    'AllowOverride All',
    'Require all granted',
    '</Directory>',
    '<FilesMatch "\\.php$">',
    'SetHandler application/x-httpd-php',
    '</FilesMatch>',
    'php_value auto_prepend_file "' + path.join(temporary, 'prepend.php') + '"',
    'StartServers 1',
    'MinSpareServers 1',
    'MaxSpareServers 2',
    'MaxRequestWorkers 4',
  ].join('\n')
  await writeFile(path.join(temporary, 'httpd.conf'), config)
  const args = [
    '-f',
    path.join(temporary, 'httpd.conf'),
    ...(nativeErrors ? ['-D', 'PROJECT_BRIEF_NATIVE_ERRORS'] : []),
  ]
  const check = spawnSync(httpd, [...args, '-t'], { env, encoding: 'utf8' })
  assert.equal(check.status, 0, check.stderr)
  const inventory = spawnSync(httpd, [...args, '-M'], { env, encoding: 'utf8' })
  for (const module of ['rewrite_module', 'headers_module', 'authz_core_module', 'php_module'])
    assert.ok(inventory.stdout.includes(module), 'Missing actual Apache module ' + module)
  server = spawn(httpd, [...args, '-DFOREGROUND'], {
    env,
    detached: true,
    stdio: ['ignore', 'ignore', 'pipe'],
  })
  let stderr = ''
  server.stderr.on('data', (data) => {
    stderr += data
  })
  for (let n = 0; n < 60; n++) {
    if (server.exitCode !== null) throw new Error('Apache exited: ' + stderr)
    try {
      await request('/api/health', 200)
      break
    } catch (error) {
      if (n === 59) throw error
      await new Promise((resolve) => setTimeout(resolve, 100))
    }
  }
  const before = await entries()
  for (const name of forbidden) {
    for (const method of ['GET', 'HEAD']) await request('/' + name, 403, { method })
  }
  for (const url of [
    '/wp-admin/',
    '/WP-JSON/',
    '/phpmyadmin/',
    '/cgi-bin/test',
    '/index.php/extra',
    '/nested/index.php/extra',
    '/%2egit/config',
    '/probe%2ephp',
    '/assets/missing.js',
    '/font-licenses/missing.txt',
  ]) {
    await request(url, url.startsWith('/assets/') || url.startsWith('/font-licenses/') ? 404 : 403)
  }
  assert.equal(await entries(), before, 'Scanner or missing asset invoked PHP')
  const dbRows = Number(
    cli([
      '-r',
      '$db=new PDO("sqlite:".getenv("DB_DATABASE")); echo $db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();',
    ]),
  )
  assert.equal(dbRows, 0, 'Scanner created database sessions')
  results.push({
    name: 'existing files + scanners + encoding + PATH_INFO + missing assets',
    requests: forbidden.length * 2 + 10,
    phpEntriesAdded: 0,
    sessions: 0,
  })
  for (const url of [
    '/',
    '/app',
    '/admin/login',
    '/admin/account/security',
    '/admin/projects/example/brief',
    '/project/example/task/example',
    '/invitation',
    '/access-error',
    '/task/example',
    '/index.html',
  ]) {
    await request(url, 200)
  }
  for (const url of ['/api/unknown', '/sanctum/csrf-cookie', '/access/unknown/extra'])
    await request(url, 404)
  await request('/api/admin/me', 401)
  await request('/api/client/project', 401)
  await request('/api/security/passkey/login/options', 200)
  await request('/api/telegram/webhook', 403, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: '{}',
  })
  await request('/api/v1/projects', 401)
  const authorized = await request('/api/v1/projects', 200, {
    headers: { authorization: 'Bearer ' + fixture.bearer },
  })
  assert.equal(authorized.headers.get('cache-control'), 'no-store, private')
  const csrf = await request('/api/csrf', 200)
  for (const name of [
    'x-content-type-options',
    'referrer-policy',
    'x-frame-options',
    'cache-control',
  ])
    assert.equal(
      csrf.headers.get(name).includes(','),
      name === 'cache-control',
      'Duplicate security header',
    )
  const access = await request('/access/' + fixture.clientToken, 302)
  assert.ok(!access.headers.get('location').includes(fixture.clientToken))
  const cookie = access.headers
    .getSetCookie()
    .map((c) => c.split(';')[0])
    .join('; ')
  const client = await request('/api/client/project', 200, { headers: { cookie } })
  assert.equal((await client.json()).data.id, fixture.project)
  for (const url of ['/sw.js', '/manifest.webmanifest']) {
    const response = await request(url, 200)
    assert.equal(response.headers.get('cache-control'), 'no-cache')
  }
  const acme = await request('/.well-known/acme-challenge/canary', 200)
  assert.equal(await acme.text(), 'acme-canary')
  const files = await (await import('node:fs/promises')).readdir(path.join(publicRoot, 'assets'))
  const asset = files.find((name) => /-[A-Za-z0-9_-]{8,}\.(js|css|ttf)$/.test(name))
  assert.ok(asset)
  assert.equal(
    (await request('/assets/' + asset, 200)).headers.get('cache-control'),
    'public, max-age=31536000, immutable',
  )
  cli(['artisan', 'down', '--no-interaction'])
  for (const url of ['/', '/index.html', '/admin/login']) await request(url, 503)
  cli(['artisan', 'up', '--no-interaction'])
  if (process.env.M73_BROWSER === '1') {
    const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
    const browser = await chromium.launch({ headless: true })
    try {
      for (const locale of ['ru', 'en', 'tr', 'zh-CN']) {
        const context = await browser.newContext({
          viewport: { width: 390, height: 844 },
          serviceWorkers: 'allow',
          acceptDownloads: true,
        })
        await context.route('**/*', (route) =>
          new URL(route.request().url()).origin === origin ? route.continue() : route.abort(),
        )
        await context.addInitScript(
          (language) => localStorage.setItem('projectbrief.locale', language),
          locale,
        )
        const page = await context.newPage()
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        await page.goto(origin + '/access/' + fixture.clientToken)
        await page.waitForFunction(() => document.body.textContent.includes('HTTP fixture'))
        const state = await page.evaluate(async () => {
          await navigator.serviceWorker.register('/sw.js', { scope: '/' })
          const registration = await navigator.serviceWorker.ready
          return {
            active: Boolean(registration.active),
            cacheKeys: await caches.keys(),
            fits: document.documentElement.scrollWidth <= innerWidth,
            manifest: Boolean(document.querySelector('link[rel="manifest"]')),
          }
        })
        assert.deepEqual(state, { active: true, cacheKeys: [], fits: true, manifest: true })
        assert.deepEqual(errors, [])
        if (process.env.M73_EXPORTS === '1') {
          await page.goto(origin + '/project/' + fixture.project + '/export')
          await page.locator('.export-form').waitFor()
          await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
          await page.locator('#export-language').selectOption(locale)
          for (const format of ['pdf', 'docx']) {
            const download = page.waitForEvent('download', { timeout: 60000 })
            await page
              .locator(
                format === 'pdf'
                  ? '.export-actions button[type=submit]'
                  : '.export-actions button[type=button]',
              )
              .first()
              .click()
            const file = await download
            assert.equal(await file.failure(), null)
            const bytes = await readFile(await file.path())
            assert.ok(bytes.length > 1000)
            assert.ok(
              format === 'pdf'
                ? bytes.subarray(0, 5).toString() === '%PDF-'
                : bytes[0] === 0x50 && bytes[1] === 0x4b,
            )
          }
        }
        await context.close()
      }
      results.push({
        name: 'mobile client link + real SW registration + manifest + no private cache, four locales',
        passed: true,
      })
      if (process.env.M73_EXPORTS === '1')
        results.push({
          name: 'client browser downloads from production assets',
          pdf: 4,
          docx: 4,
          locales: ['ru', 'en', 'tr', 'zh-CN'],
          passed: true,
        })
    } finally {
      await browser.close()
    }
  }
  results.push({
    name: 'Laravel routes, bearer header, client link, SPA maintenance, static/SW/ACME',
    passed: true,
  })
  console.log(
    JSON.stringify({
      passed: true,
      apache: spawnSync(httpd, ['-v'], { encoding: 'utf8' }).stdout.split('\n')[0],
      packaged: Boolean(packaged),
      nativeErrorDefine: nativeErrors,
      bootstrapGuard: false,
      results,
    }),
  )
} finally {
  if (server && server.exitCode === null) {
    server.kill('SIGTERM')
    await new Promise((resolve) => server.once('exit', resolve))
  }
  await rm(temporary, { recursive: true, force: true })
}
