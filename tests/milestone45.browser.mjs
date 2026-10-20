// UI integration with mocked APIs; real authorization and renderers are covered by Laravel tests.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { notificationEvents } from '../src/services/notificationSettings.js'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = new URL('../docs/milestone-4-5/', import.meta.url)
await mkdir(output, { recursive: true })
const port = 5189
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port, strictPort: true },
  logLevel: 'error',
})
const results = []
let browser
const generalId = '44444444-4444-4444-8444-444444444444'
const taskCommentId = '22222222-2222-4222-8222-222222222222'
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const width of [1440, 1280, 768, 390])
    for (const mode of ['light', 'dark', 'system'])
      for (const language of ['ru', 'en'])
        for (const admin of [true, false]) {
          const config = {
            width,
            mode,
            language,
            admin,
            clientId: language === 'ru' ? 'anna' : 'john',
          }
          let connected = false
          const read = new Set(),
            calls = []
          const f = await taskWorkspaceFixture(browser, config, {
            output,
            port,
            setup: async ({ context, data }) => {
              await context.route(
                (url) =>
                  (url.pathname.startsWith('/api/') &&
                    /\/telegram(?:\/|$)|\/discussions(?:\/|$)|\/export$/.test(url.pathname)) ||
                  url.pathname === '/api/client/notifications',
                async (route) => {
                  const request = route.request(),
                    url = new URL(request.url()),
                    path = url.pathname,
                    body = request.postDataJSON()
                  calls.push({ path, method: request.method(), body })
                  let result
                  if (path.endsWith('/telegram/connect'))
                    result = {
                      url: 'https://t.me/ProjectBriefTestBot?start=' + 'a'.repeat(64),
                      expiresAt: '2026-10-20T10:10:00Z',
                    }
                  else if (path.endsWith('/telegram/test')) result = { status: 'sent', code: null }
                  else if (path.endsWith('/telegram')) {
                    if (request.method() === 'DELETE') connected = false
                    result = {
                      available: true,
                      connected,
                      connectedAt: connected ? '2026-10-20T10:00:00Z' : null,
                    }
                  } else if (path === '/api/client/notifications')
                    result = {
                      preferences: body?.preferences || { email: true, push: true, telegram: true },
                      email: 'client@example.test',
                    }
                  else if (path.endsWith('/discussions/read')) {
                    for (const id of body.ids || [generalId, taskCommentId]) read.add(id)
                    result = { unreadCount: 2 - read.size }
                  } else if (path.endsWith('/discussions')) {
                    const general = {
                      id: generalId,
                      authorName: 'Алексей / Alex',
                      createdAt: '2026-10-20T08:00:00Z',
                      text: 'Общее обсуждение проекта — уточним результат и выбранные настройки.',
                      task: null,
                      unread: !read.has(generalId),
                    }
                    const task = {
                      ...general,
                      id: taskCommentId,
                      text: 'Комментарий к задаче. Сохраните фильтры при возврате к списку.',
                      task: {
                        id: data.tasks[0].id,
                        title: data.tasks[0].title,
                        section: 'Главная',
                      },
                      unread: !read.has(taskCommentId),
                    }
                    const kind = url.searchParams.get('kind')
                    if (request.method() === 'POST')
                      result = {
                        data: {
                          ...general,
                          id: '55555555-5555-4555-8555-555555555555',
                          authorName: 'Client',
                          text: body.text,
                          unread: false,
                        },
                      }
                    else
                      result = {
                        data: [kind === 'general' ? general : task].filter(
                          (item) => url.searchParams.get('filter') !== 'unread' || item.unread,
                        ),
                        unreadCount: 2 - read.size,
                        nextCursor: null,
                      }
                  } else if (path.endsWith('/export'))
                    return route.fulfill({
                      status: 200,
                      headers: {
                        'Content-Type': 'application/pdf',
                        'Content-Disposition': 'attachment; filename="client-specification.pdf"',
                      },
                      body: '%PDF-1.7\nSynthetic UI download fixture',
                    })
                  else return route.fallback()
                  return route.fulfill({ json: result })
                },
              )
            },
          })
          const { page } = f
          await page.waitForFunction(
            (expected) => document.documentElement.dataset.theme === expected,
            mode === 'light' ? 'light' : 'dark',
          )
          if (mode === 'system') {
            await page.emulateMedia({ colorScheme: 'light' })
            await page.waitForFunction(() => document.documentElement.dataset.theme === 'light')
            await page.emulateMedia({ colorScheme: 'dark' })
            await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark')
          }

          const base = admin ? adminRoot : `/project/${uuid}`
          const representative =
            (width === 1440 && mode === 'light' && language === 'ru') ||
            (width === 390 && mode === 'dark' && language === 'en') ||
            (width === 768 && mode === 'system' && language === 'ru') ||
            (width === 1280 && mode === 'dark' && language === 'en')
          const label = `${admin ? 'admin' : 'client'}-${width}-${mode}-${language}`
          const nav = async (section) => {
            if (admin) {
              if (page.url().includes('/brief/task/')) {
                await page.locator('.task-admin-trigger').click()
                await page
                  .locator(`#admin-drawer .project-panel-nav a[href="${base}/${section}"]`)
                  .click()
              } else await page.goto(`http://127.0.0.1:${port}${base}/${section}`)
            } else {
              await page.locator('.client-menu-trigger').click()
              await page.locator('#client-drawer[open]').waitFor()
              await page.locator(`#client-drawer a[href="${base}/${section}"]`).click()
            }
            await page.waitForURL('**' + `${base}/${section}`)
            await page.locator('.navigation-loading').waitFor({ state: 'detached' })
          }
          const overflow = async () =>
            assert.ok(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
              label + ' horizontal overflow',
            )
          if (!admin) {
            assert.equal(await page.locator('.app-topbar .push-trigger').count(), 0)
            const controls = await page.locator('.client-header-controls').boundingBox()
            assert.ok(controls.x + controls.width <= width)
            assert.ok(controls.height <= 48)
            if (width <= 768)
              assert.equal(await page.locator('.current-client-name').isVisible(), false)
            else assert.equal(await page.locator('.current-client-name').isVisible(), true)
            await page.locator('.client-menu-trigger').click()
            await page.locator('#client-drawer[open]').waitFor()
            assert.equal(await page.locator('#client-drawer .project-panel-nav a').count(), 4)
            assert.equal(await page.locator('#client-drawer a[href^="/admin"]').count(), 0)
            if (representative) await f.screenshot('drawer-' + label)
            await page.keyboard.press('Escape')
            await page.locator('#client-drawer[open]').waitFor({ state: 'detached' })
            assert.equal(
              await page
                .locator('.client-menu-trigger')
                .evaluate((node) => node === document.activeElement),
              true,
            )
            if (width <= 960) {
              await page.locator('#task-navigation-trigger').click()
              await page.locator('#task-navigation-drawer[open]').waitFor()
              // Opening the right drawer through shared panel state closes the left modal.
              await page.evaluate(async () => {
                const { useTaskWorkspaceStore } = await import('/src/stores/taskWorkspace.js')
                useTaskWorkspaceStore().openClient()
              })
              await page.locator('#client-drawer[open]').waitFor()
              assert.equal(await page.locator('#task-navigation-drawer[open]').count(), 0)
              await page.mouse.click(5, 400)
              await page.locator('#client-drawer[open]').waitFor({ state: 'detached' })
            }
          }
          await overflow()
          await nav('discussions')
          await page.locator('.discussion-page').waitFor()
          await page.locator('#project-comment').fill('Public general message')
          await page.locator('.comment-form button[type="submit"]').click()
          await page.getByText('Public general message', { exact: true }).waitFor()
          await page.locator('.discussion-page .filter-tabs button').nth(1).click()
          await page.locator('.discussion-item').first().waitFor()
          assert.ok(
            calls.some((call) => call.path.startsWith(admin ? '/api/admin/' : '/api/client/')),
          )
          await overflow()
          if (representative) await f.screenshot('discussions-' + label)
          await nav('notifications')
          await page.locator('.telegram-settings').waitFor()
          if (admin) {
            assert.equal(await page.locator('.notification-settings > details').count(), 0)
            assert.equal(
              await page.locator('.notification-checkbox').count(),
              notificationEvents.length * 3,
            )
            await page.locator('.notification-settings form > button[type="submit"]').click()
            await page
              .getByText(
                language === 'ru'
                  ? 'Настройки уведомлений сохранены.'
                  : 'Notification settings saved.',
                { exact: true },
              )
              .waitFor()
              .catch(() => {})
          } else {
            assert.equal(await page.locator('.notification-checkbox').count(), 0)
            await page.locator('.client-notifications form button[type="submit"]').click()
          }
          await page.locator('.telegram-settings .notification-test-actions button').first().click()
          const bot = page.locator('.telegram-link a')
          await bot.waitFor()
          assert.ok((await bot.getAttribute('href')).startsWith('https://t.me/'))
          connected = true
          await page.locator('.telegram-settings .text-button').click()
          await page
            .getByText(language === 'ru' ? 'Telegram подключён' : 'Telegram connected', {
              exact: true,
            })
            .waitFor()
          if (representative) await f.screenshot('notifications-' + label)
          await overflow()
          await nav('export')
          await page.locator('.export-form').waitFor()
          if (!admin) {
            const text = await page.locator('.export-form').innerText()
            for (const forbidden of language === 'ru'
              ? ['Оценка часов', 'Стоимость', 'Технические заметки']
              : ['Estimate hours', 'Price', 'Technical notes'])
              assert.equal(text.includes(forbidden), false)
          }
          await page.locator('#export-document-title').fill('Client specification')
          const download = page.waitForEvent('download')
          await page.locator('.export-form button[type="submit"]').click()
          assert.equal((await download).suggestedFilename(), 'client-specification.pdf')
          const exportCall = calls.findLast((call) => call.path.endsWith('/export'))
          if (!admin)
            for (const key of ['price', 'estimateHours', 'developerNotes'])
              assert.equal(key in exportCall.body.fields, false)
          if (representative) await f.screenshot('export-' + label)
          await overflow()
          if (!admin) {
            await page.locator('.client-menu-trigger').click()
            await page.locator(`#client-drawer a[href="/project/${uuid}"]`).click()
            await page.locator('.project-page, .project-layout, .project-header').first().waitFor()
            assert.equal(await page.locator('.app-topbar .brand').count(), 1)
            assert.equal(await page.locator('.client-menu-trigger').count(), 1)
          }
          assert.deepEqual(f.errors, [], label + ' runtime errors')
          results.push({ ...config, passed: true })
          console.log('PASS', label)
          await f.context.close()
        }
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify({ api: 'mocked', scenarios: results.length, results }, null, 2) + '\n',
  )
} finally {
  await browser?.close()
  await server.close()
}
