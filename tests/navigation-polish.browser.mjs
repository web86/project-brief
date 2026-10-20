// PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/navigation-polish.browser.mjs
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const before = process.env.NAVIGATION_POLISH_BEFORE === '1'
const output = new URL('../docs/milestone-4-4-1/', import.meta.url)
await mkdir(output, { recursive: true })
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5188, strictPort: true },
  logLevel: 'error',
})
const configurations = [
  { admin: true, width: 1440, language: 'ru', mode: 'light' },
  { admin: true, width: 1280, language: 'en', mode: 'dark' },
  { admin: true, width: 1024, language: 'en', mode: 'light' },
  { admin: true, width: 390, language: 'ru', mode: 'dark' },
  { admin: false, width: 1440, language: 'en', mode: 'dark' },
  { admin: false, width: 1280, language: 'ru', mode: 'light' },
  { admin: false, width: 390, language: 'en', mode: 'light' },
  ...(!before
    ? [
        { admin: true, width: 390, language: 'en', mode: 'light', role: 'admin', short: true },
        { admin: true, width: 1280, language: 'ru', mode: 'dark', longNames: true, longList: true },
        {
          admin: false,
          width: 1024,
          language: 'ru',
          mode: 'dark',
          longNames: true,
          longList: true,
        },
      ]
    : []),
]
let browser
const results = []
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const config of configurations) {
    const f = await taskWorkspaceFixture(browser, config, { output, port: 5188 })
    const { page } = f
    await page.setViewportSize({
      width: config.width,
      height: config.short ? 600 : config.width > 960 ? 650 : 844,
    })
    const label = `${config.admin ? 'admin' : 'client'}-${config.width}-${config.language}-${config.mode}${config.longNames ? '-long' : ''}`
    const phase = before ? 'before' : 'after'
    await f.screenshot(`${phase}-task-${label}`)
    if (config.admin) {
      await page.locator('.task-admin-trigger').click()
      await page.locator('#admin-drawer[open]').waitFor()
      await f.screenshot(`management-${phase}-${label}`)
      if (!before) {
        const panel = page.locator('#admin-drawer[open]')
        assert.equal(
          await panel
            .locator(
              '.sidebar-workspace, .sidebar-create, .sidebar-project-group, .admin-sidebar-content',
            )
            .count(),
          0,
        )
        assert.equal(await panel.locator('.project-panel-nav a').count(), 7)
        assert.equal(await panel.locator('.discussion-badge').innerText(), '3')
        assert.equal(await panel.locator('.task-all-projects').count(), 1)
        assert.equal(await panel.locator('.task-account strong').innerText(), f.accountName)
        assert.equal(await panel.locator('.task-account a').count(), 0)
        assert.equal(
          await panel.locator('.task-account-details > span').innerText(),
          config.role === 'admin' ? 'Admin' : config.language === 'ru' ? 'Владелец' : 'Owner',
        )
        const scroll = await panel.evaluate((node) => ({
          nested: [...node.querySelectorAll('*')]
            .filter(
              (child) =>
                /auto|scroll/.test(getComputedStyle(child).overflowY) &&
                child.scrollHeight > child.clientHeight + 1,
            )
            .map((child) => child.className),
          scrollable: node.scrollHeight > node.clientHeight,
        }))
        assert.deepEqual(
          scroll.nested,
          [],
          'The management panel has no independent inner scroll regions',
        )
        if (config.short || config.width > 960)
          assert.equal(scroll.scrollable, true, 'Short windows scroll the entire dialog')
        await panel.evaluate((node) => {
          node.scrollTop = node.scrollHeight
        })
        const profile = await panel.locator('.task-account').boundingBox()
        assert.ok(
          profile.y >= 0 && profile.y + profile.height <= (await page.viewportSize()).height,
        )
        if (config.short || config.longNames) await f.screenshot(`management-after-bottom-${label}`)
        await panel.locator('.task-account button').focus()
        for (let i = 0; i < 20; i++) {
          await page.keyboard.press('Tab')
          assert.ok(await panel.evaluate((node) => node.contains(document.activeElement)))
        }
      }
      await page.keyboard.press('Escape')
    }
    if (!before) {
      const mobile = config.width <= 960
      const navigation = page.locator('#project-task-navigation')
      assert.equal(await navigation.count(), 1)
      assert.ok(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        label,
      )
      assert.equal(await page.locator('.task-header-back').isVisible(), true)
      assert.equal(await page.locator('.breadcrumbs').count(), 0)
      if (!mobile) {
        const aside = await page.locator('.task-navigation').boundingBox()
        const header = await page.locator('.task-topbar').boundingBox()
        assert.equal(aside.y, 0)
        assert.equal(aside.x, 0)
        assert.equal(header.x, aside.width)
        assert.equal(aside.height, 650)
        assert.equal(
          await navigation.locator('.brand').getAttribute('href'),
          config.admin ? '/admin' : `/project/${uuid}`,
        )
        await navigation.locator('.task-navigation-heading button').click()
        assert.equal((await page.locator('.task-topbar').boundingBox()).x, 0)
        await page.locator('#task-navigation-trigger').click()
        assert.equal((await page.locator('.task-topbar').boundingBox()).x, aside.width)
        if (config.longList)
          assert.ok(
            await page
              .locator('.task-navigation-sections')
              .evaluate((node) => node.scrollHeight > node.clientHeight),
          )
      } else {
        assert.ok((await page.locator('.task-topbar').boundingBox()).height <= 70)
        await f.openTasks()
        assert.equal(
          await navigation.locator('.brand').isVisible(),
          false,
          'Drawer logo adds no mobile header row',
        )
        await f.screenshot(`task-navigation-after-${label}`)
        await page.keyboard.press('Escape')
      }
      const calls = f.calls.filter(
        (call) => call.method === 'GET' && call.path.endsWith('/tasks'),
      ).length
      await f.openTasks()
      await navigation.locator(`[aria-controls="task-navigation-${f.second.sectionId}"]`).click()
      await navigation.locator(`a[href="${f.path(f.second.id)}"]`).click()
      await f.waitRoute(f.path(f.second.id))
      assert.equal(
        f.calls.filter((call) => call.method === 'GET' && call.path.endsWith('/tasks')).length,
        calls,
      )
      await f.openTasks()
      await navigation.locator(`a[href="${f.path(f.first.id)}"]`).click()
      await f.waitRoute(f.path(f.first.id))
      await page.evaluate(() => scrollTo(0, 650))
      assert.ok(
        Math.abs((await page.locator('.task-topbar').boundingBox()).y) < 2,
        'Header remains sticky',
      )
      await page.evaluate(() => scrollTo(0, 0))
      if (config.admin) {
        const crumbs = page.locator('.breadcrumbs')
        assert.equal(await crumbs.count(), 0)
        if ((config.width === 1440 && config.language === 'ru') || config.short) {
          await page.locator('.notes-disclosure summary').click()
          await page.locator('#developer-notes').fill('NAVIGATION_POLISH_DRAFT')
          page.once('dialog', (dialog) => dialog.dismiss())
          await page.locator('.task-header-back').click()
          assert.ok(page.url().endsWith(f.path(f.first.id)))
          assert.equal(
            await page.locator('#developer-notes').inputValue(),
            'NAVIGATION_POLISH_DRAFT',
          )
          await page.locator('.task-admin-trigger').click()
          page.once('dialog', (dialog) => dialog.dismiss())
          await page.locator('#admin-drawer .task-account button').click()
          assert.equal(
            f.calls.some((call) => call.path === '/api/admin/logout'),
            false,
          )
          await page.keyboard.press('Escape')
          await page.keyboard.press('Control+s')
          await page.locator('.developer-save-actions').waitFor({ state: 'detached' })
        }
        await page.locator('.task-header-back').click()
        await f.waitRoute(`${adminRoot}/brief`)
        assert.equal(await crumbs.locator('li').count(), 2)
        for (const section of [
          'clients',
          'discussions',
          'structure',
          'notifications',
          'settings',
          'export',
        ]) {
          await page.locator(`.project-subnav a[href$="/${section}"]`).click()
          await f.waitRoute(`${adminRoot}/${section}`)
          assert.equal(await crumbs.locator('li').count(), 3)
          assert.equal(await crumbs.locator('li:last-child a').count(), 0)
        }
        await f.screenshot(`after-breadcrumbs-${label}`)
        await page.locator('.project-subnav a[href$="/brief"]').click()
        await f.waitRoute(`${adminRoot}/brief`)
        await page.locator(`.task-card[href="${f.path(f.first.id)}"]`).click()
        await f.waitRoute(f.path(f.first.id))
        await page.locator('.task-admin-trigger').click()
        await page.locator('#admin-drawer .task-all-projects').click()
        await f.waitRoute('/admin')
        assert.equal(await crumbs.count(), 0)
        const openGlobal = async () => {
          if (mobile) {
            await page.locator('.admin-menu-trigger').click()
            await page.locator('#admin-drawer[open]').waitFor()
          }
          return page.locator(mobile ? '#admin-drawer[open]' : '.admin-sidebar')
        }
        let global = await openGlobal()
        assert.equal(await global.locator('.sidebar-workspace select option').count(), 2)
        assert.equal(await global.locator('.sidebar-project').count(), 25)
        await global.locator('.workspace-help').focus()
        assert.equal(await global.locator('.workspace-tooltip').isVisible(), true)
        await f.screenshot(`management-global-${label}`)
        await global.locator('.sidebar-create').click()
        await f.waitRoute('/admin/projects/new')
        assert.equal(await crumbs.locator('li').count(), 2)
        await crumbs.locator('a').click()
        await f.waitRoute('/admin')
        global = await openGlobal()
        await global.locator('.sidebar-workspace select').selectOption('other-workspace')
        await page.waitForFunction(() =>
          [...document.querySelectorAll('.sidebar-workspace select')].every(
            (node) => node.value === 'other-workspace',
          ),
        )
        if (mobile) await page.locator('#admin-drawer').waitFor({ state: 'hidden' })
        assert.ok(
          f.calls.some(
            (call) =>
              call.path === '/api/admin/workspaces/other-workspace/select' &&
              call.method === 'POST',
          ),
        )
        global = await openGlobal()
        await global.locator('.sidebar-project[href$="/project-0/brief"]').click()
        await f.waitRoute('/admin/projects/project-0/brief')
        assert.equal(
          await crumbs.locator('[aria-current="page"]').innerText(),
          'Другой выбранный проект',
        )
        assert.equal((await crumbs.innerText()).includes('project-0'), false)
      } else {
        await page.locator('.task-header-back').click()
        await f.waitRoute(`/project/${uuid}`)
        assert.equal(await page.locator('.breadcrumbs').count(), 0)
        assert.equal(await page.locator('.admin-sidebar').count(), 0)
      }
    }
    assert.deepEqual(f.errors, [])
    results.push({ ...config, phase, passed: true })
    await f.context.close()
  }
  await writeFile(
    new URL(`${before ? 'before' : 'results'}.json`, output),
    JSON.stringify(results, null, 2) + '\n',
  )
  console.log(`PASS: navigation polish ${before ? 'baseline' : 'scenarios'} (${results.length})`)
} finally {
  await browser?.close()
  await server.close()
}
