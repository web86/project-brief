// PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs npm run test:branding
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { createDemoData } from '../src/data/demo.js'

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const baseline = process.env.BRANDING_BASELINE === '1'
const directory = new URL(
  process.env.BRANDING_OUTPUT_DIR || '../docs/milestone-4-9-1/screenshots/',
  import.meta.url,
)
await mkdir(directory, { recursive: true })
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5187, strictPort: true },
  logLevel: 'error',
})
const root = 'http://127.0.0.1:5187'
const id = '11111111-1111-4111-8111-111111111111'
const demo = createDemoData()
const project = { ...demo.project, id, sections: demo.sections, status: 'active' }
const results = []
const runtimeErrors = []
let browser

async function fixture({ mode = 'system', language = 'ru', guest = false, ...options } = {}) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    colorScheme: 'light',
    reducedMotion: 'reduce',
    locale: language,
    ...options,
  })
  await context.addInitScript(
    ({ mode, language }) => {
      if (mode !== null) localStorage.setItem('projectbrief.theme', mode)
      localStorage.setItem('projectbrief.locale', language)
    },
    { mode, language },
  )
  await context.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const path = new URL(route.request().url()).pathname
      let json = { data: [] }
      if (guest && ['/api/session', '/api/admin/me', '/api/client/me'].includes(path)) {
        await route.fulfill({ status: 401, json: { message: 'Unauthenticated' } })
        return
      }
      if (path === '/api/csrf') json = { token: 'fixture' }
      else if (path === '/api/session') json = { persona: 'guest' }
      else if (path.endsWith('/push/status')) json = { available: false }
      else if (path === '/api/admin/me')
        json = {
          user: {
            id: 'admin',
            name: 'Alex Morgan',
            email: 'alex@example.test',
            membershipRole: 'owner',
          },
          workspace: { id: 'studio', name: 'Studio workspace' },
        }
      else if (path === '/api/client/me')
        json = { id: 'client', name: 'Анна', preferredLocale: 'ru' }
      else if (path === '/api/admin/workspaces')
        json = { data: [{ id: 'studio', name: 'Studio workspace' }] }
      else if (path === '/api/admin/projects/trash') json = { data: [] }
      else if (path === '/api/admin/projects') json = { data: [project] }
      else if (path.endsWith('/feedback'))
        json = {
          eligible: false,
          requested: false,
          shown: false,
          dismissed: false,
          submitted: false,
        }
      else if (path.endsWith('/tasks')) json = { data: demo.tasks }
      else if (path.endsWith('/sections')) json = { data: demo.sections }
      else if (path === `/api/admin/projects/${id}` || path === `/api/client/project/${id}`)
        json = { data: project }
      else if (path.endsWith('/me/locale'))
        json = { preferredLocale: route.request().postDataJSON().locale }
      await route.fulfill({ json })
    },
  )
  const page = await context.newPage()
  page.setDefaultTimeout(10000)
  page.on('pageerror', (error) => runtimeErrors.push(error.message))
  return { context, page }
}
async function screenshot(page, name) {
  await page.screenshot({
    path: new URL(`${baseline ? 'before' : 'after'}-${name}.png`, directory).pathname,
    animations: 'disabled',
  })
}
async function ready(page, path) {
  await page.goto(root + path)
  await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
  await page.locator('h1').first().waitFor()
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
}

try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const width of [1440, 390, 320]) {
    for (const mode of ['light', 'dark']) {
      for (const language of baseline ? [width === 320 ? 'en' : 'ru'] : ['ru', 'en']) {
        const { context, page } = await fixture({
          mode,
          language,
          viewport: { width, height: width === 1440 ? 1000 : 844 },
        })
        let release
        const held = new Promise((resolve) => {
          release = resolve
        })
        await page.route('**/src/main.js*', async (route) => {
          await held
          await route.continue()
        })
        await page.goto(root + '/admin', { waitUntil: 'commit' })
        await page.locator('#bootstrap-splash').waitFor()
        await screenshot(page, `splash-${width}-${mode}-${language}`)
        if (!baseline) {
          assert.equal(await page.locator('#app').innerHTML(), '')
          assert.equal(
            await page
              .locator('#bootstrap-splash')
              .evaluate((e) => getComputedStyle(e).backgroundColor),
            mode === 'dark' ? 'rgb(21, 25, 31)' : 'rgb(247, 248, 250)',
          )
          assert.equal(
            await page.locator('meta[name="theme-color"]').getAttribute('content'),
            mode === 'dark' ? '#15191f' : '#f7f8fa',
          )
          assert.equal(
            await page.locator('#bootstrap-label').textContent(),
            language === 'ru' ? 'Загрузка…' : 'Loading…',
          )
          assert.equal(
            await page
              .locator('.bootstrap-dots span')
              .first()
              .evaluate((e) => getComputedStyle(e).animationName),
            'none',
          )
        }
        release()
        await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
        await page.locator('h1').first().waitFor()
        await screenshot(page, `admin-${width}-${mode}-${language}`)
        await ready(page, `/project/${id}`)
        await screenshot(page, `client-${width}-${mode}-${language}`)
        if (!baseline) {
          assert.equal(await page.locator('.brand-mark svg').first().count(), 1)
          if (width < 640) {
            await ready(page, '/admin')
            await page.locator('.admin-menu-trigger').click()
            await page.locator('#admin-drawer[open]').waitFor()
            await screenshot(page, `drawer-${width}-${mode}-${language}`)
            await page.keyboard.press('Escape')
            assert.equal(await page.locator('#bootstrap-splash').count(), 0)
          }
        }
        await context.close()
      }
    }
  }
  if (baseline) {
    console.log('Before screenshots captured')
  } else {
    results.push(
      'Pre-Vue delayed entry, admin/client hard reload, 1440/390/320px, light/dark, RU/EN, reduced motion and mobile drawer',
    )
    for (const path of ['/admin', `/project/${id}`, '/app', '/admin/login', '/invitation']) {
      const { context, page } = await fixture({ guest: path === '/app', mode: 'dark' })
      await ready(page, path)
      assert.equal(await page.locator('#bootstrap-splash').count(), 0)
      await screenshot(page, `route-${path.replaceAll('/', '-').slice(1)}-dark`)
      await context.close()
    }
    results.push(
      'Initial /admin, client project, /app, login and invitation mount and dismiss splash',
    )
    for (const mode of [null, 'system', 'light', 'dark']) {
      const { context, page } = await fixture({ mode, colorScheme: 'dark' })
      let release
      const held = new Promise((resolve) => {
        release = resolve
      })
      await page.route('**/src/main.js*', async (route) => {
        await held
        await route.continue()
      })
      await page.goto(root + '/admin/login', { waitUntil: 'commit' })
      await page.locator('#bootstrap-splash').waitFor()
      assert.equal(
        await page.locator('html').getAttribute('data-theme'),
        mode === 'light' ? 'light' : 'dark',
      )
      await page.emulateMedia({ colorScheme: 'light' })
      await page.waitForFunction(
        (expected) => document.documentElement.dataset.theme === expected,
        mode === 'dark' ? 'dark' : 'light',
      )
      release()
      await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
      await page.emulateMedia({ colorScheme: 'dark' })
      await page.waitForFunction(
        (expected) => document.documentElement.dataset.theme === expected,
        mode === 'light' ? 'light' : 'dark',
      )
      await context.close()
    }
    results.push('Saved light/dark, system and absent preference; OS changes before and after Vue')
    for (const language of ['ru', 'en']) {
      const { context, page } = await fixture({ mode: 'dark', language })
      const block = (route) => route.abort()
      await page.route('**/src/main.js*', block)
      await page.goto(root + '/admin/login')
      await page.locator('#bootstrap-fallback').waitFor()
      assert.equal(await page.locator('.bootstrap-dots').isVisible(), false)
      await screenshot(page, `failure-dark-${language}`)
      await page.unroute('**/src/main.js*', block)
      await page.locator('#bootstrap-retry').click()
      await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
      assert.equal(new URL(page.url()).pathname, '/admin/login')
      await context.close()
    }
    results.push('JS load error, contrast in dark theme, localized recovery and retry current URL')
    const { context, page } = await fixture({ guest: true })
    await page.route('**/api/session', (route) =>
      route.fulfill({ status: 503, json: { message: 'Unavailable' } }),
    )
    await ready(page, '/app')
    assert.equal(await page.locator('.app-entry [role="alert"]').isVisible(), true)
    assert.equal(await page.locator('#bootstrap-splash').count(), 0)
    await context.close()
    results.push('API failure mounts recoverable /app without endless fullscreen spinner')
    {
      const { context, page } = await fixture({ reducedMotion: 'no-preference' })
      await page.clock.install()
      let release
      const held = new Promise((resolve) => {
        release = resolve
      })
      await page.route('**/src/main.js*', async (route) => {
        await held
        await route.continue()
      })
      await page.goto(root + '/admin/login', { waitUntil: 'commit' })
      await page.locator('.bootstrap-dots').waitFor()
      assert.equal(
        await page
          .locator('.bootstrap-dots span')
          .first()
          .evaluate((e) => getComputedStyle(e).animationName),
        'bootstrap-pulse',
      )
      await page.clock.fastForward(25001)
      await page.locator('#bootstrap-fallback').waitFor()
      release()
      await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
      await context.close()
    }
    results.push('25-second recovery timeout, normal CSS animation and late successful startup')
    {
      const { context, page } = await fixture()
      await ready(page, '/admin')
      const heading = await page.locator('h1').first().textContent()
      let release
      const held = new Promise((resolve) => {
        release = resolve
      })
      await page.route(`**/api/admin/projects/${id}/tasks`, async (route) => {
        await held
        await route.fallback()
      })
      const requested = page.waitForRequest(
        (request) => new URL(request.url()).pathname === `/api/admin/projects/${id}/tasks`,
      )
      await page.locator(`a[href="/admin/projects/${id}/brief"]`).first().click()
      await requested
      await page.locator('.navigation-loading').waitFor()
      assert.equal(await page.locator('h1').first().textContent(), heading)
      assert.equal(await page.locator('.loading-overlay').count(), 1)
      assert.equal(await page.locator('#bootstrap-splash').count(), 0)
      await screenshot(page, 'internal-navigation-light')
      release()
      await page.waitForURL(`**/admin/projects/${id}/brief`)
      await page.locator('.navigation-loading').waitFor({ state: 'detached' })
      assert.equal(await page.locator('#bootstrap-splash').count(), 0)
      await context.close()
    }
    results.push(
      'Internal navigation retains previous page, dims with one indicator and never recreates fullscreen splash',
    )
    {
      const { context, page } = await fixture()
      for (const path of [
        '/favicon.svg?v=4.9.1',
        '/manifest.webmanifest',
        '/icon-192.png?v=4.9.1',
        '/icon-512.png?v=4.9.1',
        '/icon-maskable-192.png?v=4.9.1',
        '/icon-maskable-512.png?v=4.9.1',
        '/apple-touch-icon.png?v=4.9.1',
      ]) {
        const response = await context.request.get(root + path)
        assert.equal(response.status(), 200, path)
      }
      await page.setContent(
        `<base href="${root}/"><style>body{font:16px system-ui;background:#f7f8fa;color:#243b62;padding:32px}section{display:flex;align-items:center;gap:32px;padding:32px;margin-bottom:16px}section.dark{background:#15191f;color:#e4e9f0}img{object-fit:contain}.safe{border-radius:50%}</style><h1>projectbrief · brand assets</h1><section><img src="/brand-logo-light.svg" width="270" height="64"><img src="/favicon.svg" width="16" height="16"><img src="/favicon.svg" width="32" height="32"></section><section class="dark"><img src="/brand-logo-dark.svg" width="270" height="64"><img src="/favicon.svg" width="16" height="16"><img src="/favicon.svg" width="32" height="32"></section><section><img src="/icon-192.png" width="192" height="192"><img class="safe" src="/icon-maskable-192.png" width="192" height="192"><img src="/apple-touch-icon.png" width="180" height="180"></section>`,
      )
      await page
        .locator('img')
        .evaluateAll((images) => Promise.all(images.map((image) => image.decode())))
      await screenshot(page, 'brand-assets')
      await context.close()
    }
    results.push(
      'Favicon, manifest, PNG/Apple assets return 200; 16/32px favicon and circular maskable visual preview',
    )
    assert.deepEqual(runtimeErrors, [])
    await writeFile(
      new URL('../browser-results.json', directory),
      JSON.stringify({ passed: results.length, results }, null, 2) + '\n',
    )
    console.log(JSON.stringify(results, null, 2))
  }
} finally {
  await browser?.close()
  await server.close()
}
