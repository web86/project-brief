// PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/notifications-ui.browser.mjs
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = new URL('../docs/milestone-4-8-2/', import.meta.url)
await mkdir(output, { recursive: true })
const server = await createServer({
  envFile: false,
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: {
    host: '127.0.0.1',
    port: 5192,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/api/**', '**/docs/**', '**/release/**'] },
  },
  logLevel: 'error',
})
let browser
const results = []
const delay = () => {
  let finish
  const promise = new Promise((resolve) => {
    finish = resolve
  })
  return { promise, finish }
}
async function fixture(config) {
  const state = {
    telegram: { available: true, connected: false },
    preferences: { email: true, push: true, telegram: true },
    email: 'anna@example.test',
    clientId: 'anna',
    adminId: 'admin-fixture-id',
    failReads: false,
    failTelegramRead: false,
    fail: false,
    gate: null,
    reads: { settings: 0, telegram: 0 },
    writes: [],
  }
  const f = await taskWorkspaceFixture(browser, config, {
    output,
    port: 5192,
    async setup({ context }) {
      await context.route('**/api/**', async (route) => {
        const request = route.request(),
          path = new URL(request.url()).pathname,
          method = request.method()
        if (path.startsWith('/api/invitations/'))
          return route.fulfill({ status: 410, json: { code: 'invitation_invalid' } })
        if (path === '/api/session') return route.fulfill({ json: { authenticated: false } })
        if (path === '/api/client/me')
          return route.fulfill({
            json: {
              id: state.clientId,
              name: config.longNames
                ? 'AnnaWithAVeryLongClientNameWithoutSpaces'.repeat(4)
                : 'Anna Smith',
              preferredLocale: config.language,
            },
          })
        if (/^\/api\/(admin|client)\/notifications$/.test(path)) {
          if (method === 'GET') {
            state.reads.settings++
            if (state.failReads)
              return route.fulfill({ status: 503, json: { message: 'Unavailable' } })
          } else {
            state.writes.push(request.postDataJSON())
            if (state.gate) await state.gate.promise
            if (state.fail) return route.fulfill({ status: 503, json: { message: 'Unavailable' } })
            Object.assign(state.preferences, request.postDataJSON().preferences)
          }
          return route.fulfill({
            json: {
              preferences: { ...state.preferences },
              email: state.email,
              accountEmail: 'anna@example.test',
              pendingEmail: null,
              emailAvailable: true,
              emailUnified: false,
              legacyEmails: [
                {
                  projectId: 'legacy',
                  projectName: 'Previous project',
                  workspaceName: 'Studio',
                  email: 'old@example.test',
                  active: true,
                },
              ],
              user: {
                notificationPreferenceId: state.adminId,
                name: 'Alex Morgan',
                email: 'admin@example.test',
                membershipRole: 'owner',
                preferredLocale: config.language,
              },
              workspaces: [{ id: 'studio', name: 'Design Studio' }],
            },
          })
        }
        if (/^\/api\/(admin|client)\/telegram(?:\/.*)?$/.test(path)) {
          if (method === 'GET') {
            state.reads.telegram++
            if (state.failTelegramRead)
              return route.fulfill({ status: 503, json: { message: 'Unavailable' } })
          }
          if (path.endsWith('/connect'))
            return route.fulfill({ json: { url: 'https://t.me/BriefWeb86Bot?start=fixture' } })
          if (path.endsWith('/test'))
            return route.fulfill({
              status: state.fail ? 422 : 200,
              json: state.fail ? { code: 'telegram_blocked' } : { status: 'sent' },
            })
          if (method === 'DELETE') state.telegram.connected = false
          return route.fulfill({ json: state.telegram })
        }
        await route.fallback()
      })
    },
  })
  const home = config.admin ? `/admin/projects/${uuid}/brief` : `/project/${uuid}`
  const settingsPath = config.admin ? '/admin/settings/notifications' : home + '/notifications'
  const go = async (path) => {
    await f.page.goto(`http://127.0.0.1:5192${path}`)
    await f.page.locator('.navigation-loading').waitFor({ state: 'detached' })
  }
  const navigate = async (path) => {
    await f.page.evaluate(async (path) => {
      const { default: router } = await import('/src/router/index.js')
      await router.push(path)
    }, path)
    await f.page.locator('.navigation-loading').waitFor({ state: 'detached' })
  }
  return { ...f, state, home, settingsPath, go, navigate }
}
async function noOverflow(page) {
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    'No horizontal overflow',
  )
}
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const admin of process.env.M482_STATEFUL_ONLY === '1' ? [] : [false, true])
    for (const width of [1440, 1024, 768, 390])
      for (const mode of ['light', 'dark'])
        for (const language of ['ru', 'en']) {
          const config = { admin, width, mode, language, longNames: width === 1024 }
          const f = await fixture(config),
            { page, state } = f
          const label = `${admin ? 'admin' : 'client'}-${width}-${language}-${mode}`
          assert.equal(
            await page.locator('.telegram-prompt').count(),
            0,
            'No prompt in task editor',
          )
          assert.equal(
            await page.locator('.client-sidebar').count(),
            0,
            'No client sidebar in task editor',
          )
          await f.go(f.home)
          await page.locator('.telegram-prompt').waitFor()
          if (!admin) {
            assert.equal(await page.locator('.client-sidebar').isVisible(), width > 960)
            assert.equal(await page.locator('.client-menu-trigger').isVisible(), width <= 960)
            if (width > 960) {
              assert.equal(
                await page.locator('.client-sidebar nav a[aria-current=page]').count(),
                1,
              )
              assert.equal(await page.locator('.client-sidebar .unread-badge').innerText(), '3')
              assert.equal(await page.locator('.current-client-name').isVisible(), false)
            }
          }
          await noOverflow(page)
          if (language === 'ru' && [1440, 390].includes(width))
            await f.screenshot(`project-${label}`)
          // The CTA persists dismissal, routes to the right personal page and reveals Telegram.
          await page.locator('.telegram-prompt a').click()
          await page.waitForURL(`**${f.settingsPath}#telegram-settings`)
          await page.locator('.telegram-settings').waitFor()
          assert.equal(await page.locator('.telegram-prompt').count(), 0)
          assert.equal(await page.locator('.channel-card').count(), 3)
          assert.equal(await page.getByRole('switch').count(), 3, 'One personal switch per channel')
          assert.ok(
            await page
              .locator('.telegram-settings')
              .evaluate((node) => node.getBoundingClientRect().top >= -1),
          )
          assert.equal(state.reads.telegram, 1, 'Shared Telegram state avoids a second request')
          assert.equal(
            state.reads.settings,
            admin ? 2 : 1,
            'Admin route bootstrap supplies page state',
          )
          if (!admin && width > 960)
            assert.equal(await page.locator('.client-page-navigation').isVisible(), false)
          await noOverflow(page)
          if (language === 'ru' && [1440, 390].includes(width))
            await f.screenshot(`notifications-${label}`)
          await page.reload()
          await page.locator('.channel-card').first().waitFor()
          assert.equal(
            await page.locator('.telegram-prompt').count(),
            0,
            'Dismissal survives reload',
          )
          if (!admin && width === 390) {
            await page.locator('.client-menu-trigger').click()
            await page.locator('#client-drawer[open]').waitFor()
            await page.keyboard.press('Escape')
            assert.equal(await page.locator('#client-drawer[open]').count(), 0)
            await page.locator('.client-menu-trigger').click()
            await page.locator('#client-drawer a').first().click()
            await page.waitForURL('**' + f.home)
            await page.locator('.client-menu-trigger').click()
            await page.setViewportSize({ width: 1024, height: 900 })
            await page.locator('#client-drawer[open]').waitFor({ state: 'detached' })
            assert.equal(await page.locator('.client-sidebar').isVisible(), true)
          }
          assert.deepEqual(f.errors, [], label)
          results.push({ label, passed: true })
          await f.context.close()
        }
  // Stateful regressions: draft vs confirmed settings, errors, reconnect and test delivery.
  for (const admin of [false, true]) {
    const f = await fixture({ admin, width: 1024, language: 'en', mode: 'light' }),
      { page, state } = f
    state.telegram.connected = true
    await f.go(f.settingsPath)
    await page.locator('.telegram-settings input').waitFor()
    assert.equal(await page.locator('.telegram-prompt').count(), 0)
    const toggle = page.locator('.telegram-settings input')
    state.gate = delay()
    state.fail = true
    await toggle.focus()
    await page.keyboard.press('Space')
    if (!admin) {
      assert.equal(state.writes.length, 0, 'Client draft does not auto-save')
      await page.locator('.notification-save-bar button').click()
    }
    await page.waitForFunction(
      () =>
        document.querySelector('button[aria-busy=true]') ||
        document.querySelector('input[disabled]'),
    )
    assert.equal(await page.locator('.telegram-prompt').count(), 0, 'No unconfirmed success state')
    if (admin)
      assert.equal(await toggle.isChecked(), true, 'Admin keeps confirmed setting during request')
    state.gate.finish()
    await page.locator('.field-error').first().waitFor()
    assert.equal(state.preferences.telegram, true)
    if (admin) assert.equal(await toggle.isChecked(), true)
    state.fail = false
    state.gate = null
    if (admin) await toggle.click()
    else await page.locator('.notification-save-bar button').click()
    await page.locator('.telegram-prompt').waitFor()
    assert.equal(state.preferences.telegram, false)
    await toggle.click()
    if (!admin) await page.locator('.notification-save-bar button').click()
    await page.locator('.telegram-prompt').waitFor({ state: 'detached' })
    await page.getByRole('button', { name: 'Reconnect', exact: true }).click()
    await page.locator('.telegram-link a').waitFor()
    assert.equal(await page.locator('.telegram-link a').getAttribute('target'), '_blank')
    await page.getByRole('button', { name: 'Test Telegram', exact: true }).click()
    await page
      .locator('.toast-success')
      .filter({ hasText: 'Telegram accepted the test message.' })
      .waitFor()
    state.fail = true
    await page.getByRole('button', { name: 'Test Telegram', exact: true }).click()
    await page.locator('.telegram-settings .field-error').waitFor()
    state.fail = false
    await page.getByRole('button', { name: 'Disconnect', exact: true }).click()
    await page.locator('.telegram-prompt').waitFor()
    await page.locator('.telegram-prompt-close').click()
    await f.go(f.home)
    assert.equal(await page.locator('.telegram-prompt').count(), 0)
    if (admin) {
      state.adminId = 'second-admin'
      await f.go(f.settingsPath)
      await page.locator('.telegram-prompt').waitFor()
      await page.locator('.telegram-prompt-close').click()
      state.adminId = 'third-admin'
      await f.navigate(f.settingsPath + '#telegram-settings')
      await page.locator('.telegram-prompt').waitFor()
      state.adminId = 'second-admin'
      await f.navigate(f.settingsPath)
      await page.getByRole('button', { name: 'Connect Telegram', exact: true }).waitFor()
      assert.equal(
        await page.locator('.telegram-prompt').count(),
        0,
        'Dismissed admin still loads Telegram after switching in place',
      )
    }
    if (!admin) {
      // Same browser, different authenticated client: no inherited dismissal.
      state.clientId = 'second-client'
      await f.go(f.home)
      await page.locator('.telegram-prompt').waitFor()
      state.telegram.available = false
      await f.go(f.home)
      assert.equal(await page.locator('.telegram-prompt').count(), 0)
      for (const path of [f.home + '/task/new', f.path(f.first.id), f.path(f.first.id) + '/edit']) {
        await f.go(path)
        assert.equal(await page.locator('.client-sidebar').count(), 0)
        assert.equal(await page.locator('.telegram-prompt').count(), 0)
      }
    }
    for (const path of ['/admin/login', '/invitation', '/access-error', '/unknown']) {
      await f.go(path)
      assert.equal(await page.locator('.telegram-prompt').count(), 0)
      assert.equal(await page.locator('.client-sidebar').count(), 0)
    }
    assert.deepEqual(f.errors, [])
    results.push({ label: `${admin ? 'admin' : 'client'}-stateful-regressions`, passed: true })
    await f.context.close()
  }
  {
    const f = await fixture({ admin: false, width: 390, language: 'en', mode: 'dark' })
    const { page, state } = f
    state.failReads = true
    state.failTelegramRead = true
    await f.go(f.settingsPath)
    await page.locator('.client-notifications .field-error').waitFor()
    assert.equal(await page.locator('.channel-card').count(), 0)
    assert.equal(await page.locator('.telegram-prompt').count(), 0)
    state.failReads = false
    await page.getByRole('button', { name: 'Try again', exact: true }).click()
    await page.locator('.telegram-settings .field-error').waitFor()
    assert.match(
      await page.locator('.telegram-settings .channel-status').innerText(),
      /Could not load/,
    )
    state.failTelegramRead = false
    await page.getByRole('button', { name: 'Refresh status' }).click()
    await page.locator('.telegram-prompt').waitFor()
    state.telegram.available = false
    await page.getByRole('button', { name: 'Refresh status' }).click()
    await page.locator('.telegram-prompt').waitFor({ state: 'detached' })
    assert.equal(await page.locator('.telegram-settings input').isDisabled(), true)
    state.email = null
    await f.go(f.settingsPath)
    await page.locator('.personal-email').waitFor()
    assert.equal(await page.locator('.personal-email input').count(), 0)
    await noOverflow(page)
    assert.deepEqual(f.errors, [])
    results.push({ label: 'client-load-errors-unavailable-and-empty-email', passed: true })
    await f.context.close()
  }
  await writeFile(new URL('browser-results.json', output), JSON.stringify(results, null, 2) + '\n')
  console.log(`PASS: ${results.length} notification/navigation scenarios; ${output.pathname}`)
} finally {
  await browser?.close()
  await server.close()
}
