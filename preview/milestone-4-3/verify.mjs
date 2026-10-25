import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = new URL('../../docs/milestone-4-3/', import.meta.url)
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const context = await browser.newContext({
  viewport: { width: 1440, height: 1120 },
  reducedMotion: 'reduce',
  colorScheme: 'light',
})
const page = await context.newPage()
const errors = [],
  apiRequests = [],
  unexpectedHosts = []
page.on('pageerror', (error) => errors.push(error.message))
page.on('request', (request) => {
  const url = new URL(request.url())
  if (/^\/(api|access|sanctum)(\/|$)/.test(url.pathname)) apiRequests.push(url.pathname)
  if (url.hostname !== '127.0.0.1') unexpectedHosts.push(url.hostname)
})
await context.addInitScript(() => {
  localStorage.setItem('projectbrief.theme', 'dark')
  localStorage.setItem('projectbrief.locale', 'en')
  localStorage.setItem('projectbrief-demo-isolation-sentinel', 'keep-me')
})
const storage = () => page.evaluate(() => JSON.stringify(Object.entries(localStorage).sort()))
async function fit() {
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    'viewport overflow',
  )
}
async function shot(name, fullPage = false) {
  await page.evaluate(() => scrollTo(0, 0))
  await page.screenshot({
    path: new URL(`${name}.png`, output).pathname,
    fullPage,
    animations: 'disabled',
  })
}
try {
  await page.goto('http://127.0.0.1:5183/')
  await page.getByRole('heading', { level: 1 }).waitFor()
  const originalStorage = await storage()
  assert.equal(
    await page.locator('html').getAttribute('data-theme'),
    'light',
    'preview ignores real theme preferences',
  )
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark')
  assert.equal(
    await page.locator('html').getAttribute('data-theme'),
    'dark',
    'system theme follows OS',
  )
  await page.locator('.preview-theme select').selectOption('light')
  await fit()
  await shot('desktop-light', true)
  await shot('hero-client')
  const heroTaskTitle = await page.locator('.task-detail h3').textContent()
  await page.getByRole('button', { name: 'Я разработчик', exact: true }).click()
  assert.equal(await page.locator('.task-detail h3').textContent(), heroTaskTitle)
  await page.getByText('Детали на месте. Можно начинать.').waitFor()
  await shot('hero-developer')
  await page.locator('.preview-theme select').selectOption('dark')
  await shot('desktop-dark', true)
  await page.locator('.preview-language select').selectOption('en')
  assert.equal(await page.locator('html').getAttribute('lang'), 'en')
  assert.match(await page.title(), /Easy for clients/)
  assert.equal(
    await page.locator('main').evaluate((el) => /[А-Яа-яЁё]/.test(el.textContent)),
    false,
    'English landing has no untranslated Russian',
  )
  await shot('desktop-en-dark')
  await page.locator('.preview-language select').selectOption('ru')
  await page.setViewportSize({ width: 390, height: 1040 })
  await page.getByRole('button', { name: 'Я клиент', exact: true }).click()
  await page.locator('.preview-theme select').selectOption('light')
  await fit()
  await shot('mobile-light', true)
  await shot('mobile-hero-light')
  await page.locator('.preview-theme select').selectOption('dark')
  await shot('mobile-dark', true)
  await shot('mobile-hero-dark')
  for (const language of ['ru', 'en']) {
    await page.locator('.preview-language select').selectOption(language)
    await page.setViewportSize({ width: 320, height: 900 })
    await fit()
  }
  await page.locator('.preview-language select').selectOption('ru')
  await page.setViewportSize({ width: 1440, height: 1120 })
  await page.locator('.preview-theme select').selectOption('light')
  await page.getByRole('link', { name: 'Попробовать демо', exact: true }).first().click()
  await page.waitForURL('**/demo')
  await fit()
  await shot('demo-desktop-light')
  await page.getByRole('button', { name: 'Я разработчик', exact: true }).click()
  await page.locator('.task-row').filter({ hasText: 'Показывать, что есть в наличии' }).click()
  assert.equal(await page.locator('.comment-row').count(), 2)
  await shot('demo-developer-light')
  await page.getByRole('button', { name: 'Готово', exact: true }).click()
  assert.equal(await page.locator('.task-list .task-row').count(), 3)
  await page.getByRole('button', { name: 'В работе', exact: true }).click()
  assert.equal(await page.locator('.task-list .task-row').count(), 1)
  await page.getByRole('button', { name: 'Все задачи', exact: true }).click()
  await page.getByRole('button', { name: 'Я клиент', exact: true }).click()
  await page.getByRole('button', { name: 'Посмотреть пример AI', exact: true }).click()
  await page.locator('.ai-example p').waitFor()
  await page.getByRole('button', { name: 'Добавить идею', exact: true }).click()
  await page.locator('#demo-title').fill('Тестовая идея')
  await page.locator('#demo-description').fill('Добавить понятный выбор размера.')
  await page.getByRole('button', { name: 'Добавить в демо', exact: true }).click()
  await page.getByRole('status').waitFor()
  assert.equal(await page.locator('.task-detail h3').textContent(), 'Тестовая идея')
  assert.equal(
    await page.getByRole('button', { name: 'Посмотреть пример AI', exact: true }).count(),
    0,
    'custom ideas must not receive a fabricated AI result',
  )
  assert.equal(
    await storage(),
    originalStorage,
    'demo interactions must not alter any persisted data',
  )
  await page.reload()
  await page.locator('.task-detail h3').waitFor()
  assert.equal(
    await page.locator('.task-list .task-row').count(),
    9,
    'added idea disappears after reload',
  )
  await page.locator('.preview-theme select').selectOption('dark')
  await shot('demo-desktop-dark')
  await page.locator('.preview-language select').selectOption('en')
  assert.equal(
    await page.locator('main').evaluate((el) => /[А-Яа-яЁё]/.test(el.textContent)),
    false,
    'English demo has no untranslated Russian',
  )
  await shot('demo-en-dark')
  await page.locator('.preview-language select').selectOption('ru')
  await page.setViewportSize({ width: 390, height: 1040 })
  await fit()
  await shot('demo-mobile-dark', true)
  await page.locator('.preview-theme select').selectOption('light')
  await shot('demo-mobile-light', true)
  await page.setViewportSize({ width: 320, height: 900 })
  await fit()
  // Native modal traps keyboard focus and Escape restores the opening control.
  await page.getByRole('button', { name: 'Добавить идею', exact: true }).click()
  assert.equal(
    await page.locator('#demo-title').evaluate((el) => el === document.activeElement),
    true,
  )
  await page.keyboard.press('Escape')
  assert.equal(await page.locator('dialog[open]').count(), 0)
  assert.equal(
    await page
      .getByRole('button', { name: 'Добавить идею', exact: true })
      .evaluate((el) => el === document.activeElement),
    true,
  )
  assert.equal(await storage(), originalStorage)
  assert.deepEqual(apiRequests, [], 'preview must never call application API or AI')
  assert.deepEqual(unexpectedHosts, [], 'preview must be self contained')
  assert.deepEqual(errors, [], 'no browser runtime errors')
  await writeFile(
    new URL('results.json', output),
    JSON.stringify(
      {
        status: 'passed',
        apiRequests,
        unexpectedHosts,
        browserErrors: errors,
        checks: [
          'RU/EN landing and demo',
          'Light/dark/system themes',
          '1440/390/320 layouts without overflow',
          'Role switch keeps the same task',
          'Task selection, filters and discussions',
          'Explicit prepared AI example',
          'In-memory idea disappears on reload',
          'No real session, project or appearance storage changes',
          'Direct /demo reload',
          'Native dialog focus and Escape',
          'No API, AI or external network calls',
        ],
      },
      null,
      2,
    ) + '\n',
  )
  console.log('Preview checks passed; screenshots saved to docs/milestone-4-3/')
} finally {
  await browser.close()
}
