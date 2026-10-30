import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { cp, mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises'
import { randomBytes } from 'node:crypto'
import { tmpdir } from 'node:os'
import path from 'node:path'
import net from 'node:net'
import { createServer } from 'vite'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const scratch = await mkdtemp(path.join(tmpdir(), 'brief-live-sync-editor-'))
const app = path.join(scratch, 'app'),
  output = path.join(tmpdir(), 'project-brief-agreement-editor-qa')
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
  origin = `http://127.0.0.1:${webPort}`
const env = {
  PATH: process.env.PATH,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_URL: origin,
  FRONTEND_URL: origin,
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(scratch, 'test.sqlite'),
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
  return result.stdout
}
let backend, frontend, browser
const errors = [],
  results = []
try {
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
  await symlink(path.join(root, 'api/vendor'), path.join(app, 'vendor'))
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
  await writeFile(env.DB_DATABASE, '')
  php(['artisan', 'migrate', '--force', '--no-interaction'])
  const fixture = JSON.parse(php(['tests/live-sync-fixture.php']))
  backend = spawn('php', ['-S', `127.0.0.1:${apiPort}`, '-t', 'public', 'public/index.php'], {
    cwd: app,
    env,
    stdio: 'ignore',
  })
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
  await frontend.listen()
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({
    viewport: { width: 1440, height: 980 },
    reducedMotion: 'reduce',
  })
  await context.addInitScript(() => {
    localStorage.setItem('projectbrief.locale', 'en')
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
  await call('/api/security/challenge', 'POST', { recovery_code: 'Live-browser-code-0' })
  const settings95 = await call('/api/admin/settings/agreement')
  await call('/api/admin/settings/agreement', 'PUT', {
    enabled: false,
    publish: false,
    requireAgain: false,
    baseRevision: null,
    draft: {
      ...settings95.data.draft,
      enabledLocales: ['ru', 'en', 'tr', 'zh-CN'],
      defaultLocale: 'ru',
    },
  })
  await page.goto(origin + '/admin/settings/agreement')
  const editor = page.locator('.editor-content'),
    dialog = page.locator('.agreement-link-dialog')
  await editor.waitFor()
  assert.equal(await page.locator('details.management-section').count(), 0)
  assert.equal(await page.locator('.editor-tools button').count(), 9)
  const tools = (name) => page.locator('.editor-tools').getByRole('button', { name, exact: true })
  async function select(text, collapsed = false) {
    await editor.evaluate(
      (root, { text, collapsed }) => {
        root.focus()
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
        let node
        while ((node = walker.nextNode()))
          if (node.textContent.includes(text)) {
            const start = node.textContent.indexOf(text),
              range = document.createRange()
            range.setStart(node, start + (collapsed ? 1 : 0))
            range.setEnd(node, collapsed ? start + 1 : start + text.length)
            const selection = window.getSelection()
            selection.removeAllRanges()
            selection.addRange(range)
            return
          }
        throw new Error('Selection text unavailable')
      },
      { text, collapsed },
    )
    await page.waitForTimeout(30)
  }
  const undo = process.platform === 'darwin' ? 'Meta+z' : 'Control+z'
  const redo = process.platform === 'darwin' ? 'Meta+Shift+z' : 'Control+Shift+z'
  await editor.fill('Agreement ru: selected text and unchanged ending.')
  await select('selected text')
  await tools('Heading').click()
  await editor.locator('h1').waitFor()
  assert.equal(await tools('Heading').getAttribute('aria-pressed'), 'true')
  await tools('Subheading').click()
  await editor.locator('h2').waitFor()
  await tools('Paragraph').click()
  await editor.locator('p').waitFor()
  await select('selected text')
  await tools('Bold').click()
  assert.equal(await tools('Bold').getAttribute('aria-pressed'), 'true')
  await tools('Insert link').click()
  await dialog.waitFor({ state: 'visible' })
  assert.equal(await dialog.getByLabel('Link text', { exact: true }).inputValue(), 'selected text')
  await dialog.getByLabel('URL', { exact: true }).fill('javascript:alert(1)')
  await dialog.getByRole('button', { name: 'Insert', exact: true }).click()
  await dialog.locator('[role=alert]').waitFor()
  assert.equal(await editor.locator('a').count(), 0)
  await dialog.getByLabel('URL', { exact: true }).fill('https://example.test/rules?a=1&b=2')
  await dialog.getByRole('button', { name: 'Insert', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  assert.equal(await editor.locator('a').textContent(), 'selected text')
  assert.equal(await editor.locator('a').getAttribute('rel'), 'noopener noreferrer')
  assert.ok(
    await editor
      .locator('a')
      .evaluate((node) => !!node.closest('b,strong') || !!node.querySelector('b,strong')),
  )
  await page.keyboard.press(undo)
  await editor.locator('a').waitFor({ state: 'detached' })
  await page.keyboard.press(redo)
  await editor.locator('a').waitFor()
  await select('selected text', true)
  await tools('Insert link').click()
  assert.equal(
    await dialog.getByLabel('URL', { exact: true }).inputValue(),
    'https://example.test/rules?a=1&b=2',
  )
  await dialog.getByLabel('URL', { exact: true }).fill('http://example.test/updated')
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  assert.equal(await editor.locator('a').getAttribute('href'), 'http://example.test/updated')
  await page.keyboard.press(undo)
  assert.equal(await editor.locator('a').getAttribute('href'), 'https://example.test/rules?a=1&b=2')
  await page.keyboard.press(redo)
  assert.equal(await editor.locator('a').getAttribute('href'), 'http://example.test/updated')
  await select('selected text', true)
  await tools('Remove link').click()
  assert.equal(await editor.locator('a').count(), 0)
  await page.keyboard.press(undo)
  await editor.locator('a').waitFor()
  await select('selected text', true)
  await tools('Insert link').click()
  await dialog.getByLabel('Link text', { exact: true }).fill('Updated link')
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  assert.equal(await editor.locator('a').count(), 1)
  assert.equal(await editor.locator('a').textContent(), 'Updated link')
  await page.keyboard.press(undo)
  assert.equal(await editor.locator('a').textContent(), 'selected text')
  await page.keyboard.press(redo)
  assert.equal(await editor.locator('a').textContent(), 'Updated link')
  results.push(
    'Native editing: H1/H2/paragraph, bold active state, preserved selection, unsafe URL refusal, insert/edit/remove links and native Undo/Redo including renamed link',
  )
  for (const locale of ['en', 'tr', 'zh-CN']) {
    await page.locator('#agreement-tab-' + locale).click()
    await editor.fill(`Agreement ${locale}: plain <img src=x onerror=alert(1)>`)
    if (['en', 'tr'].includes(locale)) {
      await select('plain')
      await tools(locale === 'en' ? 'Bullet list' : 'Numbered list').click()
      await editor.evaluate((root) => {
        root.focus()
        const range = document.createRange()
        range.selectNodeContents(root.querySelector('li'))
        range.collapse(false)
        const selection = window.getSelection()
        selection.removeAllRanges()
        selection.addRange(range)
      })
      await editor.press('Enter')
      await editor.pressSequentially('Second item')
    }
    await select('plain')
    await tools('Italic').click()
    await tools('Insert link').click()
    await dialog.getByLabel('URL', { exact: true }).fill('https://example.test/' + locale)
    await dialog.getByRole('button', { name: 'Insert', exact: true }).click()
    await dialog.waitFor({ state: 'hidden' })
  }
  await page.locator('#agreement-tab-tr').click()
  assert.equal(await editor.locator('ol').count(), 1)
  assert.equal(await editor.locator('ol > li').count(), 2)
  await page.locator('#agreement-tab-en').click()
  assert.equal(await editor.locator('ul > li').count(), 2)
  await page.locator('#agreement-tab-ru').click()
  assert.equal(await editor.locator('a').textContent(), 'Updated link')
  assert.equal(await editor.locator('a').getAttribute('href'), 'http://example.test/updated')
  for (const locale of ['ru', 'en', 'tr', 'zh-CN'])
    for (const width of [1440, 390])
      for (const mode of ['light', 'dark']) {
        await page.setViewportSize({ width, height: 980 })
        await page.evaluate(
          async ({ locale, mode }) => {
            ;(await import('/src/i18n/index.js')).setManualLocale(locale)
            ;(await import('/src/services/theme.js')).theme.select(mode)
          },
          { locale, mode },
        )
        await page.waitForTimeout(180)
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1))
        for (const button of await page.locator('.editor-tools button').all()) {
          assert.ok(await button.getAttribute('aria-label'))
          assert.ok(!(await button.getAttribute('title')).startsWith('agreement.'))
        }
        await page.screenshot({
          path: path.join(output, `editor-${locale}-${width}-${mode}.png`),
          fullPage: true,
        })
        await select('Updated link', true)
        await page.locator('.editor-tools button').nth(7).click()
        await dialog.waitFor({ state: 'visible' })
        assert.ok(await dialog.evaluate((node) => node.getBoundingClientRect().right <= innerWidth))
        await page.screenshot({ path: path.join(output, `link-${locale}-${width}-${mode}.png`) })
        await page.keyboard.press('Escape')
        await dialog.waitFor({ state: 'hidden' })
      }
  await page.setViewportSize({ width: 1440, height: 980 })
  await page.evaluate(async () => (await import('/src/i18n/index.js')).setManualLocale('en'))
  await page.locator('input[type=date]').fill('2026-10-30')
  await page.locator('.agreement-option input').first().check()
  await page.getByRole('button', { name: 'Save and publish', exact: true }).click()
  await page.locator('[role=status]').filter({ hasText: 'Saved' }).waitFor()
  const saved = await call('/api/admin/settings/agreement')
  assert.equal(
    saved.data.published.texts.ru[0].content.find((span) => span.href)?.href,
    'http://example.test/updated',
  )
  await page.reload()
  await editor.locator('a').waitFor()
  assert.equal(await editor.locator('a').textContent(), 'Updated link')
  const guest = await browser.newContext(),
    publicPage = await guest.newPage()
  await publicPage.goto(origin + '/w/' + fixture.workspace + '/agreement?lang=ru')
  await publicPage.locator('.agreement-document a').waitFor()
  assert.equal(
    await publicPage.locator('.agreement-document a').getAttribute('rel'),
    'noopener noreferrer',
  )
  assert.equal(await publicPage.locator('.agreement-document a').textContent(), 'Updated link')
  const invitation = await call('/api/admin/administrators/invitations', 'POST', {
    email: 'editor-invited@example.test',
    sendEmail: false,
    generateLink: true,
  })
  await publicPage.goto(invitation.link)
  await publicPage.locator('.invitation-entry-actions button').first().click()
  await publicPage.locator('#invited-name').fill('Editor invited')
  await publicPage.locator('#invited-password').fill('Editor-invited-password!')
  await publicPage.locator('#invited-confirmation').fill('Editor-invited-password!')
  await publicPage.locator('form button[type=submit]').click()
  await publicPage.waitForURL('**/admin/agreement')
  await publicPage.locator('.agreement-document a').waitFor()
  assert.equal(await publicPage.locator('.admin-sidebar').count(), 0)
  assert.equal(await publicPage.locator('.agreement-document a').getAttribute('target'), '_blank')
  await guest.close()
  results.push(
    'Four document languages retain drafts across tabs; thirty-two desktop/mobile light/dark/localized editor captures; save/reload/public document/invited acceptance screen preserve safe links and formatting',
  )
  assert.deepEqual(errors, [])
  await writeFile(
    path.join(output, 'results.json'),
    JSON.stringify({ passed: true, results, screenshots: 32, errors }, null, 2) + '\n',
  )
  console.log(JSON.stringify({ passed: results.length, screenshots: 32, errors }))
} catch (error) {
  console.error(error)
  throw error
} finally {
  await browser?.close()
  await frontend?.close()
  backend?.kill()
  await rm(scratch, { recursive: true, force: true })
}
