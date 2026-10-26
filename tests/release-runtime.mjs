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
$owner->forceFill(['pending_notification_email'=>'verified-release@example.test', 'notification_email_token_hash'=>hash('sha256', str_repeat('a', 64)), 'notification_email_expires_at'=>now()->addMinutes(30)])->save();
// Render templates with an in-memory transport; the isolated package has no SMTP credentials.
config(['mail.default'=>'array']);
foreach (['ru', 'en'] as $language) {
    $verification = new \\App\\Mail\\VerifyNotificationEmail('https://brief.example.test/admin/settings/notifications#verify='.str_repeat('a', 64), $language);
    if (!str_contains($verification->render(), '#verify=') || !str_contains(view('mail.verify-notification-email-text', ['verificationUrl'=>$verification->verificationUrl, 'language'=>$language])->render(), '#verify=')) {
        throw new \\RuntimeException('Packaged verification email template is missing its link.');
    }
}

$workspace = $owner->workspaceMembers()->active()->firstOrFail()->workspace;
$project = $workspace->projects()->create(['title'=>'Release QA project', 'sections'=>['Главная'], 'status'=>'active']);
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
  const ownerState = await login.json()
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
    type: 'admin',
    workspace: { id: fixture.workspace, name: 'Release QA' },
  })
  assert.equal((await request('/api/admin/me')).status, 200)
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
    const exported = await request('/api/admin/projects/' + fixture.project + '/export', {
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
    })
    assert.equal(exported.status, 200)
    assert.match(
      exported.headers.get('content-disposition'),
      new RegExp('specification-.*\\.' + format),
    )
    const bytes = Buffer.from(await exported.arrayBuffer())
    assert.ok(bytes.length > 1000)
    if (format === 'pdf') {
      assert.equal(exported.headers.get('content-type'), 'application/pdf')
      assert.equal(bytes.subarray(0, 5).toString(), '%PDF-')
      assert.ok(bytes.includes(Buffer.from('DejaVuSans')))
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
        await request('/api/admin/projects/' + fixture.foreignProject + '/export', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': workspaceCsrf.token,
          },
          body: JSON.stringify({ format, scope: 'all', locale: 'ru' }),
        })
      ).status,
      404,
    )
  }
  const completedExport = await request('/api/admin/projects/' + fixture.project + '/export', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': workspaceCsrf.token,
    },
    body: JSON.stringify({ format: 'pdf', scope: 'done', locale: 'en' }),
  })
  assert.equal(completedExport.status, 200)
  assert.equal(
    Buffer.from(await completedExport.arrayBuffer())
      .subarray(0, 5)
      .toString(),
    '%PDF-',
  )
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
      await request('/api/admin/projects/' + fixture.project + '/export', {
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
    const exported = await request('/api/client/project/' + fixture.project + '/export', {
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
    })
    assert.equal(exported.status, 200)
    const bytes = Buffer.from(await exported.arrayBuffer())
    if (format === 'pdf') assert.equal(bytes.subarray(0, 5).toString(), '%PDF-')
    else {
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
        await request('/api/client/project/' + fixture.foreignProject + '/export', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': clientCsrf.token,
          },
          body: JSON.stringify({ format, scope: 'all', locale: 'en' }),
        })
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
  assert.match(command(['artisan', 'integrations:webhooks']), /Processed deliveries: 0/)
  assert.ok(
    (await readFile(path.join(packageRoot, 'INTEGRATIONS/openapi.json'), 'utf8')).includes(
      'bearerAuth',
    ),
  )
  command(['artisan', 'down'])
  assert.equal((await request('/admin/login')).status, 503)
  command(['artisan', 'up'])
  assert.equal((await request('/admin/login')).status, 200)
  assert.doesNotMatch(serverErrors, /PHP (Fatal error|Warning)|Uncaught/)
  console.log(
    'PASS: Packaged stateless v1 API/auth/scopes/idempotency/tenant isolation/sections, cron command, integration specification; comment multipart/thumbnail/original/download/idempotency, atomic placement, soft deletion by admin and client, trash/restore, preserved attachments/comments, personal preferences, verification email templates and single-use proof, split HOME layout, CLI checks, repeated install/key preservation, migrations, optimized routes, SPA reload, assets, CSRF, admin login, client access and maintenance mode.',
  )
} finally {
  if (server && server.exitCode === null) {
    server.kill('SIGTERM')
    await once(server, 'exit')
  }
  await rm(fixtureRoot, { recursive: true, force: true })
}
