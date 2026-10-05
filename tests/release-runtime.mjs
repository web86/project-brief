import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { mkdtemp, cp, mkdir, writeFile, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { once } from 'node:events'

const packageRoot = path.resolve(process.argv[2])
const php = process.env.PHP_BIN || 'php'
const fixtureRoot = await mkdtemp(path.join(tmpdir(), 'project-brief-runtime-'))
const application = path.join(fixtureRoot, 'project-brief-app')
const publicRoot = path.join(fixtureRoot, 'domains/brief.web86.site')
let server

// Never inherit developer DB/session settings into an isolated release test.
const env = Object.fromEntries(
  Object.entries(process.env).filter(
    ([key]) => !/^(APP_|DB_|DATABASE_|SESSION_|CACHE_|FRONTEND_|DEMO_|LOG_|QUEUE_|MAIL_)/.test(key),
  ),
)

function command(args, input) {
  const result = spawnSync(php, args, { cwd: application, env, encoding: 'utf8', input })
  assert.equal(
    result.status,
    0,
    `PHP command failed: ${args.join(' ')}\n${result.stdout}\n${result.stderr}`,
  )
  return result.stdout
}

try {
  await cp(path.join(packageRoot, 'private/project-brief-app'), application, { recursive: true })
  await mkdir(path.dirname(publicRoot), { recursive: true })
  await cp(path.join(packageRoot, 'public'), publicRoot, { recursive: true })
  const database = path.join(application, 'database/release-test.sqlite')
  await writeFile(database, '')
  const example = await readFile(path.join(application, '.env.production.example'), 'utf8')
  const localEnvironment = example
    .replace('DB_CONNECTION=mysql', 'DB_CONNECTION=sqlite')
    .replace('DB_DATABASE=specchina_breaf_tz', `DB_DATABASE="${database}"`)
  await writeFile(path.join(application, '.env'), localEnvironment, { mode: 0o600 })
  assert.match(command(['bin/check-server']), /PASS: Database connection/)
  assert.match(command(['bin/first-install'], 'n\n'), /Installation complete/)
  const installedEnvironment = await readFile(path.join(application, '.env'), 'utf8')
  assert.match(installedEnvironment, /^APP_KEY=base64:.+/m)

  // Test data lives only in this disposable HOME, never in the upload artifact.
  const fixturePhp = path.join(fixtureRoot, 'fixture.php')
  await writeFile(
    fixturePhp,
    `<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$user = \\App\\Models\\User::create(['name'=>'Release QA', 'email'=>'release@example.test', 'password'=>\\Illuminate\\Support\\Facades\\Hash::make('Release-only-long-password')]);
$project = \\App\\Models\\Project::create(['title'=>'Release QA project', 'sections'=>['Главная'], 'active'=>true]);
$token = str_repeat('a', 64);
$project->accessTokens()->create(['token_hash'=>hash('sha256', $token)]);
echo json_encode(['project'=>$project->uuid]);
`,
  )
  const fixture = JSON.parse(command([fixturePhp, application]))
  const repeated = command(['bin/first-install'], '')
  assert.match(repeated, /Existing APP_KEY preserved/)
  assert.match(repeated, /Nothing to migrate/i)
  assert.match(repeated, /Administrator already exists/)
  assert.equal(await readFile(path.join(application, '.env'), 'utf8'), installedEnvironment)
  assert.match(command(['bin/check-server']), /PASS: Server check complete/)

  const portProbe = net.createServer()
  portProbe.listen(0, '127.0.0.1')
  await once(portProbe, 'listening')
  const port = portProbe.address().port
  await new Promise((resolve) => portProbe.close(resolve))
  const origin = `http://127.0.0.1:${port}`
  const router = path.join(fixtureRoot, 'router.php')
  await writeFile(
    router,
    `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/index.html' && !preg_match('~^/(api|access|sanctum)(/|$)~', $path) && is_file($_SERVER['DOCUMENT_ROOT'].$path)) { return false; }
require $_SERVER['DOCUMENT_ROOT'].'/index.php';
`,
  )
  // Local HTTP only: override Secure for the test process, never the production example.
  command(['artisan', 'config:clear'])
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', publicRoot, router], {
    cwd: application,
    env: { ...env, SESSION_SECURE_COOKIE: 'false' },
    stdio: ['ignore', 'ignore', 'pipe'],
  })
  let serverErrors = ''
  server.stderr.on('data', (chunk) => {
    serverErrors += chunk.toString()
  })
  const cookies = new Map()
  async function request(url, options = {}) {
    const response = await fetch(`${origin}${url}`, {
      ...options,
      redirect: 'manual',
      signal: AbortSignal.timeout(5000),
      headers: {
        Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join('; '),
        ...options.headers,
      },
    })
    for (const cookie of response.headers.getSetCookie()) {
      const [key, ...value] = cookie.split(';')[0].split('=')
      cookies.set(key, value.join('='))
    }
    return response
  }
  const deadline = Date.now() + 10000
  for (;;) {
    try {
      const health = await request('/api/health')
      assert.equal(health.status, 200)
      assert.deepEqual(await health.json(), { ok: true })
      assert.equal(health.headers.getSetCookie().length, 0)
      break
    } catch (error) {
      if (Date.now() >= deadline || server.exitCode !== null) throw error
      await new Promise((resolve) => setTimeout(resolve, 100))
    }
  }
  const html = await readFile(path.join(publicRoot, 'index.html'), 'utf8')
  for (const route of [
    '/',
    '/admin',
    '/admin/login',
    '/admin/projects/example/brief',
    '/project/example/task/example',
    '/index.html',
  ]) {
    const response = await request(route)
    assert.equal(response.status, 200, route)
    assert.equal(await response.text(), html, route)
  }
  const asset = html.match(/src="(\/assets\/[^" ]+\.js)"/)[1]
  assert.equal((await request(asset)).status, 200)
  for (const route of ['/api/unknown', '/access/unknown/extra', '/sanctum/csrf-cookie']) {
    const response = await request(route, { headers: { Accept: 'application/json' } })
    assert.equal(response.status, 404, route)
    assert.doesNotMatch(await response.text(), /id="app"/)
  }
  const guest = await request('/api/admin/me', { headers: { Accept: 'application/json' } })
  assert.equal(guest.status, 401)
  const loginPayload = JSON.stringify({
    email: 'release@example.test',
    password: 'Release-only-long-password',
  })
  const noCsrf = await request('/api/admin/login', {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: loginPayload,
  })
  assert.equal(noCsrf.status, 419)
  const csrf = await (await request('/api/csrf')).json()
  const login = await request('/api/admin/login', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrf.token,
    },
    body: loginPayload,
  })
  assert.equal(login.status, 200)
  assert.equal((await login.json()).user.role, 'admin')
  assert.equal((await request('/api/admin/me')).status, 200)
  const entered = await request(`/access/${'a'.repeat(64)}`)
  assert.equal(entered.status, 302)
  assert.equal(
    entered.headers.get('location'),
    `https://brief.web86.site/project/${fixture.project}`,
  )
  const clientProject = await request(`/api/client/project/${fixture.project}`)
  assert.equal(clientProject.status, 200)
  assert.equal((await clientProject.json()).data.title, 'Release QA project')
  assert.equal(
    (await request('/api/admin/me', { headers: { Accept: 'application/json' } })).status,
    401,
  )
  const failedLink = await request(`/access/${'b'.repeat(64)}`)
  assert.equal(failedLink.status, 302)
  assert.match(failedLink.headers.get('location'), /access-error/)
  command(['artisan', 'down'])
  assert.equal((await request('/admin/login')).status, 503)
  command(['artisan', 'up'])
  assert.equal((await request('/admin/login')).status, 200)
  assert.doesNotMatch(serverErrors, /PHP (Fatal error|Warning)|Uncaught/)
  console.log(
    'PASS: Packaged runtime, split HOME layout, CLI checks, repeated install/key preservation, migrations, optimized routes, SPA reload, assets, CSRF, admin login, client access and maintenance mode.',
  )
} finally {
  if (server && server.exitCode === null) {
    server.kill('SIGTERM')
    await once(server, 'exit')
  }
  await rm(fixtureRoot, { recursive: true, force: true })
}
