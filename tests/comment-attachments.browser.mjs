import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises'
import { createServer as createSmtp } from 'node:net'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = new URL('..', import.meta.url).pathname
const output = process.env.COMMENT_ATTACHMENTS_OUTPUT_DIR
  ? new URL('file://' + process.env.COMMENT_ATTACHMENTS_OUTPUT_DIR.replace(/\/$/, '') + '/')
  : new URL('../docs/milestone-4-9/', import.meta.url)
await mkdir(output, { recursive: true })
const scratch = await mkdtemp(path.join(tmpdir(), 'milestone49-e2e-'))
const database = path.join(scratch, 'test.sqlite')
await writeFile(database, '')
const origin = 'http://127.0.0.1:5195'
const env = {
  ...process.env,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
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
  MAIL_PORT: '2529',
  MAIL_SCHEME: 'smtp',
  MAIL_URL: 'null',
  MAIL_USERNAME: 'null',
  MAIL_PASSWORD: 'null',
  MAIL_FROM_ADDRESS: 'brief@example.test',
  TELEGRAM_BOT_TOKEN: '',
  TELEGRAM_BRIDGE_ENABLED: 'false',
  QUEUE_CONNECTION: 'sync',
  ARGON_MEMORY: '1024',
  ARGON_TIME: '1',
  PHP_INI_SCAN_DIR: `:${root}/api/dev-php`,
}
function run(args) {
  const result = spawnSync('php', args, { cwd: root + '/api', env, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  return result.stdout
}
const mail = [],
  sockets = new Set()
const smtp = createSmtp((socket) => {
  sockets.add(socket)
  socket.on('close', () => sockets.delete(socket))
  socket.write('220 localhost ESMTP test\r\n')
  let buffer = '',
    data = false,
    message = []
  socket.on('data', (bytes) => {
    buffer += bytes.toString()
    let pos
    while ((pos = buffer.indexOf('\r\n')) >= 0) {
      const line = buffer.slice(0, pos)
      buffer = buffer.slice(pos + 2)
      if (data) {
        if (line === '.') {
          mail.push(
            message
              .join('\n')
              .replace(/=\n/g, '')
              .replace(/=([0-9A-F]{2})/gi, (_, n) => String.fromCharCode(parseInt(n, 16))),
          )
          data = false
          message = []
          socket.write('250 queued\r\n')
        } else message.push(line)
      } else if (/^EHLO|^HELO/.test(line)) socket.write('250 localhost\r\n')
      else if (/^DATA/.test(line)) {
        data = true
        socket.write('354 send data\r\n')
      } else if (/^QUIT/.test(line)) socket.end('221 bye\r\n')
      else socket.write('250 OK\r\n')
    }
  })
})
const vite = await createServer({
  envFile: false,
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: {
    host: '127.0.0.1',
    port: 5195,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/release/**'] },
    proxy: { '/api': 'http://127.0.0.1:8020', '/access/': 'http://127.0.0.1:8020' },
  },
  logLevel: 'error',
})
let backend, browser, fixture
let backendLog = ''
const results = [],
  errors = [],
  privateDirectories = new Set()
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
async function until(check) {
  for (let i = 0; i < 100; i++) {
    if (await check()) return
    await wait(100)
  }
  throw new Error('Timed out waiting for condition')
}
async function navigate(page, url) {
  await page.evaluate(async (url) => {
    const { default: router } = await import('/src/router/index.js')
    await router.push(url)
  }, url)
  await page.locator('.comment-form textarea').waitFor()
}
async function shot(page, name, selector) {
  await page.evaluate(async () => {
    const { toast } = await import('/src/services/toast.js')
    toast.clear()
  })
  await page.locator(selector).scrollIntoViewIfNeeded()
  await page.screenshot({ path: new URL(name + '.png', output).pathname })
}
async function postForm(page, text) {
  const form = page.locator('.comment-form')
  if (text !== null) await form.locator('textarea').fill(text)
  assert.equal(await form.locator('[type=submit]').isEnabled(), true)
  await form.locator('[type=submit]').click()
  await until(
    async () =>
      (await form.locator('textarea').inputValue()) === '' &&
      (await form.locator('.file-list li').count()) === 0,
  )
}
function commentId(message) {
  return /#comment-([a-f0-9-]{36})/.exec(message)?.[1]
}
function mailUrl(message) {
  return new RegExp(origin.replaceAll('.', '\\.') + '[^\\s"<>]+#comment-[a-f0-9-]{36}').exec(
    message,
  )?.[0]
}
try {
  await new Promise((resolve) => smtp.listen(2529, '127.0.0.1', resolve))
  run(['artisan', 'migrate', '--force', '--no-interaction'])
  fixture = JSON.parse(run(['tests/milestone49-fixture.php']))
  backend = spawn(
    'php',
    [
      '-S',
      '127.0.0.1:8020',
      root + '/api/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    { cwd: root + '/api/public', env, stdio: ['ignore', 'ignore', 'pipe'] },
  )
  backend.stderr.on('data', (bytes) => {
    backendLog += bytes.toString()
  })
  await vite.listen()
  await until(async () => {
    try {
      return (await fetch('http://127.0.0.1:8020/api/health')).ok
    } catch {
      return false
    }
  })
  browser = await chromium.launch({ headless: true })
  const clientContext = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    permissions: ['clipboard-read', 'clipboard-write'],
    reducedMotion: 'reduce',
    locale: 'ru-RU',
  })
  const developerContext = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    reducedMotion: 'reduce',
    locale: 'ru-RU',
  })
  for (const context of [clientContext, developerContext])
    await context.addInitScript(() => {
      localStorage.setItem('projectbrief.locale', 'ru')
      localStorage.setItem('projectbrief.theme', 'light')
    })
  const client = await clientContext.newPage(),
    developer = await developerContext.newPage()
  for (const page of [client, developer]) {
    page.on('pageerror', (error) => errors.push(error.message))
    page.on('response', async (response) => {
      if (response.request().method() !== 'POST' || !response.url().match(/comments|discussions/))
        return
      try {
        const data = (await response.json()).data
        for (const comment of data?.comments || (data?.id ? [data] : []))
          if (comment.attachments?.length) privateDirectories.add(comment.id)
      } catch {}
    })
  }
  await client.goto(origin + '/access/' + fixture.token)
  await client.waitForURL(origin + '/project/' + fixture.project)
  await developer.goto(origin + '/admin/login')
  await developer.locator('#admin-email').fill('developer@example.test')
  await developer.locator('#admin-password').fill('Local-e2e-password!')
  await developer.locator('form button[type=submit]').click()
  await developer.waitForURL(origin + '/admin')
  const png = await client.evaluate(async () => {
    const canvas = document.createElement('canvas')
    canvas.width = 900
    canvas.height = 540
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#f1f5fc'
    ctx.fillRect(0, 0, 900, 540)
    ctx.fillStyle = '#2461c9'
    ctx.fillRect(40, 40, 820, 90)
    ctx.fillStyle = 'white'
    ctx.font = '32px sans-serif'
    ctx.fillText('Product page · review', 70, 98)
    ctx.fillStyle = '#c9daf5'
    ctx.fillRect(40, 160, 340, 330)
    ctx.fillStyle = '#172c50'
    ctx.fillText('Photo', 150, 330)
    ctx.fillText('Product description', 420, 200)
    ctx.font = '22px sans-serif'
    ctx.fillText('Please align this block', 420, 250)
    return canvas.toDataURL('image/png').split(',')[1]
  })
  const screenshot = {
    name: 'Скриншот-карточки-товара.png',
    mimeType: 'image/png',
    buffer: Buffer.from(png, 'base64'),
  }
  for (const kind of ['task', 'general']) {
    const clientPath =
      `/project/${fixture.project}` + (kind === 'task' ? `/task/${fixture.task}` : '/discussions')
    const developerPath =
      `/admin/projects/${fixture.project}` +
      (kind === 'task' ? `/brief/task/${fixture.task}` : '/discussions')
    await navigate(client, clientPath)
    const form = client.locator('.comment-form')
    assert.equal(await form.locator('[type=submit]').isDisabled(), true)
    await form.locator('input[type=file]').setInputFiles(screenshot)
    await form.locator('.file-list img').waitFor()
    // Keep form content through a recoverable HTTP failure.
    const endpoint =
      kind === 'task'
        ? `**/api/client/tasks/${fixture.task}/comments`
        : `**/api/client/project/${fixture.project}/discussions`
    await client.route(
      endpoint,
      (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }),
      { times: 1 },
    )
    await form.locator('textarea').fill('Посмотрите, пожалуйста, расположение описания товара.')
    await form.locator('[type=submit]').click()
    await until(async () => !(await form.locator('[type=submit]').isDisabled()))
    assert.equal(await form.locator('.file-list li').count(), 1)
    assert.ok((await form.locator('textarea').inputValue()).includes('Посмотрите'))
    await shot(client, kind + '-desktop-form', '.comment-form')
    await client.setViewportSize({ width: 390, height: 844 })
    await shot(client, kind + '-mobile-form', '.comment-form')
    assert.ok(await client.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
    const beforeMail = mail.length
    await postForm(client, null)
    await until(() => mail.length > beforeMail)
    const firstMail = mail.at(-1),
      id = commentId(firstMail)
    assert.ok(firstMail.includes('developer@example.test'))
    assert.ok(id && mailUrl(firstMail))
    assert.equal(firstMail.includes('/attachments/'), false)
    assert.equal(firstMail.includes('Content-Disposition: attachment'), false)
    await developer.goto(mailUrl(firstMail))
    await developer.locator('#comment-' + id).waitFor()
    const row = developer.locator('#comment-' + id)
    const imageRequest = developer.waitForResponse(
      (response) => response.url().endsWith('/original') && response.status() === 200,
    )
    await row.locator('.attachment-item').click()
    await imageRequest
    await developer.locator('dialog[open] .attachment-preview').waitFor()
    await shot(developer, kind + '-desktop-viewer', 'dialog[open]')
    await developer.setViewportSize({ width: 390, height: 844 })
    await shot(developer, kind + '-mobile-viewer', 'dialog[open]')
    assert.ok(await developer.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
    await developer.setViewportSize({ width: 1440, height: 1000 })
    await developer.locator('dialog[open] .image-zoom').click()
    assert.equal(
      await developer.locator('dialog[open] .image-zoom').getAttribute('aria-pressed'),
      'true',
    )
    const downloadEvent = developer.waitForEvent('download')
    await developer.locator('dialog[open] a').click()
    const download = await downloadEvent
    assert.equal(download.suggestedFilename(), screenshot.name)
    const downloaded = path.join(scratch, kind + '-download.png')
    await download.saveAs(downloaded)
    assert.deepEqual(await readFile(downloaded), screenshot.buffer)
    await developer.keyboard.press('Escape')
    await until(async () => (await developer.locator('dialog[open]').count()) === 0)
    await shot(developer, kind + '-desktop-comment', '#comment-' + id)
    await navigate(developer, developerPath)
    await developer.locator('.comment-form input[type=file]').setInputFiles({
      name: 'Рекомендации-по-расположению-описания-товара.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('Please review the updated product layout.\n'),
    })
    const replyMail = mail.length
    await postForm(developer, 'Обновил расположение. Прикрепляю рекомендации.')
    await until(() => mail.length > replyMail)
    assert.ok(mail.at(-1).includes('client@example.test'))
    const replyId = commentId(mail.at(-1))
    await client.goto(mailUrl(mail.at(-1)))
    await client.locator('#comment-' + replyId + ' .attachment-item').waitFor()
    const docDownload = client.waitForEvent('download')
    await client.locator('#comment-' + replyId + ' a.attachment-item').click()
    await (await docDownload).saveAs(path.join(scratch, kind + '-notes.txt'))
    assert.equal(
      await readFile(path.join(scratch, kind + '-notes.txt'), 'utf8'),
      'Please review the updated product layout.\n',
    )
    await shot(client, kind + '-mobile-comments', '#comment-' + replyId)
    // Paste through the actual system clipboard, then remove before sending.
    await form.locator('textarea').focus()
    await client.evaluate(async (base64) => {
      const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))
      await navigator.clipboard.write([
        new ClipboardItem({ 'image/png': new Blob([bytes], { type: 'image/png' }) }),
      ])
    }, png)
    await client.keyboard.press(process.platform === 'darwin' ? 'Meta+V' : 'Control+V')
    await until(async () => (await form.locator('.file-list li').count()) === 1)
    await form.locator('.file-list button').click()
    assert.equal(await form.locator('.file-list li').count(), 0)
    // Drag a document, lose response after server commit, retry same submission.
    await form.dispatchEvent('drop', {
      dataTransfer: await client.evaluateHandle(() => {
        const dt = new DataTransfer()
        dt.items.add(new File(['Drop content'], 'drop.txt', { type: 'text/plain' }))
        return dt
      }),
    })
    await until(async () => (await form.locator('.file-list li').count()) === 1)
    const retryMail = mail.length
    await client.route(
      endpoint,
      async (route) => {
        await route.fetch()
        await route.abort('failed')
      },
      { times: 1 },
    )
    await form.locator('[type=submit]').click()
    await until(async () => !(await form.locator('[type=submit]').isDisabled()))
    assert.equal(await form.locator('.file-list li').count(), 1)
    await postForm(client, '')
    await until(() => mail.length > retryMail)
    await wait(150)
    assert.equal(mail.length, retryMail + 1, 'Lost response retry sends one notification')
    results.push({
      kind,
      filePicker: true,
      pasteShortcut: true,
      dragDrop: true,
      remove: true,
      errorRetainsDraft: true,
      lostResponseIdempotency: true,
      developerEmail: true,
      clientEmail: true,
      deepLinks: true,
      originalDownload: true,
    })
  }
  // Scope: image paste in unrelated fields leaves the active composer unchanged.
  await client.locator('.comment-form textarea').fill('Plain text')
  await client
    .locator('.comment-form textarea')
    .evaluate((el) =>
      el.dispatchEvent(
        new ClipboardEvent('paste', { bubbles: true, clipboardData: new DataTransfer() }),
      ),
    )
  assert.equal(await client.locator('.comment-form textarea').inputValue(), 'Plain text')
  for (const width of [320, 360, 390, 1440])
    for (const lang of ['ru', 'en'])
      for (const theme of ['light', 'dark', 'system']) {
        await client.setViewportSize({ width, height: width < 500 ? 844 : 1000 })
        await client.evaluate(
          async ({ lang, theme: mode }) => {
            const { setManualLocale } = await import('/src/i18n/index.js')
            const { theme } = await import('/src/services/theme.js')
            setManualLocale(lang)
            theme.select(mode)
          },
          { lang, theme },
        )
        assert.equal(await client.locator('html').getAttribute('lang'), lang)
        assert.equal(
          await client.locator('html').getAttribute('data-theme'),
          theme === 'system' ? 'light' : theme,
        )
        assert.ok(
          await client.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
          `${width}/${lang}/${theme}`,
        )
        if (width === 320 && lang === 'en' && theme === 'dark')
          await shot(client, 'general-320-en-dark', '.comment-form')
      }
  const stats = JSON.parse(run(['tests/milestone49-fixture.php', 'stats']))
  assert.deepEqual(stats, { comments: 6, files: 6, deliveries: 6 })
  assert.deepEqual(errors, [])
  console.log(
    'PASS real Laravel E2E: task + general, bidirectional SMTP notifications, file chooser, clipboard shortcut, drop, retry, downloads, 24 viewport/locale/theme combinations',
  )
  results.push({
    responsiveConfigurations: 24,
    stats,
    webkit: 'Not installed; iOS Safari not run',
    android: 'Chromium mobile viewport; physical Android device not available',
  })
} catch (error) {
  console.error(error)
  console.error(backendLog)
  console.error(run(['tests/milestone49-fixture.php', 'stats']))
  process.exitCode = 1
  if (browser)
    for (const context of browser.contexts())
      for (const page of context.pages())
        await page
          .screenshot({
            path: new URL(
              'failure-' +
                context.pages().indexOf(page) +
                '-' +
                browser.contexts().indexOf(context) +
                '.png',
              output,
            ).pathname,
          })
          .catch(() => {})
} finally {
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify({ realLaravelApi: true, localSmtpTransport: true, results, errors }, null, 2) +
      '\n',
  )
  await browser?.close()
  backend?.kill()
  await vite.close()
  for (const socket of sockets) socket.destroy()
  await new Promise((resolve) => smtp.close(resolve))
  for (const id of privateDirectories)
    await rm(path.join(root, 'api/storage/app/private/attachments/comments', id), {
      recursive: true,
      force: true,
    })
  await rm(scratch, { recursive: true, force: true })
}
