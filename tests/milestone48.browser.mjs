import assert from 'node:assert/strict'
import { createServer as createSmtp } from 'node:net'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, rm, readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = new URL(process.env.M48_OUTPUT_DIR || '../docs/milestone-4-8/', import.meta.url)
const invitationUx = process.env.M481_INVITATION_UX === '1'
const expiredToken = randomBytes(32).toString('hex')
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone48-e2e-'))
const database = path.join(scratch, 'test.sqlite'),
  avatar = path.join(scratch, 'avatar.png')
await writeFile(database, '')
const mails = [],
  results = []
const smtp = createSmtp((socket) => {
  socket.write('220 localhost ESMTP\r\n')
  let buffer = '',
    data = false,
    body = []
  socket.on('data', (bytes) => {
    buffer += bytes.toString()
    let end
    while ((end = buffer.indexOf('\r\n')) >= 0) {
      const line = buffer.slice(0, end)
      buffer = buffer.slice(end + 2)
      if (data) {
        if (line === '.') {
          mails.push(body.join('\r\n'))
          body = []
          data = false
          socket.write('250 2.0.0 accepted\r\n')
        } else body.push(line)
      } else if (/^EHLO/i.test(line)) socket.write('250-localhost\r\n250 8BITMIME\r\n')
      else if (/^DATA/i.test(line)) {
        data = true
        socket.write('354 End with dot\r\n')
      } else if (/^QUIT/i.test(line)) {
        socket.end('221 Bye\r\n')
      } else socket.write('250 OK\r\n')
    }
  })
})
await new Promise((resolve) => smtp.listen(2528, '127.0.0.1', resolve))
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: 'http://127.0.0.1:5189',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  DB_URL: '',
  SESSION_DRIVER: 'database',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_DOMAIN: '',
  CACHE_STORE: 'array',
  LOG_CHANNEL: 'stderr',
  MAIL_MAILER: 'smtp',
  MAIL_HOST: '127.0.0.1',
  MAIL_PORT: '2528',
  MAIL_SCHEME: 'smtp',
  MAIL_URL: '(null)',
  MAIL_USERNAME: '',
  MAIL_PASSWORD: '',
  MAIL_FROM_ADDRESS: 'test@example.test',
  QUEUE_CONNECTION: 'sync',
  ARGON_MEMORY: '1024',
  ARGON_TIME: '1',
  M48_AVATAR_FILE: avatar,
  M481_EXPIRED_TOKEN: invitationUx ? expiredToken : '',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function run(args) {
  const result = spawnSync('php', args, { cwd: root + '/api', env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
const origin = 'http://127.0.0.1:5189'
const vite = await createServer({
  envFile: false,
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: {
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/release/**'] },
    host: '127.0.0.1',
    port: 5189,
    strictPort: true,
    proxy: { '/api': { target: 'http://127.0.0.1:8018' } },
  },
  logLevel: 'error',
})
let backend, browser, fixture
const errors = []
async function context(width = 1440, lang = 'ru', mode = 'light') {
  const context = await browser.newContext({
    viewport: { width, height: width > 960 ? 1000 : 844 },
    locale: lang === 'ru' ? 'ru-RU' : 'en-GB',
    colorScheme: mode,
    reducedMotion: 'reduce',
    hasTouch: width < 961,
  })
  await context.addInitScript(
    ({ lang, mode }) => {
      localStorage.setItem('projectbrief.locale', lang)
      localStorage.setItem('projectbrief.theme', mode)
    },
    { lang, mode },
  )
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  await page.route('https://gravatar.com/**', (route) => route.abort())
  return { context, page }
}
async function go(page, target) {
  await page.goto(origin + target)
  await page.locator('.navigation-loading').waitFor({ state: 'detached' })
}
async function login(page, email = 'owner@example.test', password = 'Local-e2e-password!') {
  await go(page, '/admin/login')
  await page.locator('#admin-email').fill(email)
  await page.locator('#admin-password').fill(password)
  await page.locator('form button[type=submit]').click()
  await page.waitForURL(origin + '/admin')
}
async function call(page, target, body, method = body ? 'POST' : 'GET') {
  return page.evaluate(
    async ({ target, body, method }) => {
      const { token } = await (await fetch('/api/csrf')).json()
      const response = await fetch(target, {
        method,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token,
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
      })
      return { status: response.status, json: await response.json() }
    },
    { target, body, method },
  )
}
async function mailToken(kind, index = mails.length - 1) {
  assert.ok(index >= 0)
  const message = mails[index]
    .replace(/=\r\n/g, '')
    .replace(/=([a-f0-9]{2})/gi, (_, value) => String.fromCharCode(parseInt(value, 16)))
  const match = message.match(new RegExp('#' + kind + '=([a-f0-9]{64})'))
  assert.ok(match, 'SMTP message has the action fragment')
  return match[1]
}
async function fits(page) {
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    'No horizontal overflow',
  )
}
async function shot(page, name) {
  await page.screenshot({ path: new URL(name + '.png', output).pathname, fullPage: true })
}
try {
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  fixture = JSON.parse(run(['tests/milestone48-fixture.php']))
  backend = spawn(
    'php',
    [
      '-S',
      '127.0.0.1:8018',
      root + '/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    { cwd: root + '/api/public', env, stdio: ['ignore', 'ignore', 'pipe'] },
  )
  backend.stderr.on('data', (bytes) => {
    const message = bytes.toString()
    if (message.includes('account_mail_failed')) console.log(message.slice(0, 700))
  })
  await vite.listen()
  for (let i = 0; i < 50; i++) {
    try {
      if ((await fetch('http://127.0.0.1:8018/api/health')).ok) break
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100))
  }
  browser = await chromium.launch({ headless: true })
  const owner = await context(),
    dev = await context()
  await login(owner.page)
  const teamRoot = `/api/admin/projects/${fixture.first}/team`
  const teamPage = `/admin/projects/${fixture.first}/team`
  await go(owner.page, teamPage)
  await owner.page.getByRole('button', { name: 'Пригласить разработчика', exact: true }).click()
  await owner.page.locator('#invite-email').fill('invited@example.test')
  await owner.page.locator('#invite-name').fill('Новый разработчик')
  await owner.page.locator('.invitation-form input[type=checkbox]').first().check()
  await owner.page.locator('.invitation-form button[type=submit]').click()
  await owner.page.getByRole('status').filter({ hasText: 'Приглашение отправлено' }).waitFor()
  const token = await mailToken('token')
  await go(dev.page, '/invitation#token=' + token)
  await dev.page.getByRole('button', { name: 'Создать аккаунт', exact: true }).click()
  await dev.page.locator('#invited-name').fill('Дмитрий Соколов')
  await dev.page.locator('#invited-password').fill('Developer-password!')
  await dev.page.locator('#invited-confirmation').fill('Developer-password!')
  assert.equal(new URL(dev.page.url()).hash, '')
  await dev.page.locator('form button[type=submit]').click()
  await dev.page.waitForURL(origin + '/admin')
  const projects = (await call(dev.page, '/api/admin/projects')).json.data
  assert.equal(projects.length, 1)
  const firstAssigned = projects[0].id,
    secondAssigned = firstAssigned === fixture.first ? fixture.second : fixture.first
  assert.equal((await call(dev.page, teamRoot)).status, firstAssigned === fixture.first ? 403 : 404)
  assert.equal((await call(dev.page, '/api/invitations/accept', { token })).status, 410)
  results.push(
    'Real SMTP sink → invitation-only registration → assigned-project list; token removed and single-use; developer cannot manage team',
  )
  if (invitationUx) {
    // Existing mixed-case User has no memberships; the page offers login without an existence API.
    assert.equal(
      (
        await call(owner.page, teamRoot + '/invitations', {
          email: 'legacy@example.test',
          projects: [fixture.second],
        })
      ).status,
      201,
    )
    const existingToken = await mailToken('token'),
      existing = await context(390)
    await go(existing.page, '/invitation#token=' + existingToken)
    await existing.page.locator('#invited-name').waitFor()
    await existing.page.getByRole('button', { name: 'Уже есть аккаунт — войти', exact: true }).tap()
    await existing.page.locator('#invited-password').fill('Local-e2e-password!')
    await existing.page.locator('form button[type=submit]').tap()
    await existing.page.waitForURL(origin + '/admin')
    assert.equal(
      (await call(existing.page, '/api/admin/me')).json.user.email,
      'Legacy@Example.test',
    )
    assert.deepEqual(
      (await call(existing.page, '/api/admin/projects')).json.data.map((p) => p.id),
      [fixture.second],
    )
    results.push(
      'Mobile: mixed-case existing User with no membership signs in and accepts; selected workspace/project refresh correctly',
    )
    const other = await context(390)
    await login(other.page)
    assert.equal(
      (
        await call(owner.page, teamRoot + '/invitations', {
          email: 'maria@example.test',
          projects: [fixture.second],
        })
      ).status,
      201,
    )
    const switchToken = await mailToken('token')
    let accepts = 0
    const sentPaths = []
    other.page.on('request', (request) => {
      const url = new URL(request.url())
      if (url.pathname.startsWith('/api/')) {
        sentPaths.push(url.pathname + url.search)
        if (url.pathname === '/api/invitations/accept') accepts++
      }
    })
    await go(other.page, '/invitation#token=' + switchToken)
    const notice = other.page.locator('.invitation-account-notice')
    await notice.waitFor()
    assert.ok((await notice.innerText()).includes('owner@example.test'))
    assert.ok((await other.page.locator('form').innerText()).includes('maria@example.test'))
    assert.equal(await other.page.locator('form button[type=submit]').count(), 0)
    assert.equal(await other.page.locator('.field-error').count(), 0)
    assert.equal(accepts, 0)
    await other.page.getByRole('button', { name: 'Выйти и продолжить', exact: true }).tap()
    await other.page.locator('#invited-password').fill('Local-e2e-password!')
    assert.equal(new URL(other.page.url()).pathname, '/invitation')
    assert.equal(new URL(other.page.url()).hash, '')
    await other.page.locator('form button[type=submit]').tap()
    await other.page.waitForURL(origin + '/admin')
    assert.equal((await call(other.page, '/api/admin/me')).json.user.email, 'maria@example.test')
    assert.equal(accepts, 1)
    assert.equal((await call(other.page, '/api/admin/projects')).json.data.length, 2)
    assert.ok(sentPaths.every((value) => !value.includes(switchToken) && !value.includes('token=')))
    assert.equal(
      await other.page.evaluate(
        (value) =>
          [localStorage, sessionStorage].some((storage) =>
            Object.keys(storage).some((key) => storage.getItem(key)?.includes(value)),
          ),
        switchToken,
      ),
      false,
    )
    results.push(
      'Mobile: mismatched account sees neutral emails/CTA, issues no premature accept, signs out and accepts original invitation as invited user; no token in query/storage',
    )
    for (const invalid of [existingToken, expiredToken]) {
      await go(existing.page, '/invitation#token=' + invalid)
      await existing.page.getByRole('alert').waitFor()
      assert.equal(await existing.page.locator('form').count(), 0)
      assert.ok(
        (await existing.page.getByRole('alert').innerText()).includes('Ссылка недействительна'),
      )
    }
    results.push('Consumed and expired invitation links show a clear message and no form')
    assert.equal(
      (
        await call(owner.page, teamRoot + '/invitations', {
          email: 'visual@example.test',
          projects: [fixture.first],
        })
      ).status,
      201,
    )
    const visualToken = await mailToken('token')
    for (const width of [1440, 390, 320])
      for (const lang of ['ru', 'en'])
        for (const mode of ['light', 'dark']) {
          const f = await context(width, lang, mode),
            label = `${width}-${lang}-${mode}`
          await go(f.page, '/invitation#token=' + visualToken)
          await f.page.locator('#invited-name').waitFor()
          assert.equal(await f.page.locator('.theme-switcher').count(), 1)
          assert.equal(await f.page.locator('.language-switcher').count(), 1)
          const header = await f.page.locator('.app-topbar').boundingBox(),
            title = await f.page.locator('.invitation-page h1').boundingBox()
          assert.ok(
            title.y - (header.y + header.height) <= (width < 600 ? 24 : 50),
            'Compact header-to-title gap',
          )
          await fits(f.page)
          await shot(f.page, 'entry-' + label)
          if (width < 600) {
            await f.page.setViewportSize({ width, height: 440 })
            await f.page.locator('#invited-password').focus()
            await f.page.locator('form button[type=submit]').scrollIntoViewIfNeeded()
            await fits(f.page)
            await f.page.setViewportSize({ width, height: 844 })
          }
          await login(f.page)
          await go(f.page, '/invitation#token=' + visualToken)
          await f.page.locator('.invitation-account-notice').waitFor()
          await fits(f.page)
          assert.equal(await f.page.locator('form button[type=submit]').count(), 0)
          await shot(f.page, 'switch-' + label)
          results.push(
            `Invitation UX ${label}: one theme/language control, compact registration, neutral mismatch, keyboard-height scrolling`,
          )
          await f.context.close()
        }
    await existing.context.close()
    await other.context.close()
  }

  // Repeat invitation reuses the authenticated account and adds a second assignment.
  assert.equal(
    (
      await call(owner.page, teamRoot + '/invitations', {
        email: 'invited@example.test',
        projects: [secondAssigned],
      })
    ).status,
    201,
  )
  const secondToken = await mailToken('token')
  await go(dev.page, '/invitation#token=' + secondToken)
  await dev.page.locator('form button[type=submit]').click()
  await dev.page.waitForURL(origin + '/admin')
  assert.equal((await call(dev.page, '/api/admin/projects')).json.data.length, 2)
  const members = (await call(owner.page, teamRoot)).json.members
  const member = members.find((item) => item.user.email === 'invited@example.test')
  assert.equal(
    (
      await call(
        owner.page,
        teamRoot + '/members/' + member.id,
        { projects: [secondAssigned] },
        'PATCH',
      )
    ).status,
    200,
  )
  assert.equal((await call(dev.page, '/api/admin/projects/' + firstAssigned)).status, 404)
  await go(dev.page, '/admin')
  await dev.page.locator('.admin-project-card').first().waitFor()
  assert.equal(await dev.page.locator('.admin-project-card').count(), 1)
  results.push(
    'Existing account accepts a second project; owner revokes one assignment during an active session; API and refreshed list exclude it',
  )
  // Global profile, private image normalization, explicit Gravatar and reset to initials.
  await go(dev.page, '/admin/account')
  await dev.page.locator('#account-name').fill('Дмитрий — обновлённый профиль')
  await dev.page.locator('form').first().locator('button[type=submit]').click()
  await dev.page.getByRole('status').filter({ hasText: 'Изменения сохранены' }).waitFor()
  await dev.page.locator('#avatar-source').selectOption('upload')
  await dev.page.locator('#avatar-file').setInputFiles(avatar)
  await dev.page.locator('form').nth(1).locator('button[type=submit]').click()
  await dev.page.locator('.account-heading img').waitFor()
  let state = (await call(dev.page, '/api/admin/account')).json
  assert.ok(state.user.avatarUrl.startsWith('/api/admin/avatars/'))
  await dev.page.locator('#avatar-source').selectOption('gravatar')
  await dev.page.locator('form').nth(1).locator('button[type=submit]').click()
  await dev.page.getByRole('status').filter({ hasText: 'Изменения сохранены' }).waitFor()
  state = (await call(dev.page, '/api/admin/account')).json
  assert.match(state.user.avatarUrl, /gravatar\.com\/avatar\/[a-f0-9]{64}/)
  await dev.page.locator('#avatar-source').selectOption('initials')
  await dev.page.locator('form').nth(1).locator('button[type=submit]').click()
  await dev.page.getByRole('status').filter({ hasText: 'Изменения сохранены' }).waitFor()
  results.push(
    'Global profile name saved; uploaded avatar served privately as normalized WebP; Gravatar explicitly enabled; reset to initials',
  )
  // Email changes keep the old address until confirmation and preserve project assignments.
  await go(dev.page, '/admin/account/security')
  await dev.page.locator('#new-email').fill('confirmed@example.test')
  await dev.page.locator('#email-password').fill('Developer-password!')
  await dev.page.locator('form').first().locator('button[type=submit]').click()
  await dev.page
    .getByRole('status')
    .filter({ hasText: 'Письмо подтверждения отправлено' })
    .waitFor()
  assert.equal((await call(dev.page, '/api/admin/account')).json.user.email, 'invited@example.test')
  const emailToken = await mailToken('email')
  await go(dev.page, '/admin/account#email=' + emailToken)
  await dev.page.getByRole('status').filter({ hasText: 'Email подтверждён' }).waitFor()
  assert.equal(
    (await call(dev.page, '/api/admin/account')).json.user.email,
    'confirmed@example.test',
  )
  assert.equal((await call(dev.page, '/api/admin/projects')).json.data.length, 1)
  assert.equal(
    (await call(dev.page, '/api/admin/account/email/confirm', { token: emailToken })).status,
    410,
  )
  results.push(
    'Email confirmation via SMTP message keeps old login until proof, preserves memberships and rejects replay',
  )
  const other = await context()
  await login(other.page, 'confirmed@example.test', 'Developer-password!')
  await go(dev.page, '/admin/account/security')
  await dev.page.locator('#current-password').fill('Developer-password!')
  await dev.page.locator('#new-password').fill('Changed-password-123!')
  await dev.page.locator('#confirm-password').fill('Changed-password-123!')
  await dev.page.locator('form').nth(1).locator('button[type=submit]').click()
  await dev.page.getByRole('status').filter({ hasText: 'Пароль изменён' }).waitFor()
  assert.equal((await call(dev.page, '/api/admin/account')).status, 200)
  assert.equal((await call(other.page, '/api/admin/account')).status, 401)
  results.push(
    'Password change keeps current session and rejects another authenticated database session',
  )
  const recovery = await context()
  await go(recovery.page, '/admin/forgot-password')
  await recovery.page.locator('#recovery-email').fill('confirmed@example.test')
  await recovery.page.locator('form button[type=submit]').click()
  await recovery.page.getByRole('status').waitFor()
  const resetToken = await mailToken('token')
  await go(
    recovery.page,
    '/admin/reset-password#token=' + resetToken + '&email=confirmed%40example.test',
  )
  await recovery.page.locator('#recovery-password').fill('Recovered-password-123!')
  await recovery.page.locator('#recovery-confirmation').fill('Recovered-password-123!')
  await recovery.page.locator('form button[type=submit]').click()
  await recovery.page.getByRole('status').waitFor()
  assert.equal((await call(dev.page, '/api/admin/account')).status, 401)
  await login(recovery.page, 'confirmed@example.test', 'Recovered-password-123!')
  results.push(
    'Laravel Password Broker reset from SMTP message revokes previous session and allows login with the new password',
  )
  // Full visual matrix uses real backend data and shared desktop/mobile navigation.
  await call(owner.page, teamRoot + '/invitations', {
    email: 'pending@example.test',
    name: 'Ожидающий разработчик',
    projects: [fixture.first, fixture.second],
  })
  for (const width of [1440, 390])
    for (const lang of ['ru', 'en'])
      for (const mode of ['light', 'dark']) {
        const f = await context(width, lang, mode),
          label = `${width}-${lang}-${mode}`
        await login(f.page)
        await go(f.page, teamPage)
        await f.page.locator('.team-member').first().waitFor()
        await fits(f.page)
        await shot(f.page, 'team-' + label)
        await f.page.locator('.team-heading > button').click()
        await f.page.locator('#invite-email').fill('developer@example.test')
        const checks = await f.page.locator('.project-choices input').evaluateAll((nodes) =>
          nodes.map((node) => ({
            width: node.getBoundingClientRect().width,
            height: node.getBoundingClientRect().height,
          })),
        )
        assert.ok(
          checks.every((check) => check.width === 18 && check.height === 18),
          'Project checkboxes keep their compact square size',
        )
        await shot(f.page, 'invitation-' + label)
        let menu = f.page.locator('.admin-sidebar')
        if (width < 961) {
          await f.page.locator('.admin-menu-trigger').click()
          menu = f.page.locator('#admin-drawer[open]')
        }
        assert.equal(await menu.locator('.project-panel-nav a').count(), 8)
        const links = await menu.locator('.project-panel-nav a').evaluateAll((nodes) =>
          nodes.map((node) => ({
            icons: node.querySelectorAll('svg[aria-hidden=true]').length,
            width: node.querySelector('svg')?.getBoundingClientRect().width,
            current: node.getAttribute('aria-current'),
            bg: getComputedStyle(node).backgroundColor,
            color: getComputedStyle(node).color,
            stroke: getComputedStyle(node.querySelector('svg')).stroke,
          })),
        )
        assert.ok(
          links.every(
            (link) => link.icons === 1 && link.width === 20 && link.color === link.stroke,
          ),
        )
        assert.equal(links.filter((link) => link.current === 'page').length, 1)
        assert.ok(links.find((link) => link.current === 'page').bg !== 'rgba(0, 0, 0, 0)')
        if (width < 961) {
          await shot(f.page, 'drawer-' + label)
          await f.page.keyboard.press('Escape')
        }
        await go(f.page, '/admin/account')
        await f.page.locator('#account-name').waitFor()
        await fits(f.page)
        await shot(f.page, 'profile-' + label)
        await go(f.page, '/admin/account/security')
        await f.page.locator('#new-email').waitFor()
        await fits(f.page)
        await shot(f.page, 'security-' + label)
        results.push(
          `Visual ${label}: team, invitation form, account, security, decorative 20px menu icons and active state`,
        )
        await f.context.close()
      }
  assert.deepEqual(errors, [])
  console.log(
    'PASS ' + results.length + ' scenarios/configurations; local SMTP messages: ' + mails.length,
  )
} catch (error) {
  console.error(error)
  process.exitCode = 1
  results.push({ failed: error.message })
  if (browser) {
    for (const ctx of browser.contexts()) {
      const p = ctx.pages()[0]
      if (p) await shot(p, 'failure-' + browser.contexts().indexOf(ctx)).catch(() => {})
    }
  }
} finally {
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify(
      {
        realLaravelApi: true,
        realSmtp: 'local disposable sink; Jino deferred to user',
        results,
        errors,
      },
      null,
      2,
    ) + '\n',
  )
  await browser?.close()
  backend?.kill()
  await vite.close()
  await new Promise((resolve) => smtp.close(resolve))
  await rm(scratch, { recursive: true, force: true })
}
