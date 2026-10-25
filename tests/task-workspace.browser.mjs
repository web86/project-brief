// PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/task-workspace.browser.mjs
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5187, strictPort: true },
  logLevel: 'error',
})
const output = process.env.TASK_WORKSPACE_OUTPUT_DIR
  ? new URL('file://' + process.env.TASK_WORKSPACE_OUTPUT_DIR.replace(/\/$/, '') + '/')
  : new URL('../docs/milestone-4-4/', import.meta.url)
await mkdir(output, { recursive: true })
const configurations = [
  { admin: true, width: 1440, language: 'ru', mode: 'light' },
  { admin: true, width: 1440, language: 'en', mode: 'dark' },
  { admin: true, width: 390, language: 'ru', mode: 'light' },
  { admin: true, width: 320, language: 'en', mode: 'system' },
  { admin: false, width: 1440, language: 'ru', mode: 'light' },
  { admin: false, width: 1440, language: 'en', mode: 'dark' },
  { admin: false, width: 390, language: 'ru', mode: 'light' },
  { admin: false, width: 320, language: 'en', mode: 'system' },
]
let browser
const results = []

try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const config of configurations) {
    const f = await taskWorkspaceFixture(browser, config, { output }),
      { page } = f
    const label = `${config.admin ? 'admin' : 'client'}-${config.width}-${config.language}-${config.mode}`
    const nav = page.locator('#project-task-navigation'),
      mobile = config.width <= 960
    const fit = async () =>
      assert.ok(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        label + ': no horizontal overflow',
      )
    assert.equal(await page.locator('.task-topbar .task-header-back').count(), 1)
    assert.equal(
      (await page.locator('.task-topbar').innerText()).includes(f.accountName),
      !config.admin && config.width > 768,
    )
    assert.equal(
      await page.locator('.admin-sidebar, .admin-project-header, .project-subnav').count(),
      0,
    )
    assert.equal(await page.locator('#project-task-navigation').count(), 1)
    assert.equal(await page.locator('.task-admin-trigger').count(), config.admin ? 1 : 0)
    assert.equal(await page.locator('.comments-panel').count(), 1)
    assert.equal(await page.locator('.history-panel').count(), 1)
    assert.equal(await page.locator('.developer-notes').count(), config.admin ? 1 : 0)
    await fit()
    const info = await page.locator('.task-context-row').boundingBox(),
      title = await page.locator('.task-heading h1').boundingBox()
    assert.ok(info.y + info.height <= title.y, 'Context row precedes numbered title')
    assert.match(await page.locator('.task-title-number').innerText(), /^#1\.1$/)
    if (config.admin) {
      assert.equal(await page.locator('.notes-disclosure').evaluate((node) => node.open), false)
      assert.equal(await page.locator('#developer-notes').isVisible(), false)
      assert.equal(await page.locator('#admin-drawer').evaluate((node) => node.open), false)
    } else assert.equal(await page.locator('#admin-drawer').count(), 0)
    const detailsBox = await page.locator('.details-surface').boundingBox()
    const commentsBox = await page.locator('.comments-panel').boundingBox()
    if (mobile) {
      assert.ok(
        detailsBox.width >= config.width - 48,
        'Mobile details occupy the full content column',
      )
      assert.ok(
        commentsBox.y >= detailsBox.y + detailsBox.height,
        'Mobile comments are below details',
      )
      assert.equal(
        await page.locator('.mobile-push-footer').count(),
        0,
        'Device settings live in the personal notification page, outside task details',
      )
    }
    await f.screenshot(`task-${label}`)
    await page.evaluate(() => scrollTo(0, 650))
    assert.ok((await page.locator('.task-topbar').boundingBox()).y >= 0)
    assert.ok((await page.locator('.task-topbar').boundingBox()).y < 2, 'Header stays sticky')
    await page.evaluate(() => scrollTo(0, 0))
    await f.openTasks()
    if (mobile) {
      await f.screenshot(`task-navigation-${label}`)
      await page.keyboard.press('Escape')
      await page.locator('#task-navigation-drawer').waitFor({ state: 'hidden' })
      assert.equal(
        await page
          .locator('#task-navigation-trigger')
          .evaluate((node) => node === document.activeElement),
        true,
      )
      await f.openTasks()
    }
    const section = nav.locator(`[aria-controls="task-navigation-${f.second.sectionId}"]`)
    assert.equal(
      await nav
        .locator(`[aria-controls="task-navigation-${f.first.sectionId}"]`)
        .getAttribute('aria-expanded'),
      'true',
    )
    await section.click()
    const gets = f.calls.filter(
      (call) => call.method === 'GET' && (call.path.endsWith('/tasks') || call.path.endsWith(uuid)),
    ).length
    await nav.locator(`a[href="${f.path(f.second.id)}"]`).click()
    await f.waitRoute(f.path(f.second.id))
    assert.equal(
      f.calls.filter(
        (call) =>
          call.method === 'GET' && (call.path.endsWith('/tasks') || call.path.endsWith(uuid)),
      ).length,
      gets,
    )
    assert.equal(
      await nav
        .locator(`[aria-controls="task-navigation-${f.second.sectionId}"]`)
        .getAttribute('aria-expanded'),
      'true',
    )
    assert.equal(
      await nav.locator(`a[href="${f.path(f.second.id)}"]`).getAttribute('aria-current'),
      'page',
    )
    if (mobile)
      assert.equal(
        await page.locator('#task-navigation-drawer').evaluate((node) => node.open),
        false,
      )
    await f.openTasks()
    await nav.locator(`a[href="${f.path(f.first.id)}"]`).click()
    await f.waitRoute(f.path(f.first.id))
    if (!mobile) {
      const before = await page.locator('.task-page').boundingBox()
      await nav.locator('.task-navigation-heading button').click()
      await page.locator('.task-navigation').waitFor({ state: 'hidden' })
      const after = await page.locator('.task-page').boundingBox()
      assert.ok(after.width > before.width + 200)
      assert.equal(
        await page
          .locator('#task-navigation-trigger')
          .evaluate((node) => node === document.activeElement),
        true,
      )
      await page.reload()
      await page.locator('.task-page h1').waitFor()
      assert.equal(
        await page.locator('.task-navigation').isVisible(),
        false,
        'Hide preference survives reload',
      )
      await page.locator('#task-navigation-trigger').click()
      await page.locator('.task-navigation').waitFor()
      assert.equal(
        await nav.evaluate((node) => node.contains(document.activeElement)),
        true,
        'Reopened desktop navigation receives focus',
      )
    }
    if (config.admin) {
      await page.locator('.notes-disclosure summary').click()
      await page
        .locator('#developer-notes')
        .fill('Сохранить несохранённый текст после сворачивания')
      const notesElement = await page.locator('#developer-notes').elementHandle()
      await page.locator('.notes-disclosure summary').click()
      assert.equal(await page.locator('#developer-notes').isVisible(), false)
      assert.equal(await notesElement.evaluate((element) => element.isConnected), true)
      assert.equal(
        await page.locator('#developer-notes').inputValue(),
        'Сохранить несохранённый текст после сворачивания',
      )
      await f.screenshot(`notes-collapsed-${label}`)
      await f.openTasks()
      const nextSection = nav.locator(`[aria-controls="task-navigation-${f.second.sectionId}"]`)
      if ((await nextSection.getAttribute('aria-expanded')) !== 'true') await nextSection.click()
      page.once('dialog', (dialog) => dialog.dismiss())
      await nav.locator(`a[href="${f.path(f.second.id)}"]`).click()
      assert.ok(page.url().endsWith(f.path(f.first.id)))
      assert.equal(
        await page.locator('#developer-notes').inputValue(),
        'Сохранить несохранённый текст после сворачивания',
      )
      if (mobile) await page.keyboard.press('Escape')
      const save = page.locator('.developer-save-actions .button.primary')
      if (config.width === 1440 && config.language === 'ru') {
        f.setSaveError(true)
        await save.click()
        await page.locator('.toast-error').first().waitFor()
        assert.equal(
          await page.locator('#developer-notes').inputValue(),
          'Сохранить несохранённый текст после сворачивания',
        )
        f.setSaveError(false)
      }
      await save.click()
      await page.locator('.developer-save-actions').waitFor({ state: 'detached' })
      assert.ok(
        f.calls.some(
          (call) =>
            call.method === 'PATCH' &&
            call.body.developerNotes === 'Сохранить несохранённый текст после сворачивания',
        ),
      )
      await page.locator('.notes-disclosure summary').click()
      await f.screenshot(`notes-open-${label}`)
      await page.locator('.notes-disclosure summary').click()
      const before = await page.locator('.task-page').boundingBox()
      await page.locator('.task-admin-trigger').click()
      const panel = page.locator('#admin-drawer[open]')
      await panel.waitFor()
      const during = await page.locator('.task-page').boundingBox()
      assert.equal(before.x, during.x)
      assert.equal(before.width, during.width)
      assert.ok((await panel.boundingBox()).x > 0, 'Management drawer is on the right')
      assert.equal(await panel.locator('.project-panel-nav a').count(), 8)
      assert.equal(await panel.locator('.discussion-badge').innerText(), '3')
      assert.equal(await panel.locator('.sidebar-workspace, .sidebar-project-group').count(), 0)
      assert.equal(await panel.locator('.task-all-projects').count(), 1)
      assert.equal(await panel.locator('.task-account strong').innerText(), f.accountName)
      for (let i = 0; i < 12; i++) {
        await page.keyboard.press(i % 2 ? 'Tab' : 'Shift+Tab')
        assert.ok(await panel.evaluate((node) => node.contains(document.activeElement)))
      }
      await f.screenshot(`management-${label}`)
      await page.keyboard.press('Escape')
      await panel.waitFor({ state: 'hidden' })
      assert.equal(
        await page
          .locator('.task-admin-trigger')
          .evaluate((node) => node === document.activeElement),
        true,
      )
      await page.locator('.task-admin-trigger').click()
      await page.mouse.click(2, 200)
      await page.locator('#admin-drawer').waitFor({ state: 'hidden' })
      if (mobile) {
        await f.openTasks()
        await page.evaluate(async () => {
          const { useTaskWorkspaceStore } = await import('/src/stores/taskWorkspace.js')
          const pinia = document.getElementById('app').__vue_app__.config.globalProperties.$pinia
          useTaskWorkspaceStore(pinia).openAdmin()
        })
        await page.locator('#admin-drawer[open]').waitFor()
        assert.equal(await page.locator('dialog[open]').count(), 1)
        await page.keyboard.press('Escape')
      }
    }
    if (config.admin && config.width === 1440 && config.language === 'ru') {
      const navigationElement = await nav.elementHandle()
      const getCount = f.calls.filter(
        (call) => call.method === 'GET' && call.path.endsWith('/tasks'),
      ).length
      const pushCount = f.calls.filter((call) => call.path.endsWith('/push/status')).length
      await page.setViewportSize({ width: 390, height: 844 })
      await page.waitForFunction(
        () => !!document.querySelector('#task-navigation-drawer-body #project-task-navigation'),
      )
      await page.locator('#task-navigation-trigger').click()
      await page.locator('#task-navigation-drawer[open]').waitFor()
      await page.setViewportSize({ width: 1024, height: 768 })
      await page.locator('#task-navigation-drawer').waitFor({ state: 'hidden' })
      await page.locator('.task-navigation').waitFor()
      assert.equal(await navigationElement.evaluate((node) => node.isConnected), true)
      assert.equal(await page.locator('#project-task-navigation').count(), 1)
      assert.equal(
        f.calls.filter((call) => call.method === 'GET' && call.path.endsWith('/tasks')).length,
        getCount,
      )
      assert.equal(f.calls.filter((call) => call.path.endsWith('/push/status')).length, pushCount)
      await fit()
      await page.emulateMedia({ reducedMotion: 'no-preference' })
      await page.locator('.task-admin-trigger').click()
      await page.locator('#admin-drawer[open]').waitFor()
      assert.equal(
        await page
          .locator('#admin-drawer')
          .evaluate((node) => getComputedStyle(node).animationName),
        'task-management-enter',
      )
      await f.screenshot('management-admin-1024-animation')
      await page.keyboard.press('Escape')
      await page.setViewportSize({ width: 1440, height: 1050 })
      await page.emulateMedia({ reducedMotion: 'reduce' })
    }
    if (!config.admin && config.width === 1440) {
      await page.setViewportSize({ width: 1024, height: 768 })
      const pageBox = await page.locator('.task-page').boundingBox()
      const details = await page.locator('.details-surface').boundingBox()
      const comments = await page.locator('.comments-panel').boundingBox()
      assert.ok(
        details.width >= pageBox.width - 64,
        'Client tablet details fill the content column',
      )
      assert.ok(comments.y >= details.y + details.height)
      await page.setViewportSize({ width: 1440, height: 1050 })
    }
    if (config.admin && config.width === 1440 && config.language === 'ru') {
      const release = f.holdNotifications()
      await page.locator('.task-admin-trigger').click()
      await page.locator('#admin-drawer[open]').waitFor()
      await page.locator('.project-panel-nav a[href$="/notifications"]').click()
      await page.locator('.loading-overlay').waitFor()
      assert.equal(
        await page.locator('dialog[open]').count(),
        0,
        'Accepted navigation closes native top-layer panels before data loading',
      )
      release()
      await f.waitRoute(`${adminRoot}/notifications`)
      await page.goBack()
      await f.waitRoute(f.path(f.first.id))
    }
    await page.locator('#comment-text').fill('Новое сообщение с детальной страницы')
    await page.locator('.comment-form button[type="submit"]').click()
    await page
      .locator('.comment-list')
      .getByText('Новое сообщение с детальной страницы', { exact: true })
      .waitFor()
    assert.equal(await page.locator('.comment-form').count(), 1)
    assert.ok(
      f.calls.some(
        (call) => call.path.endsWith('/comments') && Object.keys(call.body).join() === 'text',
      ),
    )
    await page.locator('.task-header-back').click()
    await f.waitRoute(config.admin ? `${adminRoot}/brief` : `/project/${uuid}`)
    await page.locator('.dashboard').waitFor()
    if (config.admin) {
      assert.equal(await page.locator('.project-subnav').count(), 0)
      assert.equal(await page.locator('.admin-sidebar .project-panel-nav').count(), 1)
      assert.equal(await page.locator('.task-admin-trigger').count(), 0)
      assert.equal(await page.locator('.admin-sidebar').isVisible(), !mobile)
    }
    assert.deepEqual(f.errors, [])
    results.push({ ...config, passed: true, projectTaskFetches: gets })
    await f.context.close()
  }
  await writeFile(
    new URL('results.json', output),
    JSON.stringify({ results, passed: results.length, api: 'mocked' }, null, 2) + '\n',
  )
  console.log(JSON.stringify({ passed: results.length }))
} finally {
  await browser?.close()
  await server.close()
}
