// PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs npm run test:mobile-header
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import vue from '@vitejs/plugin-vue'
import { createDemoData } from '../src/data/demo.js'

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5184, strictPort: true },
  logLevel: 'error',
})
const root = 'http://127.0.0.1:5184'
const directory = new URL('../docs/milestone-4-2/mobile-header/', import.meta.url)
await mkdir(directory, { recursive: true })
const projectId = '11111111-1111-4111-8111-111111111111'
const clientPath = `/project/${projectId}`
const adminPath = `/admin/projects/${projectId}/brief`
const demo = createDemoData()
const project = { ...demo.project, id: projectId, sections: demo.sections, status: 'active' }
const results = []
let browser, baseline

async function fixture({
  permission = 'default',
  statusError = false,
  longNames = true,
  base = root,
  ...options
} = {}) {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    locale: 'en-GB',
    hasTouch: true,
    reducedMotion: 'reduce',
    ...options,
  })
  context.setDefaultTimeout(10000)
  await context.addInitScript(
    ({ permission }) => {
      localStorage.setItem('projectbrief.locale', 'en')
      // No native permission prompt, live service worker or external push subscription in tests.
      window.pushCalls = { register: 0, subscribe: 0, permission: 0 }
      let subscription = null
      const registration = {
        pushManager: {
          getSubscription: async () => subscription,
          subscribe: async () => {
            window.pushCalls.subscribe++
            subscription = {
              endpoint: 'https://push.example.test/device',
              expirationTime: null,
              toJSON: () => ({ endpoint: 'https://push.example.test/device', keys: {} }),
              unsubscribe: async () => {
                subscription = null
                return true
              },
            }
            return subscription
          },
        },
      }
      Object.defineProperty(window, 'Notification', {
        configurable: true,
        value: {
          permission,
          requestPermission: async () => {
            window.pushCalls.permission++
            window.Notification.permission = 'granted'
            return 'granted'
          },
        },
      })
      Object.defineProperty(window, 'PushManager', { configurable: true, value: {} })
      Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
          register: async () => {
            window.pushCalls.register++
            return registration
          },
          getRegistration: async () => registration,
          ready: Promise.resolve(registration),
        },
      })
    },
    { permission },
  )
  const calls = [],
    errors = []
  let subscribed = false
  const name = longNames
    ? 'Очень длинное название рабочего пространства и клиента '.repeat(5)
    : 'Studio'
  const workspace = { id: 'studio', name }
  const user = { name: 'Admin', email: 'admin@example.test', membershipRole: 'owner' }
  await context.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request(),
        path = new URL(request.url()).pathname
      calls.push({ path, method: request.method() })
      let payload
      if (path === '/api/csrf') payload = { token: 'test-csrf' }
      else if (path === '/api/client/me') payload = { id: 'client', name, preferredLocale: 'en' }
      else if (path.endsWith('/me/locale'))
        payload = { preferredLocale: request.postDataJSON().locale }
      else if (path === '/api/admin/me') payload = { user, workspace }
      else if (path === '/api/admin/workspaces') payload = { data: [workspace] }
      else if (path === '/api/admin/projects') payload = { data: [project] }
      else if (path.endsWith('/activity/read')) payload = { unreadCount: 0 }
      else if (path.endsWith('/push/status')) {
        if (statusError) return route.fulfill({ status: 503, json: {} })
        payload = { available: true, subscribed, publicKey: 'YQ' }
      } else if (path.endsWith('/push/subscriptions')) {
        subscribed = request.method() === 'POST'
        payload = {}
      } else if (path.endsWith('/tasks')) payload = { data: demo.tasks }
      else if (
        path === `/api/client/project/${projectId}` ||
        path === `/api/admin/projects/${projectId}`
      )
        payload = { data: project }
      else throw new Error(`Unexpected request ${path}`)
      await route.fulfill({ json: payload })
    },
  )
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  async function open(path) {
    await page.goto(base + path)
    await page.locator('.theme-switcher select').waitFor()
    await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
    await page.locator('h1').first().waitFor()
  }
  return { context, page, calls, errors, open }
}
async function settings(page, language, mode) {
  await page.locator('.language-switcher select').selectOption(language)
  await page.locator('.theme-switcher select').selectOption(mode)
  await page.waitForFunction(
    ({ language, mode }) =>
      document.documentElement.lang === language && document.documentElement.dataset.theme === mode,
    { language, mode },
  )
}
async function compact(page, authenticated) {
  await page.waitForFunction(
    (authenticated) =>
      !authenticated || !!document.querySelector('.mobile-push-footer .push-trigger'),
    authenticated,
  )
  const layout = await page.evaluate(() => {
    const header = document.querySelector('.app-topbar, .admin-toolbar')
    const rect = header.getBoundingClientRect()
    const brand = header.querySelector('.brand-mark').getBoundingClientRect()
    const controls = [...header.querySelectorAll('.theme-switcher, .language-switcher')].map(
      (element) => {
        const box = element.getBoundingClientRect(),
          style = getComputedStyle(element)
        const select = getComputedStyle(element.querySelector('select'))
        return {
          x: box.x,
          y: box.y,
          width: box.width,
          height: box.height,
          background: style.backgroundColor,
          border: style.borderWidth,
          opacity: select.opacity,
        }
      },
    )
    return {
      overflow: document.documentElement.scrollWidth > innerWidth,
      height: rect.height,
      brand: { y: brand.y, height: brand.height },
      controls,
      headerPush: header.querySelectorAll('.push-trigger').length,
      wordmark: header.querySelector('.brand-wordmark')?.checkVisibility() || false,
      workspace: header.querySelector('.admin-toolbar-workspace')?.checkVisibility() || false,
      client: header.querySelector('.current-client-name')?.checkVisibility() || false,
      footerPosition: document.querySelector('.mobile-push-footer')
        ? getComputedStyle(document.querySelector('.mobile-push-footer')).position
        : null,
    }
  })
  assert.equal(layout.overflow, false)
  assert.ok(layout.height >= 60 && layout.height <= 61, 'one 60px row, including its bottom border')
  assert.equal(layout.wordmark, false)
  assert.equal(layout.workspace, false)
  assert.equal(layout.client, false)
  assert.equal(layout.headerPush, 0)
  for (const control of layout.controls) {
    assert.equal(control.width, 44)
    assert.equal(control.height, 44)
    assert.ok(
      Math.abs(control.y + 22 - layout.brand.y - layout.brand.height / 2) <= 1,
      'all controls share one row',
    )
    assert.equal(control.opacity, '0', 'native select is presented by its icon')
    assert.equal(control.border, '0px')
    assert.equal(control.background, 'rgba(0, 0, 0, 0)')
  }
  assert.equal(await page.locator('.push-trigger').count(), authenticated ? 1 : 0)
  if (authenticated) {
    assert.equal(await page.locator('.mobile-push-footer .push-trigger').isVisible(), true)
    assert.equal(layout.footerPosition, 'static')
    assert.equal(
      await page
        .locator('.mobile-push-footer')
        .evaluate(
          (element) =>
            !!element.previousElementSibling?.matches('main') &&
            element.getBoundingClientRect().top >=
              element.previousElementSibling.getBoundingClientRect().bottom,
        ),
      true,
    )
  }
}
async function shot(page, name, footer = false) {
  await page.evaluate(async () => (await import('/src/services/toast.js')).toast.clear())
  if (footer) await page.locator('.mobile-push-footer').scrollIntoViewIfNeeded()
  else await page.evaluate(() => window.scrollTo(0, 0))
  await page.evaluate(() => document.activeElement?.blur?.())
  await page.mouse.move(0, 0)
  await page.screenshot({
    path: new URL(name + '.png', directory).pathname,
    fullPage: false,
    animations: 'disabled',
  })
}

async function compareHeader(page, actual, expected) {
  return page.evaluate(
    async ({ actual, expected }) => {
      async function pixels(value) {
        const image = new Image()
        image.src = 'data:image/png;base64,' + value
        await image.decode()
        const canvas = document.createElement('canvas')
        canvas.width = image.width
        canvas.height = image.height
        const context = canvas.getContext('2d')
        context.drawImage(image, 0, 0)
        return context.getImageData(0, 0, canvas.width, canvas.height).data
      }
      const [a, b] = await Promise.all([pixels(actual), pixels(expected)])
      if (a.length !== b.length) return false
      // Rounded native control borders can differ by two RGB levels between Chromium contexts.
      return a.every((value, index) => Math.abs(value - b[index]) <= 2)
    },
    { actual: actual.toString('base64'), expected: expected.toString('base64') },
  )
}

try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  if (!process.env.MOBILE_HEADER_DESKTOP_ONLY) {
    const state = await fixture()
    const { page, context, errors } = state
    for (const [width, height] of [
      [320, 800],
      [360, 800],
      [390, 844],
      [640, 360],
    ]) {
      await page.setViewportSize({ width, height })
      for (const [persona, path] of [
        ['client', clientPath],
        ['admin', adminPath],
        ['login', '/admin/login'],
      ]) {
        await state.open(path)
        for (const language of ['ru', 'en'])
          for (const mode of ['light', 'dark']) {
            await settings(page, language, mode)
            await page.mouse.move(0, 0)
            await compact(page, persona !== 'login')
            const select = page.locator('.theme-switcher select')
            await select.focus()
            assert.equal(
              await page
                .locator('.theme-switcher')
                .evaluate((element) => getComputedStyle(element).outlineWidth),
              '3px',
            )
            await page.keyboard.press('Tab')
            assert.equal(
              await page
                .locator('.language-switcher select')
                .evaluate((element) => document.activeElement === element),
              true,
            )
            await page.keyboard.press('Shift+Tab')
            assert.equal(
              await select.evaluate((element) => document.activeElement === element),
              true,
            )
            if (
              (width === 320 && language === 'ru') ||
              (width === 390 && language === 'en' && mode === 'dark')
            ) {
              await shot(page, `${persona}-${width}-${language}-${mode}`)
              if (persona !== 'login')
                await shot(page, `${persona}-footer-${width}-${language}-${mode}`, true)
            }
            if (persona === 'admin') {
              await page.locator('.admin-menu-trigger').click()
              await page.locator('#admin-drawer[open]').waitFor()
              assert.equal(
                await page.locator('#admin-drawer .sidebar-workspace option').textContent(),
                'Очень длинное название рабочего пространства и клиента '.repeat(5),
              )
              if (width === 320 && language === 'ru')
                await shot(page, `drawer-${width}-${language}-${mode}`)
              await page.keyboard.press('Escape')
              assert.equal(
                await page
                  .locator('.admin-menu-trigger')
                  .evaluate((element) => document.activeElement === element),
                true,
              )
            }
          }
      }
    }
    assert.deepEqual(errors, [])
    await context.close()
    results.push(
      'Single-row 320/360/390px portrait and 640px landscape: client/admin/login, long names, RU/EN, light/dark, 44px icon controls, keyboard focus and drawer',
    )

    for (const persona of ['client', 'admin']) {
      for (const permission of ['default', 'granted', 'denied']) {
        const state = await fixture({ permission })
        const { page, context, calls } = state
        await state.open(persona === 'client' ? clientPath : adminPath)
        await page.waitForFunction(() => window.pushCalls.register === 1)
        await page.locator('.mobile-push-footer .push-trigger').click()
        await page.locator('#push-settings-title').waitFor()
        await page.waitForFunction(
          () =>
            !document.querySelector('dialog[open] [role="status"]').textContent.includes('Loading'),
        )
        if (permission === 'granted')
          await page.getByRole('button', { name: 'Disable on this device', exact: true }).waitFor()
        if (permission === 'denied')
          assert.ok(
            (await page.locator('dialog[open]').textContent()).includes(
              'Notifications are blocked',
            ),
          )
        const trigger = await page.locator('.push-trigger').elementHandle()
        const before = await page.evaluate(() => ({ ...window.pushCalls }))
        const statusRequests = calls.filter(({ path }) => path.endsWith('/push/status')).length
        assert.equal(before.permission, 0)
        assert.equal(before.subscribe, permission === 'granted' ? 1 : 0)
        for (const width of [1440, 320, 641, 390]) {
          await page.setViewportSize({ width, height: 844 })
          await page.waitForFunction(
            (mobile) => !!document.querySelector('.mobile-push-footer .push-trigger') === mobile,
            width <= 640,
          )
          assert.equal(
            await trigger.evaluate(
              (element) => element === document.querySelector('.push-trigger'),
            ),
            true,
          )
          assert.equal(
            await page.locator('dialog[open]').evaluate((element) => element.matches(':modal')),
            true,
            'moving the trigger must preserve the open modal',
          )
          assert.equal(await page.locator('.push-trigger').count(), 1)
        }
        assert.deepEqual(await page.evaluate(() => window.pushCalls), before)
        assert.equal(
          calls.filter(({ path }) => path.endsWith('/push/status')).length,
          statusRequests,
        )
        if (permission === 'default') {
          await page.getByRole('button', { name: 'Enable push notifications', exact: true }).click()
          await page.getByRole('button', { name: 'Disable on this device', exact: true }).waitFor()
          assert.deepEqual(await page.evaluate(() => window.pushCalls), {
            register: 1,
            subscribe: 1,
            permission: 1,
          })
        }
        await page.keyboard.press('Escape')
        assert.equal(
          await page
            .locator('.push-trigger')
            .evaluate((element) => document.activeElement === element),
          true,
        )
        assert.deepEqual(state.errors, [])
        await context.close()
      }
    }
    results.push(
      'Client/admin Push default/granted/denied, one live component across resizes, preserved modal and state, no duplicate status/subscriptions and permission only after explicit Enable',
    )

    const failure = await fixture({ statusError: true })
    await failure.open(clientPath)
    await failure.page.locator('.mobile-push-footer .push-trigger').click()
    await failure.page.locator('dialog[open] .field-error').waitFor()
    assert.equal(
      await failure.page.getByRole('button', { name: 'Try again', exact: true }).count(),
      1,
    )
    assert.equal(await failure.page.evaluate(() => window.pushCalls.permission), 0)
    await failure.context.close()
    results.push(
      'Push error/retry state remains available in the mobile footer without requesting permission',
    )
  }
  // Optional pixel comparison against the previous commit, using the identical API fixture.
  if (process.env.APPEARANCE_BASELINE_ROOT) {
    baseline = await createServer({
      root: process.env.APPEARANCE_BASELINE_ROOT,
      configFile: false,
      envFile: false,
      plugins: [vue()],
      define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
      cacheDir: process.env.APPEARANCE_BASELINE_ROOT + '/.vite-cache',
      server: { host: '127.0.0.1', port: 5185, strictPort: true },
      logLevel: 'error',
    })
    await baseline.listen()
  }
  for (const [persona, path] of [
    ['client', clientPath],
    ['admin', adminPath],
    ['login', '/admin/login'],
  ]) {
    const current = await fixture({ longNames: false, viewport: { width: 1440, height: 1050 } })
    const previous = baseline
      ? await fixture({
          longNames: false,
          base: 'http://127.0.0.1:5185',
          viewport: { width: 1440, height: 1050 },
        })
      : null
    await current.open(path)
    await previous?.open(path)
    for (const mode of ['light', 'dark']) {
      await settings(current.page, 'en', mode)
      if (previous) await settings(previous.page, 'en', mode)
      const header = current.page.locator('.app-topbar, .admin-toolbar')
      assert.equal(await current.page.locator('.language-switcher > svg').isVisible(), false)
      assert.equal(
        await current.page
          .locator('.theme-switcher select')
          .evaluate((element) => getComputedStyle(element).opacity),
        '1',
      )
      assert.equal(await header.locator('.push-trigger').count(), persona === 'login' ? 0 : 1)
      assert.equal(
        await current.page.locator('.mobile-push-footer').filter({ visible: true }).count(),
        0,
      )
      if (persona !== 'admin')
        assert.equal(await current.page.locator('.brand-wordmark').isVisible(), true)
      if (previous) {
        await current.page.mouse.move(0, 0)
        await previous.page.mouse.move(0, 0)
        const actual = await header.screenshot({ animations: 'disabled' })
        const expected = await previous.page
          .locator('.app-topbar, .admin-toolbar')
          .screenshot({ animations: 'disabled' })
        assert.deepEqual(
          await header.boundingBox(),
          await previous.page.locator('.app-topbar, .admin-toolbar').boundingBox(),
        )
        const matching = await compareHeader(current.page, actual, expected)
        if (!matching) {
          await writeFile('/tmp/projectbrief-header-actual.png', actual)
          await writeFile('/tmp/projectbrief-header-expected.png', expected)
        }
        assert.equal(
          matching,
          true,
          `${persona} ${mode} desktop header must match 86ea458 (two RGB levels of antialiasing tolerance)`,
        )
      }
    }
    await current.context.close()
    await previous?.context.close()
  }
  results.push(
    baseline
      ? 'Desktop client/admin/login match 86ea458 geometry and rendered pixels (two RGB levels of antialiasing tolerance) in light/dark; original Push position and native selects preserved'
      : 'Desktop client/admin/login retain wordmarks, native selects and Push in the header',
  )
  await writeFile(
    new URL('results.json', directory),
    JSON.stringify({ passed: results.length, results }, null, 2) + '\n',
  )
  console.log(JSON.stringify({ passed: results.length, results }, null, 2))
} finally {
  await browser?.close()
  await server.close()
  await baseline?.close()
}
