// Uses an existing Playwright installation; no application dependency is needed.
// PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs npm run test:appearance
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer, preview } from 'vite'
import { createDemoData } from '../src/data/demo.js'
import { notificationEvents } from '../src/services/notificationSettings.js'
import { allProjectPermissions } from './helpers/projectPermissions.js'

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5182, strictPort: true },
  logLevel: 'error',
})
const directory = new URL(
  process.env.APPEARANCE_OUTPUT_DIR || '../docs/milestone-4-2/',
  import.meta.url,
)
await mkdir(directory, { recursive: true })
const results = []
let browser, production
const projectId = '11111111-1111-4111-8111-111111111111'
const data = createDemoData()
const project = {
  ...data.project,
  permissions: { ...allProjectPermissions },
  id: projectId,
  sections: data.sections,
  status: 'active',
  tasksCount: 9,
  doneCount: 3,
}
const user = {
  id: 'admin',
  name: 'Alex Morgan',
  email: 'alex@example.test',
  membershipRole: 'owner',
}
const client = { id: 'client', name: 'Анна', preferredLocale: 'ru' }
const workspace = { id: 'studio', name: 'Studio workspace' }
let root = `http://127.0.0.1:5182`
const clientPath = `/project/${projectId}`
const adminPath = `/admin/projects/${projectId}`

async function fixture(options = {}) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1050 },
    locale: 'en-GB',
    colorScheme: 'light',
    reducedMotion: 'reduce',
    ...options,
  })
  context.setDefaultTimeout(10000)
  context.setDefaultNavigationTimeout(10000)
  const calls = [],
    errors = []
  await context.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request(),
        path = new URL(request.url()).pathname
      const body = request.postDataJSON()
      calls.push({ path, method: request.method(), body })
      let payload
      if (path === '/api/csrf') payload = { token: 'test-csrf' }
      else if (path.endsWith('/me/locale')) payload = { preferredLocale: body.locale }
      else if (path === '/api/client/me') payload = client
      else if (path === '/api/admin/me') payload = { user, workspace }
      else if (path === '/api/admin/workspaces') payload = { data: [workspace] }
      else if (path === '/api/admin/projects') payload = { data: [project] }
      else if (path.endsWith('/activity/read') || path.endsWith('/discussions/read'))
        payload = { unreadCount: 0 }
      else if (path.endsWith('/push/status')) payload = { available: false }
      else if (path.endsWith('/telegram')) payload = { available: false, connected: false }
      else if (path.endsWith('/notifications'))
        payload = {
          notificationEmail: 'studio@example.test',
          events: Object.fromEntries(
            notificationEvents.map((event) => [event, { email: true, push: true }]),
          ),
        }
      else if (path.endsWith('/ai/task-assistant'))
        payload = {
          title: 'Удобный каталог товаров',
          description: 'Добавить понятный поиск товаров и крупные фотографии.',
          expectedResult: '',
          questions: ['Какие фильтры нужны?'],
          suggestedIdeas: [
            {
              title: 'Крупные фотографии',
              description: 'Увеличить изображения товаров.',
              expectedResult: '',
            },
          ],
        }
      else if (path.endsWith('/tasks')) payload = { data: data.tasks }
      else if (path.endsWith('/clients'))
        payload = {
          data: [{ ...client, email: 'anna@example.test', active: true, accessLinks: [] }],
        }
      else if (path.endsWith('/sections')) payload = { data: data.sections }
      else if (
        path === `/api/admin/projects/${projectId}` ||
        path === `/api/client/project/${projectId}`
      )
        payload = { data: project }
      else throw new Error(`Unexpected API request: ${path}`)
      await route.fulfill({ json: payload })
    },
  )
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  return { context, page, calls, errors }
}
async function open(page, path) {
  await page.goto(root + path)
  await page.locator('.theme-switcher select').waitFor()
  await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
  await page.locator('h1').first().waitFor()
}
async function screenshot(page, name) {
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.screenshot({
    path: new URL(name + '.png', directory).pathname,
    fullPage: (await page.locator('dialog[open]').count()) === 0,
    animations: 'disabled',
  })
}
async function fit(page) {
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    true,
    'page must fit the viewport',
  )
  for (const selector of ['.theme-switcher select', '.language-switcher select']) {
    const box = await page.locator(selector).boundingBox()
    assert.ok(
      box && box.height >= 44 && box.x >= 0 && box.x + box.width <= page.viewportSize().width,
      `${selector} must be visible, touchable and inside viewport`,
    )
  }
}
async function theme(page, value) {
  await page.locator('.theme-switcher select').selectOption(value)
  await page.waitForFunction((value) => document.documentElement.dataset.theme === value, value)
}
async function checkContrast(page) {
  const failures = await page.evaluate(() => {
    const rgb = (color) =>
      color
        .match(/[\d.]+/g)
        .slice(0, 3)
        .map((value) => Number(value) * (color.startsWith('color(srgb ') ? 255 : 1))
    const luminance = (color) =>
      rgb(color)
        .map((v) => {
          v /= 255
          return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
        })
        .reduce((sum, v, index) => sum + v * [0.2126, 0.7152, 0.0722][index], 0)
    const failures = []
    for (const element of document.querySelectorAll(
      '.status-badge, .priority-label, .field-error, .button, .ai-button, .muted, .sidebar-project.active',
    )) {
      if (
        !element.checkVisibility() ||
        element.disabled ||
        element.getAttribute('aria-disabled') === 'true' ||
        element.closest('.is-done')
      )
        continue
      const style = getComputedStyle(element)
      let parent = element,
        background
      while (parent) {
        background = getComputedStyle(parent).backgroundColor
        if (!background.startsWith('rgba') && background !== 'transparent') break
        parent = parent.parentElement
      }
      if (!parent) continue
      const a = luminance(style.color),
        b = luminance(background)
      const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
      if (ratio < 4.5)
        failures.push({
          text: element.textContent.trim().slice(0, 60),
          ratio,
          foreground: style.color,
          background,
        })
    }
    return failures
  })
  assert.deepEqual(failures, [], 'representative text and action colors must meet 4.5:1')
}

try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  const fixtureState = await fixture({ colorScheme: 'dark' })
  const { page, context, calls, errors } = fixtureState
  await open(page, clientPath)
  assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark')
  assert.equal(await page.locator('.theme-switcher select').inputValue(), 'system')
  assert.equal(await page.evaluate(() => localStorage.getItem('projectbrief.theme')), null)
  await page.emulateMedia({ colorScheme: 'light' })
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'light')
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark')
  const state = () =>
    page.evaluate(async () =>
      JSON.stringify((await import('/src/stores/project.js')).useProjectStore().$state),
    )
  const snapshot = await state()
  const mutations = () => calls.filter(({ method }) => method !== 'GET').length
  const count = mutations()
  await theme(page, 'light')
  await page.emulateMedia({ colorScheme: 'light' })
  await page.emulateMedia({ colorScheme: 'dark' })
  assert.equal(await page.locator('html').getAttribute('data-theme'), 'light')
  assert.equal(await state(), snapshot)
  assert.equal(mutations(), count)
  await page.reload()
  await page.locator('h1').waitFor()
  await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
  assert.equal(await page.locator('.theme-switcher select').inputValue(), 'light')
  assert.equal(await page.locator('html').getAttribute('data-theme'), 'light')
  // Native controls are reachable in tab order and expose translated accessible names.
  const themeControl = page.getByRole('combobox', { name: 'Тема оформления' })
  await themeControl.focus()
  assert.equal(await themeControl.evaluate((element) => element === document.activeElement), true)
  await page.keyboard.press('Tab')
  assert.equal(
    await page
      .locator('.language-switcher select')
      .evaluate((element) => element === document.activeElement),
    true,
  )
  await page.keyboard.press('Shift+Tab')
  assert.equal(await themeControl.evaluate((element) => element === document.activeElement), true)
  await theme(page, 'dark')
  results.push(
    'Default system mode, OS changes, manual override, persistence, keyboard access and project/API isolation',
  )

  const languageControl = page.getByRole('combobox', { name: 'Язык' })
  await languageControl.selectOption('en')
  await page.waitForFunction(() => document.documentElement.lang === 'en')
  await page.waitForFunction(
    async () =>
      (await import('/src/stores/project.js')).useProjectStore().currentClient.preferredLocale ===
      'en',
  )
  assert.deepEqual(calls.filter(({ path }) => path.endsWith('/me/locale')).at(-1), {
    path: '/api/client/me/locale',
    method: 'PATCH',
    body: { locale: 'en' },
  })
  assert.equal(
    await page
      .locator('.language-switcher option')
      .allTextContents()
      .then((items) => items.map((item) => item.trim()).join('|')),
    'Русский|English',
  )
  assert.equal(await page.evaluate(() => localStorage.getItem('projectbrief.locale')), 'en')
  await page.reload()
  await page.locator('h1').waitFor()
  await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
  assert.equal(await page.locator('html').getAttribute('lang'), 'en')
  await page.locator('.language-switcher select').selectOption('ru')
  results.push(
    'Real language select, native names, immediate HTML lang, persisted manual override and current-client PATCH',
  )

  await open(page, `${clientPath}/task/new`)
  assert.equal(await page.locator('.field-label-row .ai-button').count(), 1)
  const aiButton = page.locator('.ai-button')
  const aiRequests = () => calls.filter(({ path }) => path.endsWith('/ai/task-assistant'))
  for (const language of ['ru', 'en']) {
    await page.locator('.language-switcher select').selectOption(language)
    await page.locator('#idea-title').fill('Title alone is insufficient')
    await page.locator('#idea-description').fill('   ')
    assert.equal(await aiButton.getAttribute('aria-disabled'), 'true')
    await aiButton.focus()
    const hint = page.getByRole('tooltip')
    assert.equal(
      await hint.textContent(),
      language === 'ru' ? 'Сначала опишите идею' : 'Describe your idea first',
    )
    assert.equal(await hint.isVisible(), true)
    await aiButton.press('Enter')
    await aiButton.click({ force: true })
    assert.equal(await page.locator('dialog[open]').count(), 0)
    assert.equal(aiRequests().length, 0)
  }
  await page.locator('#idea-title').fill('')
  await page.locator('#idea-description').fill('Хочу быстрее находить товары')
  assert.equal(await aiButton.getAttribute('aria-disabled'), 'false')
  assert.equal(
    await aiButton.locator('svg').evaluate((element) => getComputedStyle(element).animationName),
    'none',
  )
  await page.emulateMedia({ reducedMotion: 'no-preference' })
  assert.equal(
    await aiButton.locator('svg').evaluate((element) => getComputedStyle(element).animationName),
    'ai-sparkle',
  )
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await aiButton.click()
  assert.equal(aiRequests().length, 0, 'opening must not send data')
  await page.getByRole('button', { name: 'Improve description', exact: true }).click()
  await page.locator('#ai-title').waitFor()
  assert.equal(aiRequests().length, 1)
  assert.equal(aiRequests()[0].body.title, '')
  assert.equal(aiRequests()[0].body.consent, true)
  assert.equal(await page.locator('#idea-title').inputValue(), '')
  await page.locator('#ai-title').fill('Проверенный вариант')
  const beforeApply = mutations()
  await page.getByRole('button', { name: 'Apply to task', exact: true }).click()
  assert.equal(await page.locator('dialog[open]').count(), 0)
  assert.equal(await page.locator('#idea-title').inputValue(), 'Проверенный вариант')
  assert.equal(mutations(), beforeApply, 'applying must not save the task')
  await page.locator('.language-switcher select').selectOption('ru')
  results.push(
    'Existing AI button only: empty-description guard, RU/EN focus tooltip, description-only opening, reduced motion, explicit consent and editable local-only application',
  )

  // Delay a synthetic request to verify pending/close behavior without any live AI calls.
  await open(page, `${clientPath}/task/new`)
  await page.locator('#idea-description').fill('Original draft')
  const aiUrl = '**/api/client/project/*/ai/task-assistant'
  let release,
    received = 0
  const held = new Promise((resolve) => {
    release = resolve
  })
  await page.route(aiUrl, async (route) => {
    received++
    await page.evaluate(() => {
      window.__aiRequestReceived = true
    })
    await held
    await route.fulfill({
      json: {
        title: 'Proposal',
        description: 'Proposal text',
        expectedResult: '',
        questions: [],
        suggestedIdeas: [],
      },
    })
  })
  await page.locator('.ai-button').click()
  await page.locator('dialog[open] .modal-footer .primary').click()
  await page.locator('.ai-working').waitFor()
  assert.equal(await page.locator('.ai-button').isDisabled(), true)
  assert.equal(await page.locator('dialog[open] .modal-footer .primary').isDisabled(), true)
  await page.locator('dialog[open] .modal-footer .primary').click({ force: true })
  await page.waitForFunction(() => window.__aiRequestReceived === true)
  assert.equal(received, 1)
  await page.locator('dialog[open] .modal-header button').click()
  await page.locator('#idea-description').fill('Draft edited while AI finishes')
  release()
  await page.waitForFunction(() => !document.querySelector('.ai-button').disabled)
  assert.equal(await page.locator('dialog[open]').count(), 0)
  assert.equal(
    await page.locator('#idea-description').inputValue(),
    'Draft edited while AI finishes',
  )
  await page.unroute(aiUrl)
  await page.route(aiUrl, (route) =>
    route.fulfill({
      status: 503,
      json: { code: 'ai_unavailable', message: 'Private provider debug detail' },
    }),
  )
  await page.locator('.ai-button').click()
  await page.locator('dialog[open] .modal-footer .primary').click()
  await page.locator('dialog[open] .field-error').waitFor()
  assert.equal(
    (await page.locator('dialog[open]').textContent()).includes('Private provider debug detail'),
    false,
  )
  await page.locator('dialog[open] .modal-header button').click()
  assert.equal(
    await page.locator('#idea-description').inputValue(),
    'Draft edited while AI finishes',
  )
  await page.unroute(aiUrl)
  results.push(
    'AI pending state prevents duplicate requests; closing allows draft editing, late responses are discarded and errors preserve text without technical details',
  )

  for (const [size, viewport] of [
    ['desktop', { width: 1440, height: 1050 }],
    ['mobile', { width: 390, height: 844 }],
  ]) {
    await page.setViewportSize(viewport)
    for (const mode of ['light', 'dark']) {
      await open(page, clientPath)
      await theme(page, mode)
      await fit(page)
      await checkContrast(page)
      await screenshot(page, `client-${size}-${mode}`)
      await open(page, `${clientPath}/task/demo-5`)
      await page.locator('.history-panel summary').click()
      await checkContrast(page)
      await screenshot(page, `task-${size}-${mode}`)
      await open(page, `${clientPath}/task/new`)
      await page.locator('#idea-title').fill('Улучшить каталог')
      await page
        .locator('#idea-description')
        .fill('Хочу быстро находить нужные товары и видеть крупные фотографии.')
      await checkContrast(page)
      await screenshot(page, `form-${size}-${mode}`)
      await page.locator('.ai-button').click()
      await page.locator('#ai-dialog-title').waitFor()
      await screenshot(page, `ai-${size}-${mode}`)
      await page.locator('dialog[open] .modal-footer .primary').click()
      await page.locator('#ai-title').waitFor()
      const footer = await page.locator('dialog[open] .modal-footer').boundingBox()
      assert.ok(
        footer.y >= 0 && footer.y + footer.height <= viewport.height,
        'AI actions remain visible with a long proposal',
      )
      assert.equal(await page.locator('#ai-result').inputValue(), '')
      await screenshot(page, `ai-ready-${size}-${mode}`)
      await page.locator('dialog[open] .modal-header button').click()
      await open(page, `${clientPath}/task/demo-4/edit`)
      await fit(page)
      assert.equal(
        await page.locator('#idea-title').inputValue(),
        data.tasks.find((task) => task.id === 'demo-4').title,
      )
      await open(page, `${adminPath}/brief`)
      await fit(page)
      await checkContrast(page)
      await screenshot(page, `admin-${size}-${mode}`)
      if (size === 'mobile') {
        await page.locator('.admin-menu-trigger').click()
        await page.locator('#admin-drawer[open]').waitFor()
        await screenshot(page, `sidebar-${size}-${mode}`)
        await page.keyboard.press('Escape')
      }
      await open(page, `${adminPath}/settings`)
      await checkContrast(page)
      await screenshot(page, `settings-${size}-${mode}`)
      await open(page, `${adminPath}/export`)
      await checkContrast(page)
      await screenshot(page, `export-${size}-${mode}`)
      await open(page, `${adminPath}/notifications`)
      await page.locator('.notification-settings h2').waitFor()
      await page.locator('.notification-settings input[type="checkbox"]').first().waitFor()
      await checkContrast(page)
      await screenshot(page, `notifications-${size}-${mode}`)
      await page.evaluate(async () => {
        const { toast } = await import('/src/services/toast.js')
        toast.success('Настройки сохранены')
        toast.warning('Проверьте соединение')
        toast.error('Не удалось сохранить. Попробуйте снова.')
      })
      await screenshot(page, `toasts-${size}-${mode}`)
      await page.evaluate(async () => (await import('/src/services/toast.js')).toast.clear())
      await open(page, '/admin/login')
      await fit(page)
      await checkContrast(page)
      await screenshot(page, `login-${size}-${mode}`)
      results.push(
        `${size} ${mode}: project, task/comments/history, create/edit, AI dialog, admin/sidebar, settings, export, notifications/toasts, login; no overflow, touch controls and text contrast`,
      )
    }
  }
  for (const width of [320, 360]) {
    await page.setViewportSize({ width, height: 800 })
    for (const path of [clientPath, `${adminPath}/brief`]) {
      await open(page, path)
      for (const lang of ['en', 'ru']) {
        await page.locator('.language-switcher select').selectOption(lang)
        await fit(page)
        await theme(page, 'dark')
        await theme(page, 'light')
      }
    }
  }
  assert.deepEqual(errors, [])
  await context.close()
  results.push(
    'Both selectors work at 320, 360 and 390px in RU/EN without horizontal overflow; no runtime errors',
  )

  production = await preview({ preview: { host: '127.0.0.1', port: 5183, strictPort: true } })
  root = 'http://127.0.0.1:5183'
  for (const failure of ['corrupt', 'blocked', 'write-only']) {
    const { context, page, errors } = await fixture({ colorScheme: 'dark' })
    await context.addInitScript((failure) => {
      if (failure === 'corrupt') {
        localStorage.setItem('projectbrief.theme', '{broken')
        localStorage.setItem('projectbrief.locale', 'de')
      } else if (failure === 'blocked')
        Object.defineProperty(window, 'localStorage', {
          get() {
            throw new Error('SecurityError')
          },
        })
      else
        Storage.prototype.setItem = () => {
          throw new Error('QuotaExceededError')
        }
    }, failure)
    try {
      await open(page, '/admin/login')
    } catch (error) {
      console.error('Storage test:', failure, errors)
      throw error
    }
    assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark')
    assert.equal(await page.locator('html').getAttribute('lang'), 'en')
    await theme(page, 'light')
    await page.locator('.language-switcher select').selectOption('ru')
    assert.equal(await page.locator('html').getAttribute('lang'), 'ru')
    assert.deepEqual(errors, [])
    await context.close()
  }
  results.push(
    'Corrupt, getter-blocked and quota-blocked localStorage: fallback and live selectors remain usable',
  )

  root = 'http://127.0.0.1:5182'
  const { context: splashContext, page: splash } = await fixture({ colorScheme: 'dark' })
  await splash.route('**/src/main.js*', (route) => route.abort())
  await splash.goto(root + '/admin/login')
  assert.equal(await splash.locator('html').getAttribute('data-theme'), 'dark')
  assert.equal(
    await splash
      .locator('#bootstrap-splash')
      .evaluate((element) => getComputedStyle(element).backgroundColor),
    'rgb(21, 25, 31)',
  )
  assert.equal(await splash.locator('meta[name="theme-color"]').getAttribute('content'), '#15191f')
  await screenshot(splash, 'splash-dark')
  await splashContext.close()
  results.push(
    'Dark splash, native color scheme and theme-color apply while the Vue entry is blocked',
  )
  await writeFile(
    new URL('results.json', directory),
    JSON.stringify({ results, passed: results.length }, null, 2) + '\n',
  )
  console.log(JSON.stringify({ passed: results.length, results }, null, 2))
} finally {
  await browser?.close()
  await server.close()
  await new Promise((resolve) => (production ? production.httpServer.close(resolve) : resolve()))
}
