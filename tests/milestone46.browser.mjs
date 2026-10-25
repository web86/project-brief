import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
import { notificationEvents } from '../src/services/notificationSettings.js'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = process.env.M46_OUTPUT_DIR
  ? new URL('file://' + process.env.M46_OUTPUT_DIR.replace(/\/$/, '') + '/')
  : new URL('../docs/milestone-4-6/', import.meta.url)
await mkdir(output, { recursive: true })
const port = 5192,
  origin = `http://127.0.0.1:${port}`
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port, strictPort: true },
  logLevel: 'error',
})
const quick = process.env.M46_QUICK === '1'
const results = [],
  scenarios = []
let browser, currentConfig
const allRules = () =>
  Object.fromEntries(
    notificationEvents.map((event) => [
      event,
      { enabled: true, recipients: { mode: 'all', ids: [] } },
    ]),
  )
async function fixture(config) {
  let personal = {
    user: {
      notificationPreferenceId: 'admin-fixture-id',
      name: config.language === 'ru' ? 'Алексей Морозов' : 'Alex Morgan',
      email: 'alex@example.test',
      preferredLocale: config.language,
    },
    workspaces: [
      { id: 'studio', name: 'Design Studio' },
      { id: 'other-workspace', name: 'Other workspace' },
    ],
    preferences: { email: true, push: true, telegram: true },
    email: 'alex@example.test',
    accountEmail: 'alex@example.test',
    pendingEmail: null,
    emailUnified: false,
    emailAvailable: true,
    legacyEmails: [
      {
        projectId: uuid,
        projectName: 'Магазин и клиентский кабинет',
        workspaceName: 'Design Studio',
        email: 'projects@example.test',
        active: true,
      },
      {
        projectId: 'other',
        projectName: 'Второй проект',
        workspaceName: 'Other workspace',
        email: 'studio@example.test',
        active: true,
      },
    ],
  }
  let projectSettings = {
    deliveryMode: 'legacy',
    events: Object.fromEntries(
      notificationEvents.map((event) => [event, { email: true, push: true, telegram: false }]),
    ),
    eventRules: allRules(),
    recipientOptions: {
      team: [
        { id: 1, name: 'Алексей Морозов / Alex Morgan' },
        { id: 2, name: 'Менеджер проекта / Project manager' },
      ],
      clients: [
        { id: 'anna', name: 'Анна Смирнова / Anna Smith' },
        { id: 'john', name: 'John Smith' },
      ],
    },
  }
  let connected = true
  const calls = []
  const f = await taskWorkspaceFixture(browser, config, {
    output,
    port,
    setup: async ({ context, data }) => {
      data.tasks[0].status = 'in_progress'
      data.tasks[1].status = 'approved'
      data.tasks[2].status = 'done'
      data.sections[0].name = config.language === 'ru' ? 'Главная страница' : 'Homepage'
      data.sections[1].name =
        'Очень длинное название раздела с несколькими важными уточнениями для мобильной проверки / A long section name for small screens'
      await context.addInitScript(() => {
        let subscription = {
          endpoint: 'https://fcm.googleapis.com/send/m46-current-device',
          toJSON() {
            return {
              endpoint: this.endpoint,
              keys: { p256dh: 'B' + 'a'.repeat(86), auth: 'a'.repeat(22) },
            }
          },
          async unsubscribe() {
            subscription = null
            return true
          },
        }
        window.__pushSubscribeCount = 0
        const registration = {
          pushManager: {
            getSubscription: async () => subscription,
            subscribe: async () => {
              window.__pushSubscribeCount++
              subscription = {
                endpoint: 'https://fcm.googleapis.com/send/m46-current-device',
                toJSON() {
                  return {
                    endpoint: this.endpoint,
                    keys: { p256dh: 'B' + 'a'.repeat(86), auth: 'a'.repeat(22) },
                  }
                },
                async unsubscribe() {
                  subscription = null
                  return true
                },
              }
              return subscription
            },
          },
        }
        Object.defineProperty(navigator, 'serviceWorker', {
          configurable: true,
          value: {
            register: async () => registration,
            ready: Promise.resolve(registration),
            getRegistration: async () => registration,
          },
        })
        Object.defineProperty(window, 'PushManager', { configurable: true, value: function () {} })
        Object.defineProperty(window, 'Notification', {
          configurable: true,
          value: { permission: 'granted', requestPermission: async () => 'granted' },
        })
      })
      await context.route(
        (url) =>
          url.pathname.startsWith('/api/') &&
          (url.pathname === '/api/admin/notifications' ||
            url.pathname.startsWith('/api/admin/notifications/') ||
            /\/projects\/[^/]+\/notifications$/.test(url.pathname) ||
            /\/push\//.test(url.pathname) ||
            /\/telegram(?:\/|$)/.test(url.pathname) ||
            url.pathname === '/api/client/notifications' ||
            /\/discussions(?:\/|$)/.test(url.pathname)),
        async (route) => {
          const request = route.request(),
            path = new URL(request.url()).pathname,
            body = request.postDataJSON()
          calls.push({ path, method: request.method(), body })
          let result
          if (path === '/api/admin/notifications') {
            if (body?.preferences) personal.preferences = { ...body.preferences }
            if (body?.email) {
              if (body.email === personal.accountEmail) {
                personal.email = body.email
                personal.emailUnified = true
                personal.pendingEmail = null
              } else personal.pendingEmail = body.email
            }
            result = personal
          } else if (path.endsWith('/notifications/email/verify')) {
            personal.email = personal.pendingEmail || 'confirmed@example.test'
            personal.pendingEmail = null
            personal.emailUnified = true
            result = personal
          } else if (path.endsWith('/notifications/email/pending')) {
            personal.pendingEmail = null
            result = personal
          } else if (path === '/api/admin/notifications/test')
            result = { status: 'sent', code: null }
          else if (/\/projects\/[^/]+\/notifications$/.test(path)) {
            if (body?.eventRules) {
              assert.equal(body.confirmMigration, true)
              projectSettings = {
                ...projectSettings,
                deliveryMode: 'personal',
                eventRules: body.eventRules,
              }
            }
            result = projectSettings
          } else if (path.endsWith('/push/status'))
            result = {
              available: true,
              publicKey: 'YQ',
              subscribed: !!new URL(request.url()).searchParams.get('endpointHash'),
            }
          else if (path.endsWith('/push/subscriptions'))
            result = { subscribed: request.method() !== 'DELETE' }
          else if (path.endsWith('/telegram/test')) result = { status: 'sent', code: null }
          else if (path.endsWith('/telegram/connect'))
            result = {
              url: 'https://t.me/BriefWeb86Bot?start=' + 'a'.repeat(64),
              expiresAt: '2026-10-21T10:00:00Z',
            }
          else if (path.endsWith('/telegram')) {
            if (request.method() === 'DELETE') connected = false
            result = { available: true, connected, connectedAt: '2026-10-20T10:00:00Z' }
          } else if (path === '/api/client/notifications')
            result = {
              email: 'anna@example.test',
              preferences: body?.preferences || { email: true, push: true, telegram: true },
            }
          else if (path.endsWith('/discussions/read')) result = { unreadCount: 3 }
          else if (path.endsWith('/discussions'))
            result = { data: [], nextCursor: null, unreadCount: 3 }
          else throw Error('Unhandled M46 mock: ' + path)
          await route.fulfill({ json: result })
        },
      )
    },
  })
  return {
    ...f,
    channelCalls: calls,
    personal: () => personal,
    projectSettings: () => projectSettings,
  }
}
async function go(page, path) {
  await page.goto(origin + path)
  await page.locator('.navigation-loading').waitFor({ state: 'detached' })
}
async function fits(page, label) {
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    label + ': page has no horizontal overflow',
  )
}
async function routeTo(page, path) {
  await page.evaluate(async (value) => {
    const router = (await import('/src/router/index.js')).default
    await router.push(value)
  }, path)
  await page.locator('.navigation-loading').waitFor({ state: 'detached' })
}
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const width of quick ? [320] : [1440, 1280, 768, 390, 320])
    for (const mode of quick ? ['light'] : ['light', 'dark', 'system'])
      for (const language of quick ? ['ru'] : ['ru', 'en'])
        for (const admin of [true, false]) {
          const config = { width, mode, language, admin, longNames: true }
          currentConfig = config
          const f = await fixture(config),
            { page } = f,
            label = `${admin ? 'admin' : 'client'}-${width}-${language}-${mode}`
          try {
            await fits(page, label + ' task')
            assert.equal(
              await page.locator('#task-brief-section,.developer-panel .order-actions').count(),
              0,
            )
            assert.equal(await page.locator('#developer-status').count(), admin ? 1 : 0)
            if (admin) assert.equal(await page.locator('#estimate,#price').count(), 2)
            await go(page, admin ? adminRoot + '/brief' : `/project/${uuid}`)
            await page.locator('.task-card').first().waitFor()
            await fits(page, label + ' ideas')
            assert.equal(
              await page.locator('.card-arrow').count(),
              admin ? 0 : await page.locator('.task-card').count(),
            )
            const counter = await page
              .locator('.section-block')
              .first()
              .locator('.section-title-group')
              .evaluate((node) => {
                const title = node.querySelector('.section-title').getBoundingClientRect(),
                  count = node.querySelector('.section-count').getBoundingClientRect()
                return { gap: count.x - title.right, sameLine: Math.abs(count.y - title.y) < 5 }
              })
            if (counter.sameLine)
              assert.ok(
                counter.gap >= 8 && counter.gap <= 14,
                label + ': counter adjacent to title',
              )
            assert.equal(
              await page.locator('.status-progress-dots').first().getAttribute('aria-hidden'),
              'true',
            )
            assert.deepEqual(
              await page
                .locator('.status-progress-dots')
                .first()
                .locator('span')
                .evaluateAll((nodes) => nodes.map((node) => getComputedStyle(node).animationName)),
              ['none', 'none', 'none'],
            )
            assert.ok(
              await page
                .locator('.tone-approved')
                .first()
                .evaluate((node) => getComputedStyle(node).outlineStyle !== 'none'),
            )
            if (admin) {
              assert.equal(await page.locator('.admin-project-header .small').count(), 0)
              await go(page, '/admin/settings/notifications')
              await page.locator('.personal-email form').waitFor()
              await fits(page, label + ' personal')
              assert.equal(await page.locator('.personal-notifications details').count(), 0)
              assert.equal(
                await page.locator('.personal-email,.personal-push,.personal-telegram').count(),
                3,
              )
              await page.locator('.telegram-settings').getByRole('status').waitFor()
              const capture =
                [1440, 390].includes(width) &&
                ((mode === 'light' && language === 'ru') || (mode === 'dark' && language === 'en'))
              if (capture) await f.screenshot('personal-' + label)
              await go(page, adminRoot + '/notifications')
              await page.locator('.notification-event-row').first().waitFor()
              await fits(page, label + ' project')
              assert.equal(
                await page
                  .locator(
                    '.project-event-settings #notification-email,.project-event-settings .telegram-settings,.project-event-settings .push-trigger',
                  )
                  .count(),
                0,
              )
              assert.equal(await page.locator('.notification-event-row').count(), 9)
              const save = page.locator('.project-event-settings button[type=submit]')
              assert.equal(await save.isDisabled(), true)
              if (capture) await f.screenshot('project-' + label)
            } else {
              for (const section of ['discussions', 'notifications', 'export']) {
                await go(page, `/project/${uuid}/${section}`)
                const navigation = width > 960 ? '.client-sidebar nav' : '.client-horizontal-nav'
                await page.locator(navigation).waitFor()
                await fits(page, label + ' ' + section)
                assert.equal(await page.locator(navigation + ' a').count(), 4)
                assert.equal(
                  await page.locator(navigation + ' a[aria-current=page]').getAttribute('href'),
                  `/project/${uuid}/${section}`,
                )
                assert.equal(
                  await page.locator('.client-back-link').getAttribute('href'),
                  `/project/${uuid}`,
                )
                assert.equal(await page.locator(navigation + ' a[href*="/admin"]').count(), 0)
                assert.equal(await page.locator('.client-menu-trigger').count(), 1)
                assert.equal(await page.locator(navigation + ' .unread-badge').innerText(), '3')
                if (
                  width === 320 &&
                  mode === 'light' &&
                  language === 'ru' &&
                  section === 'notifications'
                )
                  await f.screenshot('client-navigation-' + label)
              }
            }
            assert.deepEqual(f.errors, [])
            results.push({ ...config, status: 'passed' })
            console.log('PASS ' + label)
          } catch (error) {
            console.error('Browser detail', {
              url: page.url(),
              errors: f.errors,
              calls: f.channelCalls.slice(-5),
            })
            await f.screenshot('failure-' + label)
            throw error
          } finally {
            await f.context.close()
          }
        }
  // Functional flows: migration consent, global preferences, workspace switch and unchanged connections.
  const f = await fixture({ width: 1440, mode: 'light', language: 'en', admin: true }),
    { page } = f
  try {
    await go(page, adminRoot + '/notifications')
    const form = page.locator('.project-event-settings')
    await form.locator('.notification-event-row').first().waitFor()
    await form.locator('.notification-migration-note input').check()
    await form.locator('select').first().selectOption('selected')
    await form.locator('.notification-recipient-list input').first().check()
    await form.locator('button[type=submit]').click()
    await form.locator('.notification-migration-note').waitFor({ state: 'detached' })
    assert.equal(f.projectSettings().deliveryMode, 'personal')
    assert.deepEqual(f.projectSettings().eventRules['client.task_created'].recipients, {
      mode: 'selected',
      ids: [1],
    })
    scenarios.push('Explicit project migration and recipient selection')
    await routeTo(page, '/admin/settings/notifications')
    await page.locator('.personal-email form').waitFor()
    await page.locator('.personal-telegram .notification-toggle input').click()
    await page.waitForFunction(
      () => !document.querySelector('.personal-telegram .notification-toggle input').disabled,
    )
    assert.equal(f.personal().preferences.telegram, false)
    assert.equal(
      await page
        .locator('.telegram-settings button')
        .filter({ hasText: 'Test Telegram' })
        .isDisabled(),
      true,
    )
    assert.match(await page.locator('.telegram-settings').innerText(), /Telegram connected/)
    const before = await page.evaluate(() => window.__pushSubscribeCount)
    await routeTo(page, adminRoot + '/brief')
    await routeTo(page, '/admin/settings/notifications')
    await page.locator('.personal-email form').waitFor()
    assert.equal(
      await page.locator('.personal-telegram .notification-toggle input').isChecked(),
      false,
    )
    assert.equal(await page.evaluate(() => window.__pushSubscribeCount), before)
    assert.equal(f.channelCalls.filter((call) => call.path.endsWith('/telegram/connect')).length, 0)
    scenarios.push('Channels persist across projects; Telegram not rebound; Push not resubscribed')
    await page.locator('.sidebar-workspace select').first().selectOption('other-workspace')
    await page.waitForURL('**/admin')
    await routeTo(page, '/admin/settings/notifications')
    await page.locator('.personal-email form').waitFor()
    assert.equal(
      await page.locator('.personal-telegram .notification-toggle input').isChecked(),
      false,
    )
    scenarios.push('Personal preferences survive workspace switch')
    await page.locator('#personal-notification-email').fill('new@example.test')
    await page.locator('.personal-email button[type=submit]').click()
    await page.locator('.notification-pending-email').waitFor()
    assert.equal(f.personal().email, 'alex@example.test')
    await go(page, '/admin/settings/notifications#verify=' + 'a'.repeat(64))
    await page.locator('.personal-email form').waitFor()
    await page.waitForURL('**/admin/settings/notifications')
    await page.locator('.notification-pending-email').waitFor({ state: 'detached' })
    assert.equal(f.personal().email, 'new@example.test')
    scenarios.push('Email waits for proof; fragment token removed after verification')
    await page.emulateMedia({ reducedMotion: 'no-preference' })
    await go(page, adminRoot + '/brief')
    const chip = page.locator('.is-in-progress').first()
    await chip.waitFor()
    const sizes = []
    for (let i = 0; i < 4; i++) {
      sizes.push((await chip.boundingBox()).width)
      await page.waitForTimeout(500)
    }
    assert.ok(Math.max(...sizes) - Math.min(...sizes) < 0.1)
    assert.notEqual(
      await chip
        .locator('.status-progress-dots span')
        .nth(1)
        .evaluate((node) => getComputedStyle(node).animationName),
      'none',
    )
    await f.screenshot('status-chips-admin-1440-en-light')
    scenarios.push('In-progress animation reserves chip width; reduced-motion matrix is static')
    assert.deepEqual(f.errors, [])
  } finally {
    await f.context.close()
  }
  console.log(`PASS ${results.length} configurations and ${scenarios.length} functional scenarios`)
} catch (error) {
  console.error('FAILED', currentConfig, error)
  process.exitCode = 1
  results.push({ config: currentConfig, error: error.message })
} finally {
  await writeFile(
    new URL(quick ? 'browser-quick.json' : 'browser-results.json', output),
    JSON.stringify({ configurations: results, scenarios, realProviders: false }, null, 2) + '\n',
  )
  await browser?.close()
  await server.close()
}
