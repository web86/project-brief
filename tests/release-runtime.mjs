import assert from 'node:assert/strict'
import { randomUUID } from 'node:crypto'
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
    ([key]) =>
      !/^(APP_|DB_|DATABASE_|SESSION_|CACHE_|FRONTEND_|DEMO_|LOG_|QUEUE_|MAIL_|AI_|GROQ_|ZVENO_|TELEGRAM_)/.test(
        key,
      ),
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
  assert.match(example, /^AI_ENABLED=false$/m)
  for (const key of ['GROQ_API_KEY', 'ZVENO_API_KEY', 'AI_BRIDGE_SECRET']) {
    assert.match(example, new RegExp(`^${key}=$`, 'm'))
  }
  const localEnvironment = example
    .replace('DB_CONNECTION=mysql', 'DB_CONNECTION=sqlite')
    .replace('DB_DATABASE=specchina_breaf_tz', `DB_DATABASE="${database}"`)
  await writeFile(path.join(application, '.env'), localEnvironment, { mode: 0o600 })
  assert.match(command(['bin/check-server']), /PASS: Database connection/)
  assert.match(command(['bin/first-install'], 'n\n'), /Installation complete/)
  const installedEnvironment = await readFile(path.join(application, '.env'), 'utf8')
  assert.match(installedEnvironment, /^APP_KEY=base64:.+/m)
  const administrator = command(
    ['bin/first-install'],
    'y\nRelease QA\nrelease@example.test\nRelease-only-long-password\n',
  )
  assert.match(administrator, /Администратор создан/)
  assert.doesNotMatch(administrator, /Release-only-long-password/)

  // Test data lives only in this disposable HOME, never in the upload artifact.
  const fixturePhp = path.join(fixtureRoot, 'fixture.php')
  await writeFile(
    fixturePhp,
    `<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$owner = \\App\\Models\\User::where('email', 'release@example.test')->firstOrFail();
// Enrolled fixture for HTTP package regression tests; signed WebAuthn is covered by HTTPS browser/protocol suites.
$owner->forceFill(['two_factor_secret'=>\\Laravel\\Fortify\\Fortify::currentEncrypter()->encrypt(app(\\PragmaRX\\Google2FA\\Google2FA::class)->generateSecretKey()), 'two_factor_confirmed_at'=>now(), 'recovery_codes_acknowledged_at'=>now(), 'two_factor_recovery_codes'=>\\Laravel\\Fortify\\Fortify::currentEncrypter()->encrypt(json_encode(['Release-recovery-one', 'Release-recovery-two', 'Release-cleanup-three', 'Release-browser-login']))])->save();
$owner->passkeys()->create(['name'=>'Package fixture credential', 'credential_id'=>bin2hex(random_bytes(32)), 'credential'=>[]]);
$owner->forceFill(['pending_notification_email'=>'verified-release@example.test', 'notification_email_token_hash'=>hash('sha256', str_repeat('a', 64)), 'notification_email_expires_at'=>now()->addMinutes(30)])->save();
// Render templates with an in-memory transport; the isolated package has no SMTP credentials.
config(['mail.default'=>'array']);
foreach (\\App\\Services\\SupportedLocales::codes() as $language) {
    $verification = new \\App\\Mail\\VerifyNotificationEmail('https://brief.example.test/admin/settings/notifications#verify='.str_repeat('a', 64), $language);
    if (!str_contains($verification->render(), '#verify=') || !str_contains(view('mail.verify-notification-email-text', ['verificationUrl'=>$verification->verificationUrl, 'language'=>$language])->render(), '#verify=')) {
        throw new \\RuntimeException('Packaged verification email template is missing its link.');
    }
}

$workspace = $owner->workspaceMembers()->active()->firstOrFail()->workspace;
$project = $workspace->projects()->create(['title'=>'Release QA project', 'sections'=>['Главная'], 'status'=>'active']);
$developer = \\App\\Models\\User::create(['name'=>'Package Developer', 'email'=>'package-developer@example.test', 'password'=>'Package-test-only-password', 'role'=>'user']);
$member = $workspace->members()->create(['user_id'=>$developer->id, 'role'=>'admin', 'status'=>'active', 'joined_at'=>now()]);
$project->members()->create(['workspace_id'=>$workspace->id, 'workspace_member_id'=>$member->id]);
$section = $project->briefSections()->create(['name'=>'Главная', 'position'=>1]);
$exportTask = $project->tasks()->create(['project_section_id'=>$section->id, 'position'=>1, 'title'=>'Кириллица: задача', 'description'=>'Описание проекта & результат', 'developer_notes'=>'PRIVATE_RELEASE_NOTE', 'client_approved'=>true, 'client_approved_at'=>'2026-10-18 12:00:00', 'estimate_hours'=>6, 'price'=>18000]);
$exportTask->attachments()->create(['uploaded_by_type'=>'admin', 'original_name'=>'requirements.pdf', 'stored_name'=>'PRIVATE_STORED_NAME', 'disk'=>'local', 'path'=>'PRIVATE_STORAGE_PATH', 'mime_type'=>'application/pdf', 'size'=>1]);
$exportSection = $project->briefSections()->create(['name'=>'Каталог', 'position'=>2]);
$trashTask = $project->tasks()->create(['project_section_id'=>$section->id, 'position'=>2, 'title'=>'Recoverable runtime idea', 'description'=>'Keep all content', 'status'=>'new']);
$trashTask->comments()->create(['author_type'=>'client', 'text'=>'Retained runtime comment']);
file_put_contents(storage_path('app/private/runtime-trash.txt'), 'retained runtime bytes');
$trashFile = $trashTask->attachments()->create(['uploaded_by_type'=>'client', 'original_name'=>'runtime-trash.txt', 'stored_name'=>'runtime-trash.txt', 'disk'=>'local', 'path'=>'runtime-trash.txt', 'mime_type'=>'text/plain', 'size'=>22]);

$project->tasks()->create(['project_section_id'=>$exportSection->id, 'position'=>1, 'title'=>'Готовая задача', 'description'=>'Результат', 'status'=>'done', 'client_approved'=>true, 'estimate_hours'=>0]);
$token = str_repeat('a', 64);
$client = $project->clients()->create(['name'=>'Release client']);
$project->accessTokens()->create(['project_client_id'=>$client->id, 'token_hash'=>hash('sha256', $token)]);
$foreign = $app->make(\\App\\Services\\OwnerProvisioning::class)->create(['name'=>'Foreign QA', 'email'=>'foreign@example.test', 'password'=>'Foreign-test-only-password']);
$foreignWorkspace = $foreign->workspaceMembers()->active()->firstOrFail()->workspace;
$foreignProject = $foreignWorkspace->projects()->create(['title'=>'Foreign QA project']);
$foreignTask = $foreignProject->tasks()->create(['title'=>'Foreign QA task', 'description'=>'Protected foreign task']);
$runtimeImage = imagecreatetruecolor(4, 4); ob_start(); imagepng($runtimeImage); $runtimePng = base64_encode(ob_get_clean());
echo json_encode(['png'=>$runtimePng, 'project'=>$project->uuid, 'workspace'=>$workspace->uuid, 'foreignWorkspace'=>$foreignWorkspace->uuid, 'foreignProject'=>$foreignProject->uuid, 'foreignTask'=>$foreignTask->uuid, 'trashTask'=>$trashTask->uuid, 'trashFile'=>$trashFile->uuid, 'mainSection'=>$section->uuid, 'targetSection'=>$exportSection->uuid, 'exportTask'=>$exportTask->uuid]);
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
  assert.equal((await login.json()).state, 'pending_2fa')
  assert.equal(
    (await request('/api/admin/me', { headers: { Accept: 'application/json' } })).status,
    403,
  )
  const factor = await request('/api/security/challenge', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': (await (await request('/api/csrf')).json()).token,
    },
    body: JSON.stringify({ recovery_code: 'Release-recovery-one' }),
  })
  assert.equal(factor.status, 200)
  assert.equal((await factor.json()).state, 'authenticated')
  const ownerState = await (await request('/api/admin/me')).json()
  assert.equal(ownerState.user.role, 'admin')
  assert.equal(ownerState.user.membershipRole, 'owner')
  assert.equal(ownerState.user.canCreateProjects, true)
  assert.equal(ownerState.workspace.id, fixture.workspace)
  const workspaceCsrf = await (await request('/api/csrf')).json()
  const personal = await (await request('/api/admin/notifications')).json()
  assert.equal(personal.email, 'release@example.test')
  assert.equal(personal.pendingEmail, 'verified-release@example.test')
  assert.equal(personal.preferences.telegram, true)
  assert.equal(personal.notification_email_token_hash, undefined)
  const personalHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': workspaceCsrf.token,
  }
  const pauseChannel = await request('/api/admin/notifications', {
    method: 'PATCH',
    headers: personalHeaders,
    body: JSON.stringify({ preferences: { email: true, push: true, telegram: false } }),
  })
  assert.equal(pauseChannel.status, 200)
  assert.equal((await pauseChannel.json()).preferences.telegram, false)
  const verifyEmail = await request('/api/admin/notifications/email/verify', {
    method: 'POST',
    headers: personalHeaders,
    body: JSON.stringify({ token: 'a'.repeat(64) }),
  })
  assert.equal(verifyEmail.status, 200)
  assert.equal((await verifyEmail.json()).email, 'verified-release@example.test')
  assert.equal(
    (
      await request('/api/admin/notifications/email/verify', {
        method: 'POST',
        headers: personalHeaders,
        body: JSON.stringify({ token: 'a'.repeat(64) }),
      })
    ).status,
    422,
  )
  // Exercise the packaged implementation, database migration and retained file through HTTP.
  const trashRoot = '/api/admin/tasks/' + fixture.trashTask
  const mutation = (url, method, body = {}) =>
    request(url, {
      method,
      headers: personalHeaders,
      body: method === 'DELETE' ? undefined : JSON.stringify(body),
    })
  const discussionRoot = '/api/admin/projects/' + fixture.project + '/discussions'
  const discussionState = await (await request(discussionRoot + '?kind=general')).json()
  assert.deepEqual(Object.keys(discussionState.unreadCounts).sort(), ['general', 'tasks'])
  assert.equal(
    discussionState.unreadCount,
    discussionState.unreadCounts.general + discussionState.unreadCounts.tasks,
  )
  const scopedRead = await mutation(discussionRoot + '/read', 'POST', {
    all: true,
    kind: 'general',
  })
  assert.equal(scopedRead.status, 200)
  const scopedState = await scopedRead.json()
  assert.equal(scopedState.unreadCounts.general, 0)
  assert.equal(scopedState.unreadCounts.tasks, discussionState.unreadCounts.tasks)
  let placed = await mutation(trashRoot + '/place', 'POST', {
    sectionId: fixture.targetSection,
    beforeTaskId: null,
    afterTaskId: null,
  })
  assert.equal(placed.status, 200)
  assert.equal((await placed.json()).data.find((task) => task.id === fixture.trashTask).position, 2)
  placed = await mutation(trashRoot + '/place', 'POST', {
    sectionId: fixture.mainSection,
    beforeTaskId: fixture.exportTask,
  })
  assert.equal(placed.status, 200)
  assert.equal(
    (await placed.json()).data.find((task) => task.id === fixture.trashTask).displayNumber,
    '1.1',
  )
  assert.equal((await mutation('/api/admin/tasks/' + fixture.exportTask, 'DELETE')).status, 403)
  assert.equal((await mutation(trashRoot, 'DELETE')).status, 200)
  assert.equal((await request(trashRoot)).status, 404)
  assert.equal(
    (await request('/api/admin/attachments/' + fixture.trashFile + '/download')).status,
    404,
  )
  const trash = await (await request('/api/admin/projects/' + fixture.project + '/trash')).json()
  assert.equal(trash.data.length, 1)
  assert.equal(trash.data[0].comments[0].text, 'Retained runtime comment')
  assert.equal(trash.data[0].attachments[0].name, 'runtime-trash.txt')
  assert.equal((await mutation(trashRoot + '/restore', 'POST')).status, 200)
  assert.equal(
    (await request('/api/admin/attachments/' + fixture.trashFile + '/download')).status,
    200,
  )
  assert.equal((await mutation(trashRoot + '/restore', 'POST')).status, 409)
  assert.equal((await mutation(trashRoot, 'DELETE')).status, 200)
  assert.equal((await mutation(trashRoot, 'DELETE')).status, 404)
  assert.equal(
    (await request('/api/admin/projects/' + fixture.foreignProject + '/trash')).status,
    404,
  )
  const personalPage = await request('/admin/settings/notifications')
  assert.equal(personalPage.status, 200)
  assert.match(await personalPage.text(), /id="app"/)
  const workspaces = await (await request('/api/admin/workspaces')).json()
  assert.deepEqual(workspaces.data, [{ id: fixture.workspace, name: 'Release QA' }])
  const projects = await (await request('/api/admin/projects')).json()
  assert.deepEqual(
    projects.data.map((project) => project.id),
    [fixture.project],
  )
  assert.equal((await request('/api/admin/projects/' + fixture.foreignProject)).status, 404)
  assert.equal((await request('/api/admin/tasks/' + fixture.foreignTask)).status, 404)
  assert.equal(
    (await request('/api/admin/projects/' + fixture.foreignProject + '/notifications')).status,
    404,
  )
  assert.equal(
    (
      await request('/api/admin/workspaces/' + fixture.foreignWorkspace + '/select', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': workspaceCsrf.token,
        },
        body: '{}',
      })
    ).status,
    404,
  )
  const ownerSession = await (await request('/api/session')).json()
  assert.deepEqual(ownerSession, {
    authenticated: true,
    securityRecommended: false,
    type: 'admin',
    workspace: { id: fixture.workspace, name: 'Release QA' },
  })
  assert.equal((await request('/api/admin/me')).status, 200)
  for (const language of ['ru', 'en', 'tr', 'zh-CN']) {
    const savedLocale = await request('/api/admin/me/locale', {
      method: 'PATCH',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': workspaceCsrf.token,
      },
      body: JSON.stringify({ locale: language }),
    })
    assert.equal(savedLocale.status, 200)
    assert.equal((await savedLocale.json()).preferredLocale, language)
  }

  const activity = await (
    await request('/api/admin/projects/' + fixture.project + '/activity')
  ).json()
  assert.deepEqual(activity, { unreadCount: 0 })
  const readState = await request('/api/admin/projects/' + fixture.project + '/activity/read', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': workspaceCsrf.token,
    },
    body: '{}',
  })
  assert.equal(readState.status, 200)
  assert.deepEqual(await readState.json(), { unreadCount: 0 })
  assert.equal(
    (
      await request('/api/admin/projects/' + fixture.foreignProject + '/activity/read', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': workspaceCsrf.token,
        },
        body: '{}',
      })
    ).status,
    404,
  )
  for (const section of ['clients', 'structure', 'notifications', 'settings', 'export']) {
    assert.equal((await request('/admin/projects/' + fixture.project + '/' + section)).status, 200)
  }
  for (const format of ['pdf', 'docx']) {
    const exported = await request(
      '/api/admin/projects/' + fixture.project + (format === 'pdf' ? '/export-data' : '/export'),
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': workspaceCsrf.token,
        },
        body: JSON.stringify({
          format,
          scope: 'approved',
          locale: 'ru',
          documentTitle: 'План работ',
          pageBreakBySection: true,
          fields: { estimateHours: true, price: true, attachments: true, approvalDate: true },
        }),
      },
    )
    assert.equal(exported.status, 200)
    if (format === 'docx')
      assert.match(
        exported.headers.get('content-disposition'),
        new RegExp('specification-.*\\.' + format),
      )
    const bytes = Buffer.from(await exported.arrayBuffer())
    assert.ok(bytes.length > 1000)
    if (format === 'pdf') {
      const data = JSON.parse(bytes.toString())
      assert.equal(data.schemaVersion, 1)
      assert.equal(data.document.title, 'План работ')
      assert.match(exported.headers.get('cache-control'), /no-store/)
      assert.doesNotMatch(
        bytes.toString(),
        /PRIVATE_STORED_NAME|PRIVATE_STORAGE_PATH|PRIVATE_RELEASE_NOTE/,
      )
    } else {
      assert.equal(
        exported.headers.get('content-type'),
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      )
      assert.equal(bytes.subarray(0, 2).toString(), 'PK')
      const docx = path.join(fixtureRoot, 'runtime-export.docx')
      await writeFile(docx, bytes)
      const xml = command([
        '-r',
        '$zip = new ZipArchive; $zip->open($argv[1]); echo $zip->getFromName("word/document.xml");',
        docx,
      ])
      assert.match(xml, /Кириллица: задача/)
      assert.match(xml, /План работ/)
      assert.match(xml, /Общая оценка: 6 ч/)
      assert.match(xml, /Общая стоимость: 18\u00a0000\u00a0₽/)
      assert.match(xml, /Стоимость указана для 1 из 2 задач/)
      assert.match(xml, /requirements.pdf/)
      assert.match(xml, /18.10.2026/)
      assert.match(xml, /SectionStart/)
      assert.doesNotMatch(xml, /PRIVATE_STORED_NAME|PRIVATE_STORAGE_PATH/)
      assert.doesNotMatch(xml, /PRIVATE_RELEASE_NOTE/)
    }
    assert.equal(
      (
        await request(
          '/api/admin/projects/' +
            fixture.foreignProject +
            (format === 'pdf' ? '/export-data' : '/export'),
          {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': workspaceCsrf.token,
            },
            body: JSON.stringify({ format, scope: 'all', locale: 'ru' }),
          },
        )
      ).status,
      404,
    )
  }
  // Exercise bundled CJK faces from the production package, without host fonts or downloads.
  for (const format of ['pdf', 'docx']) {
    const response = await request(
      '/api/admin/projects/' + fixture.project + (format === 'pdf' ? '/export-data' : '/export'),
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': workspaceCsrf.token,
        },
        body: JSON.stringify({ format, scope: 'approved', locale: 'zh-CN' }),
      },
    )
    assert.equal(response.status, 200)
    const bytes = Buffer.from(await response.arrayBuffer())
    if (format === 'pdf') {
      const data = JSON.parse(bytes.toString())
      assert.equal(data.document.title, '技术需求说明书')
      assert.equal(data.schemaVersion, 1)
    } else {
      const file = path.join(fixtureRoot, 'runtime-chinese.docx')
      await writeFile(file, bytes)
      const metadata = command([
        '-r',
        '$zip = new ZipArchive; $zip->open($argv[1]); echo $zip->getFromName("word/document.xml"); echo $zip->getFromName("word/fontTable.xml"); if (!$zip->locateName("word/fonts/NotoSansSC-Regular.odttf")) exit(2);',
        file,
      ])
      assert.match(metadata, /技术需求说明书/)
      assert.match(metadata, /w:fontKey/)
      assert.match(metadata, /Кириллица: задача/)
    }
  }
  const completedExport = await request('/api/admin/projects/' + fixture.project + '/export-data', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': workspaceCsrf.token,
    },
    body: JSON.stringify({ format: 'pdf', scope: 'done', locale: 'en' }),
  })
  assert.equal(completedExport.status, 200)
  assert.equal((await completedExport.json()).document.totals.taskCount, 1)
  const entered = await request(`/access/${'a'.repeat(64)}`)
  assert.equal(entered.status, 302)
  assert.equal(
    entered.headers.get('location'),
    `https://brief.web86.site/project/${fixture.project}`,
  )
  const clientSession = await (await request('/api/session')).json()
  assert.deepEqual(clientSession, {
    authenticated: true,
    type: 'client',
    project: { id: fixture.project },
  })
  const clientProject = await request(`/api/client/project/${fixture.project}`)
  assert.equal(clientProject.status, 200)
  assert.equal((await clientProject.json()).data.name, 'Release QA project')
  assert.equal(
    (await request('/api/admin/me', { headers: { Accept: 'application/json' } })).status,
    401,
  )
  const clientCsrf = await (await request('/api/csrf')).json()
  assert.equal((await request('/api/client/tasks/' + fixture.trashTask)).status, 404)
  assert.equal(
    (await request('/api/client/attachments/' + fixture.trashFile + '/download')).status,
    404,
  )
  const clientHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': clientCsrf.token,
  }
  const clientDraft = await request('/api/client/tasks', {
    method: 'POST',
    headers: clientHeaders,
    body: JSON.stringify({
      title: 'Client runtime draft',
      description: 'Delete safely',
      location: 'Главная',
      priority: 'normal',
    }),
  })
  assert.equal(clientDraft.status, 201)
  const clientDraftId = (await clientDraft.json()).data.id
  assert.equal(
    (
      await request('/api/client/tasks/' + clientDraftId, {
        method: 'DELETE',
        headers: clientHeaders,
      })
    ).status,
    200,
  )
  assert.equal((await request('/api/client/tasks/' + clientDraftId)).status, 404)

  assert.equal(
    (
      await request('/api/admin/projects/' + fixture.project + '/export-data', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': clientCsrf.token,
        },
        body: JSON.stringify({ format: 'pdf', scope: 'all', locale: 'ru' }),
      })
    ).status,
    401,
  )
  for (const endpoint of [
    '/api/client/project/' + fixture.project + '/discussions',
    '/api/client/notifications',
    '/api/client/telegram',
  ])
    assert.equal((await request(endpoint)).status, 200)
  for (const endpoint of [
    '/api/client/tasks/' + fixture.exportTask + '/comments',
    '/api/client/project/' + fixture.project + '/discussions',
  ]) {
    const submissionId = randomUUID()
    let comment
    for (let attempt = 0; attempt < 2; attempt++) {
      const body = new FormData()
      body.append('text', '')
      body.append('submissionId', submissionId)
      body.append(
        'attachments[]',
        new Blob(['release comment attachment'], { type: 'text/plain' }),
        'runtime-comment.txt',
      )
      body.append(
        'attachments[]',
        new Blob([Buffer.from(fixture.png, 'base64')], { type: 'image/png' }),
        'runtime-comment.png',
      )
      const saved = await request(endpoint, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': clientCsrf.token },
        body,
      })
      assert.ok(saved.ok, 'Packaged multipart comment must save')
      const data = (await saved.json()).data
      const latest = endpoint.endsWith('/comments') ? data.comments.at(-1) : data
      if (comment) assert.equal(latest.id, comment.id, 'Packaged retry must be idempotent')
      comment = latest
      assert.equal(comment.attachments.length, 2)
      assert.equal(comment.text, '')
      if (data.comments)
        assert.equal(data.attachments.length, 1, 'Idea attachment list excludes comment files')
    }
    const [document, image] = comment.attachments
    assert.equal(await (await request(document.downloadUrl)).text(), 'release comment attachment')
    const thumbnail = await request(image.preview)
    assert.equal(thumbnail.status, 200)
    assert.match(thumbnail.headers.get('content-type'), /image\/jpeg/)
    const original = await request(image.originalUrl)
    assert.equal(original.status, 200)
    assert.deepEqual(Buffer.from(await original.arrayBuffer()), Buffer.from(fixture.png, 'base64'))
  }
  for (const format of ['pdf', 'docx']) {
    const exported = await request(
      '/api/client/project/' + fixture.project + (format === 'pdf' ? '/export-data' : '/export'),
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': clientCsrf.token,
        },
        body: JSON.stringify({
          format,
          scope: 'all',
          locale: 'en',
          fields: { price: true, estimateHours: true, developerNotes: true, description: true },
        }),
      },
    )
    assert.equal(exported.status, 200)
    const bytes = Buffer.from(await exported.arrayBuffer())
    if (format === 'pdf') {
      const data = JSON.parse(bytes.toString())
      assert.equal(data.schemaVersion, 1)
      assert.doesNotMatch(
        bytes.toString(),
        /PRIVATE_RELEASE_NOTE|estimateHours|developerNotes|\"price\"/,
      )
    } else {
      const file = path.join(fixtureRoot, 'runtime-client-export.docx')
      await writeFile(file, bytes)
      const xml = command([
        '-r',
        '$zip = new ZipArchive; $zip->open($argv[1]); echo $zip->getFromName("word/document.xml");',
        file,
      ])
      assert.match(xml, /Кириллица: задача/)
      assert.doesNotMatch(xml, /PRIVATE_RELEASE_NOTE|18.000|Total price|Total estimate/)
    }
    assert.equal(
      (
        await request(
          '/api/client/project/' +
            fixture.foreignProject +
            (format === 'pdf' ? '/export-data' : '/export'),
          {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': clientCsrf.token,
            },
            body: JSON.stringify({ format, scope: 'all', locale: 'en' }),
          },
        )
      ).status,
      404,
    )
  }
  const failedLink = await request(`/access/${'b'.repeat(64)}`)
  assert.equal(failedLink.status, 302)
  assert.match(failedLink.headers.get('location'), /access-error/)
  // Check the shipped v1 implementation without browser session/CSRF, using a disposable token.
  const integrationFixture = path.join(fixtureRoot, 'integration-fixture.php')
  await writeFile(
    integrationFixture,
    `<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$user = \\App\\Models\\User::where('email', 'release@example.test')->firstOrFail();
$workspace = $user->workspaceMembers()->active()->firstOrFail()->workspace;
echo json_encode(app(\\App\\Services\\IntegrationTokens::class)->create($workspace->id, ['name'=>'Release API', 'scopes'=>['projects:read','projects:write','tasks:read','tasks:write']]));
`,
  )
  const integration = JSON.parse(command([integrationFixture, application]))
  const integrationHeaders = {
    Authorization: 'Bearer ' + integration.secret,
    'Content-Type': 'application/json',
    'Idempotency-Key': 'release-synthetic-project',
  }
  const callIntegration = (url, options = {}) =>
    fetch(origin + url, { ...options, headers: integrationHeaders })
  const noToken = await fetch(origin + '/api/v1/projects')
  assert.equal(noToken.status, 401)
  const projectPayload = JSON.stringify({
    title: 'Packaged CRM project',
    sourceSystem: 'release',
    externalId: 'deal-1',
  })
  const createdIntegrationProject = await callIntegration('/api/v1/projects', {
    method: 'POST',
    body: projectPayload,
  })
  assert.equal(createdIntegrationProject.status, 201)
  assert.equal(createdIntegrationProject.headers.getSetCookie().length, 0)
  const integrationProject = (await createdIntegrationProject.json()).data
  const replayIntegrationProject = await callIntegration('/api/v1/projects', {
    method: 'POST',
    body: projectPayload,
  })
  assert.equal(replayIntegrationProject.status, 201)
  assert.equal(replayIntegrationProject.headers.get('Idempotency-Replayed'), 'true')
  assert.equal((await replayIntegrationProject.json()).data.id, integrationProject.id)
  assert.equal(
    (
      await callIntegration('/api/v1/projects', {
        method: 'POST',
        body: JSON.stringify({ title: 'Conflict' }),
      })
    ).status,
    409,
  )
  assert.equal((await callIntegration('/api/v1/projects/' + fixture.foreignProject)).status, 404)
  const createdIntegrationTask = await callIntegration(
    '/api/v1/projects/' + integrationProject.id + '/tasks',
    {
      method: 'POST',
      body: JSON.stringify({
        title: 'Packaged idea',
        location: 'New section',
        description: 'Synthetic',
        priority: 'normal',
      }),
    },
  )
  assert.equal(createdIntegrationTask.status, 201)
  const integrationTask = (await createdIntegrationTask.json()).data
  assert.ok(integrationTask.number)
  const readIntegrationTasks = await callIntegration(
    '/api/v1/projects/' + integrationProject.id + '/tasks',
  )
  assert.equal(readIntegrationTasks.status, 200)
  const integrationTasks = await readIntegrationTasks.json()
  assert.equal(integrationTasks.data[0].id, integrationTask.id)
  assert.equal(integrationTasks.data[0].price, undefined)
  assert.equal(integrationTasks.data[0].developerNotes, undefined)
  const idleCron = spawnSync(php, ['artisan', 'integrations:webhooks'], {
    cwd: application,
    env,
    encoding: 'utf8',
  })
  assert.equal(idleCron.status, 0)
  assert.equal(idleCron.stdout, '')
  assert.equal(idleCron.stderr, '')
  assert.match(
    command(['artisan', 'integrations:webhooks', '--verbose']),
    /Processed deliveries: 0/,
  )

  const webhookFixture = path.join(fixtureRoot, 'webhook-filter-fixture.php')
  await writeFile(
    webhookFixture,
    `<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$project = \\App\\Models\\Project::where('uuid', $argv[2])->firstOrFail();
$task = $project->tasks()->firstOrFail();
$secret = 'whsec_'.bin2hex(random_bytes(32));
$endpoint = \\Illuminate\\Support\\Facades\\DB::table('webhook_endpoints')->insertGetId(['uuid'=>(string) \\Illuminate\\Support\\Str::uuid(), 'workspace_id'=>$project->workspace_id, 'name'=>'Packaged selected webhook', 'url'=>'https://example.com/events', 'project_filter'=>'selected', 'events'=>json_encode(['task.client_comment_created','project.client_comment_created','project.updated']), 'enabled'=>true, 'secret'=>\\Illuminate\\Support\\Facades\\Crypt::encryptString($secret), 'secret_version'=>1, 'created_at'=>now(), 'updated_at'=>now()]);
\\Illuminate\\Support\\Facades\\DB::table('webhook_endpoint_projects')->insert(['webhook_endpoint_id'=>$endpoint,'workspace_id'=>$project->workspace_id,'project_id'=>$project->id]);
$comments = [];
foreach ([$task,null] as $target) {
    $comment = \\App\\Models\\Comment::create(['project_id'=>$project->id,'task_id'=>$target?->id,'project_client_id'=>$project->clients()->first()->id,'author_type'=>'client','text'=>'PRIVATE_PACKAGED_COMMENT']);
    app(\\App\\Services\\WebhookEvents::class)->clientComment($comment,$project,$target);
    $path = 'attachments/packaged/'.(string) \\Illuminate\\Support\\Str::uuid().'.txt';
    \\Illuminate\\Support\\Facades\\Storage::disk('local')->put($path,'SYNTHETIC_FILE');
    $file = \\App\\Models\\Attachment::create(['comment_id'=>$comment->id,'task_id'=>$target?->id,'original_name'=>'report.txt','stored_name'=>basename($path),'mime_type'=>'text/plain','size'=>14,'disk'=>'local','path'=>$path,'uploaded_by_type'=>'client']);
    $comments[] = ['id'=>$comment->uuid,'file'=>$file->uuid];
}
$other = \\App\\Models\\Project::where('uuid',$argv[3])->firstOrFail();
app(\\App\\Services\\WebhookEvents::class)->project($other,'project.updated',['title'=>'Excluded']);
$rows = \\Illuminate\\Support\\Facades\\DB::table('webhook_events')->get();
if ($rows->count() !== 2) { throw new RuntimeException('Packaged project filtering failed'); }
foreach ($rows as $row) {
    $body = json_decode($row->body,true);
    if (!isset($body['commentId']) || str_contains($row->body,'PRIVATE_PACKAGED_COMMENT')) { throw new RuntimeException('Packaged comment metadata failed'); }
}
$taskPath = 'attachments/packaged/task.txt';
\\Illuminate\\Support\\Facades\\Storage::disk('local')->put($taskPath,'SYNTHETIC_FILE');
$taskFile = \\App\\Models\\Attachment::create(['task_id'=>$task->id,'original_name'=>'task.txt','stored_name'=>'task.txt','mime_type'=>'text/plain','size'=>14,'disk'=>'local','path'=>$taskPath,'uploaded_by_type'=>'client']);
$readToken = app(\\App\\Services\\IntegrationTokens::class)->create($project->workspace_id,['name'=>'Packaged Read','scopes'=>['comments:read','attachments:read','tasks:read']]);
echo json_encode(['readToken'=>$readToken,'comments'=>$comments,'task'=>$task->uuid,'taskFile'=>$taskFile->uuid,'events'=>$rows->count(),'selection'=>app(\\App\\Services\\WebhookProjectFilter::class)->ids(\\Illuminate\\Support\\Facades\\DB::table('webhook_endpoints')->find($endpoint))]);
`,
  )
  const packagedWebhooks = JSON.parse(
    command([webhookFixture, application, fixture.project, fixture.foreignProject]),
  )
  assert.equal(packagedWebhooks.events, 2)
  assert.deepEqual(packagedWebhooks.selection, [fixture.project])

  assert.equal(
    (await callIntegration('/api/v1/projects/' + fixture.project + '/comments')).status,
    403,
  )
  const readHeaders = { Authorization: 'Bearer ' + packagedWebhooks.readToken.secret }
  for (const comment of packagedWebhooks.comments) {
    const url = origin + '/api/v1/projects/' + fixture.project + '/comments/' + comment.id
    const response = await fetch(url, { headers: readHeaders })
    assert.equal(response.status, 200)
    const data = (await response.json()).data
    assert.equal(data.text, 'PRIVATE_PACKAGED_COMMENT')
    assert.deepEqual(Object.keys(data.attachments[0]).sort(), ['id', 'mimeType', 'name', 'size'])
    assert.equal(data.attachments[0].id, comment.file)
    assert.equal((await fetch(url + '/attachments', { headers: readHeaders })).status, 200)
    const downloaded = await fetch(origin + '/api/v1/attachments/' + comment.file + '/download', {
      headers: readHeaders,
    })
    assert.equal(downloaded.status, 200)
    assert.match(downloaded.headers.get('content-disposition'), /^attachment;/)
    assert.match(downloaded.headers.get('cache-control'), /no-store/)
    assert.equal(await downloaded.text(), 'SYNTHETIC_FILE')
    assert.equal(
      (await fetch(origin + '/api/v1/attachments/' + comment.file + '/download')).status,
      401,
    )
  }
  const taskFiles = await fetch(
    origin +
      '/api/v1/projects/' +
      fixture.project +
      '/tasks/' +
      packagedWebhooks.task +
      '/attachments',
    { headers: readHeaders },
  )
  assert.equal(taskFiles.status, 200)
  const taskFileData = (await taskFiles.json()).data
  assert.ok(taskFileData.some((file) => file.id === packagedWebhooks.taskFile))
  assert.ok(
    !taskFileData.some((file) =>
      packagedWebhooks.comments.some((comment) => comment.file === file.id),
    ),
  )
  const taskDownload = await fetch(
    origin + '/api/v1/attachments/' + packagedWebhooks.taskFile + '/download',
    { headers: readHeaders },
  )
  assert.equal(taskDownload.status, 200)
  assert.equal(await taskDownload.text(), 'SYNTHETIC_FILE')
  const readList = await fetch(
    origin + '/api/v1/projects/' + fixture.project + '/comments?kind=all&perPage=1',
    { headers: readHeaders },
  )
  assert.equal(readList.status, 200)
  assert.equal((await readList.json()).meta.hasMore, true)
  const quietFixture = path.join(fixtureRoot, 'quiet-cron-fixture.php')
  await writeFile(
    quietFixture,
    `<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();
$app->instance(\\App\\Services\\WebhookDestination::class,new class extends \\App\\Services\\WebhookDestination {
    public function resolve(string $url): array { return ['host'=>'example.com','ip'=>'93.184.216.34']; }
});
\\Illuminate\\Support\\Facades\\Http::preventStrayRequests();
\\Illuminate\\Support\\Facades\\Http::fake(['*'=>\\Illuminate\\Support\\Facades\\Http::response('PRIVATE_RESPONSE',$argv[2]==='error' ? 503 : 204)]);
if ($argv[2]==='success') { \\Illuminate\\Support\\Facades\\DB::table('webhook_deliveries')->where('status','pending')->update(['available_at'=>now()]); }
$input = new \\Symfony\\Component\\Console\\Input\\ArgvInput(['artisan','integrations:webhooks']);
$output = new \\Symfony\\Component\\Console\\Output\\ConsoleOutput;
$status = $kernel->handle($input,$output);
$kernel->terminate($input,$status);
exit($status);
`,
  )
  const errorCron = spawnSync(php, [quietFixture, application, 'error'], {
    cwd: application,
    env,
    encoding: 'utf8',
  })
  assert.equal(errorCron.status, 1)
  assert.equal(errorCron.stdout, '')
  assert.match(errorCron.stderr, /status=retry attempt=1 code=remote_transient http=503/)
  assert.doesNotMatch(errorCron.stderr, /PRIVATE|https?:\/\//)
  const successCron = spawnSync(php, [quietFixture, application, 'success'], {
    cwd: application,
    env,
    encoding: 'utf8',
  })
  assert.equal(successCron.status, 0)
  assert.equal(successCron.stdout, '')
  assert.equal(successCron.stderr, '')
  // Exercise project lifecycle through the shipped HTTP routes, on disposable data only.
  const lifecycleCsrf = await (await request('/api/csrf')).json()
  assert.equal(
    (
      await request('/api/admin/login', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': lifecycleCsrf.token,
        },
        body: loginPayload,
      })
    ).status,
    200,
  )
  const lifecycleFactor = await request('/api/security/challenge', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': (await (await request('/api/csrf')).json()).token,
    },
    body: JSON.stringify({ recovery_code: 'Release-recovery-two' }),
  })
  assert.equal(lifecycleFactor.status, 200)
  const lifecycleHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': (await (await request('/api/csrf')).json()).token,
  }
  const lifecycleCall = (url, method, body) =>
    request(url, {
      method,
      headers: lifecycleHeaders,
      ...(body ? { body: JSON.stringify(body) } : {}),
    })
  const lifecycleCreated = await lifecycleCall('/api/admin/projects', 'POST', {
    title: 'Packaged lifecycle QA',
    currency: 'EUR',
  })
  assert.equal(lifecycleCreated.status, 201)
  const lifecycleId = (await lifecycleCreated.json()).data.id
  const lifecycleRoot = '/api/admin/projects/' + lifecycleId
  const lifecycleTask = await lifecycleCall(lifecycleRoot + '/tasks', 'POST', {
    title: 'Preserved task',
    location: 'QA',
    description: 'Preserved data',
    priority: 'normal',
  })
  assert.equal(lifecycleTask.status, 201)
  const lifecycleTaskId = (await lifecycleTask.json()).data.id
  const lifecycleUpload = new FormData()
  lifecycleUpload.append(
    'attachments[]',
    new Blob(['packaged lifecycle bytes'], { type: 'text/plain' }),
    'lifecycle.txt',
  )
  const lifecycleUploaded = await request('/api/admin/tasks/' + lifecycleTaskId + '/attachments', {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': lifecycleHeaders['X-CSRF-TOKEN'] },
    body: lifecycleUpload,
  })
  assert.equal(lifecycleUploaded.status, 200)
  const lifecycleDownload = (await lifecycleUploaded.json()).data.attachments[0].downloadUrl
  assert.equal((await lifecycleCall(lifecycleRoot, 'PATCH', { status: 'inactive' })).status, 200)
  assert.equal((await lifecycleCall(lifecycleRoot + '/trash', 'POST', {})).status, 200)
  assert.equal((await request(lifecycleRoot)).status, 404)
  assert.equal((await request(lifecycleRoot + '/tasks')).status, 404)
  assert.equal((await request(lifecycleDownload)).status, 404)
  assert.equal((await callIntegration('/api/v1/projects/' + lifecycleId)).status, 404)
  assert.equal((await (await request('/api/admin/projects/trash')).json()).data[0].id, lifecycleId)
  assert.equal((await lifecycleCall(lifecycleRoot + '/restore', 'POST', {})).status, 200)
  const lifecycleRestored = (await (await request(lifecycleRoot)).json()).data
  assert.equal(lifecycleRestored.status, 'inactive')
  assert.equal(lifecycleRestored.currency, 'EUR')
  assert.equal(lifecycleRestored.tasksCount, 1)
  assert.equal(await (await request(lifecycleDownload)).text(), 'packaged lifecycle bytes')
  assert.equal((await lifecycleCall(lifecycleRoot + '/trash', 'POST', {})).status, 200)
  assert.equal(
    (await lifecycleCall('/api/admin/projects/trash', 'DELETE', { projectIds: [lifecycleId] }))
      .status,
    422,
  )
  assert.equal(
    (
      await lifecycleCall('/api/admin/projects/trash', 'DELETE', {
        confirmed: true,
        projectIds: [],
      })
    ).status,
    409,
  )
  const purgeConfirmation = await lifecycleCall('/api/admin/projects/trash/confirmation', 'POST', {
    projectIds: [lifecycleId],
  })
  assert.equal(purgeConfirmation.status, 200)
  const purgeProof = (await purgeConfirmation.json()).confirmation
  assert.equal(
    (
      await lifecycleCall('/api/admin/projects/trash', 'DELETE', {
        confirmation: purgeProof,
        confirmed: true,
        projectIds: [lifecycleId],
      })
    ).status,
    200,
  )
  assert.equal((await (await request('/api/admin/projects/trash')).json()).data.length, 0)
  assert.equal((await request(lifecycleRoot)).status, 404)
  assert.equal((await request('/api/admin/projects/' + fixture.project)).status, 200)
  assert.equal((await request('/api/admin/projects/' + fixture.foreignProject)).status, 404)
  command(['artisan', 'projects:cleanup-files'])
  const cleanupFixture = path.join(fixtureRoot, 'integration-cleanup-fixture.php')
  await writeFile(
    cleanupFixture,
    `<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$user = \\App\\Models\\User::where('email','release@example.test')->firstOrFail();
$request = \\Illuminate\\Http\\Request::create('/api/admin/integrations','DELETE');
$request->setLaravelSession(app('session')->driver());
$request->setUserResolver(fn () => $user);
$controller = app(\\App\\Http\\Controllers\\IntegrationController::class);
$requests = \\Illuminate\\Support\\Facades\\DB::table('integration_requests')->count();
$request->session()->start();
try {
    $controller->revoke($request,$argv[2]);
    throw new \\RuntimeException('Packaged sensitive mutation lacked step-up');
} catch (\\Illuminate\\Http\\Exceptions\\HttpResponseException $error) {
    if ($error->getResponse()->getStatusCode() !== 423) { throw $error; }
}
$request->merge(['recovery_code'=>'Release-cleanup-three', 'Release-browser-login']);
app(\\App\\Http\\Controllers\\AccountSecurityController::class)->confirm($request);
$controller->revoke($request,$argv[2]);
$token = \\Illuminate\\Support\\Facades\\DB::table('integration_tokens')->where('uuid',$argv[2])->first();
if (!$token->revoked_at || !$token->last_used_at || $requests < 1 || $requests !== \\Illuminate\\Support\\Facades\\DB::table('integration_requests')->count()) { throw new RuntimeException('Packaged token audit preservation failed'); }
$endpoint = \\Illuminate\\Support\\Facades\\DB::table('webhook_endpoints')->first();
$delivery = \\Illuminate\\Support\\Facades\\DB::table('webhook_deliveries')->first();
\\Illuminate\\Support\\Facades\\DB::table('webhook_attempts')->insert(['webhook_delivery_id'=>$delivery->id,'attempt'=>1,'status'=>'failed','http_status'=>400,'error_code'=>'remote_rejected','duration_ms'=>1,'created_at'=>now()]);
$controller->destroy($request,$endpoint->uuid);
foreach (['webhook_endpoints','webhook_endpoint_projects','webhook_deliveries','webhook_attempts'] as $table) {
    if (\\Illuminate\\Support\\Facades\\DB::table($table)->count() !== 0) { throw new RuntimeException('Packaged cascade failed: '.$table); }
}
if (\\Illuminate\\Support\\Facades\\DB::table('webhook_events')->count() !== 2) { throw new RuntimeException('Shared events were deleted'); }
echo json_encode(['ok'=>true]);
`,
  )
  assert.equal(JSON.parse(command([cleanupFixture, application, integration.id])).ok, true)
  assert.equal((await callIntegration('/api/v1/projects')).status, 401)
  assert.equal(
    (await callIntegration('/api/v1/projects', { method: 'POST', body: projectPayload })).status,
    401,
  )
  assert.equal(
    JSON.parse(await readFile(path.join(packageRoot, 'release.json'), 'utf8')).version,
    '0.7.3',
  )
  assert.match(await readFile(path.join(packageRoot, 'PRODUCTION.md'), 'utf8'), /maintenance/)
  assert.match(
    await readFile(path.join(packageRoot, 'PRODUCTION.md'), 'utf8'),
    /2026_10_26_200540_add_project_trash_lifecycle/,
  )
  assert.match(await readFile(path.join(packageRoot, 'DEPLOY-JINO.md'), 'utf8'), /first-install/)

  command(['artisan', 'down'])
  assert.equal((await request('/admin/login')).status, 503)
  command(['artisan', 'up'])
  assert.equal((await request('/admin/login')).status, 200)
  if (process.env.RELEASE_BROWSER_QA_DIRECTORY) {
    // Add multilingual user content only to this disposable database.
    command([
      '-r',
      'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); App\\Models\\Task::where("uuid", $argv[1])->update(["title" => "Кириллица: задача / 中文需求 / Çığ İpek"]);',
      fixture.exportTask,
    ])
    const { verifyPackagedExports } = await import('./release-export.browser.mjs')
    await verifyPackagedExports(origin, fixture.project, process.env.RELEASE_BROWSER_QA_DIRECTORY)
  }
  if (process.env.RELEASE_SECURITY_BROWSER_QA_DIRECTORY) {
    // Test the compiled application and production vendor, never source/Vite imports.
    const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
    const output = path.resolve(process.env.RELEASE_SECURITY_BROWSER_QA_DIRECTORY)
    await mkdir(output, { recursive: true })
    const browser = await chromium.launch({ headless: true })
    try {
      const publicOrigin = 'https://brief.web86.site'
      const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } })
      await context.route('**/*', async (route) => {
        const url = new URL(route.request().url())
        if (url.origin !== publicOrigin) return route.abort()
        const response = await route.fetch({
          url: origin + url.pathname + url.search,
          maxRedirects: 0,
        })
        await route.fulfill({ response })
      })
      await context.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
      const page = await context.newPage()
      page.setDefaultTimeout(20000)
      const errors = []
      page.on('pageerror', (error) => errors.push(error.message))
      const cdp = await context.newCDPSession(page)
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
      await page.goto(publicOrigin + '/admin/login')
      await page.locator('#admin-email').fill('release@example.test')
      await page.locator('#admin-password').fill('Release-only-long-password')
      await page.locator('form button[type=submit]').click()
      await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
      await page.getByLabel('Recovery code', { exact: true }).fill('Release-browser-login')
      await page.getByRole('button', { name: 'Confirm identity', exact: true }).click()
      await page.waitForURL(publicOrigin + '/admin')
      await page.goto(publicOrigin + '/admin/account/security')
      await page.getByLabel('Device name', { exact: true }).fill('Packaged virtual authenticator')
      await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
      await page
        .locator('.security-passkeys')
        .getByText('Packaged virtual authenticator', { exact: true })
        .waitFor()
      const readyOptions = page.waitForResponse(
        (response) =>
          response.url().endsWith('/api/security/passkey/confirm/options') &&
          response.status() === 200,
      )
      await page.goto(publicOrigin + '/admin/projects/' + fixture.project + '/team')
      await readyOptions
      await page.locator('.team-member').first().waitFor()
      // Wait for the background options response and the UI to commit it.
      await page.waitForFunction(async () => {
        const result = await fetch('/api/security/status', {
          headers: { Accept: 'application/json' },
        })
        const status = await result.json()
        return status.fresh === false && status.passkeys.length === 2
      })
      await page.evaluate(
        () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
      )
      await page.evaluate(() => {
        window.packagedGet = []
        window.packagedDialogs = 0
        const get = navigator.credentials.get.bind(navigator.credentials)
        navigator.credentials.get = (options) => {
          window.packagedGet.push({
            active: navigator.userActivation.isActive,
            dialog: !!document.querySelector('dialog[open]'),
          })
          return get(options)
        }
        const show = HTMLDialogElement.prototype.showModal
        HTMLDialogElement.prototype.showModal = function () {
          window.packagedDialogs++
          return show.call(this)
        }
      })
      const member = page
        .locator('.team-member')
        .filter({ hasText: 'package-developer@example.test' })
      const mutations = []
      page.on('request', (request) => {
        if (request.method() === 'PATCH' && request.url().includes('/team/members/'))
          mutations.push(request.url())
      })
      await member.getByRole('button', { name: 'Projects and permissions', exact: true }).click()
      const switches = member.locator('.member-permissions [role="switch"]')
      for (let i = 1; i < 6; i++) await switches.nth(i).check()
      await member.getByRole('button', { name: 'Save', exact: true }).dblclick()
      await page.locator('.toast-success').filter({ hasText: 'Access updated' }).waitFor()
      await member.locator('.team-edit').waitFor({ state: 'hidden' })
      assert.equal(mutations.length, 1)
      assert.deepEqual(await page.evaluate(() => window.packagedGet), [
        { active: true, dialog: false },
      ])
      assert.equal(await page.evaluate(() => window.packagedDialogs), 0)
      assert.deepEqual(errors, [])
      await page.screenshot({
        path: path.join(output, 'packaged-team-success.png'),
        fullPage: true,
      })
      const evidence = {
        passed: true,
        compiledAssets: true,
        productionVendor: true,
        origin: publicOrigin,
        rpId: 'brief.web86.site',
        virtualUV: true,
        active: true,
        applicationDialogs: 0,
        mutations: 1,
        changedPermissions: 5,
        browser: browser.version(),
      }
      await writeFile(
        path.join(output, 'packaged-security-results.json'),
        JSON.stringify(evidence, null, 2) + '\n',
      )
      console.log(
        'PASS: Extracted ZIP compiled UI -> real UV step-up -> team mutation once, without app dialog',
      )
    } finally {
      await browser.close()
    }
  }
  assert.doesNotMatch(serverErrors, /PHP (Fatal error|Warning)|Uncaught/)
  console.log(
    'PASS: Packaged comments/files Read API and quiet cron stdout/stderr; token revocation/audit and webhook history cascade; project-filter migration/FKs/selection and safe client comment events; stateless v1 API/auth/scopes/idempotency/tenant isolation/sections, cron command, integration specification; comment multipart/thumbnail/original/download/idempotency, atomic placement, soft deletion by admin and client, trash/restore, preserved attachments/comments, personal preferences, verification email templates and single-use proof, split HOME layout, CLI checks, repeated install/key preservation, migrations, optimized routes, SPA reload, assets, CSRF, admin login, client access and maintenance mode.',
  )
} finally {
  if (server && server.exitCode === null) {
    server.kill('SIGTERM')
    await once(server, 'exit')
  }
  await rm(fixtureRoot, { recursive: true, force: true })
}
