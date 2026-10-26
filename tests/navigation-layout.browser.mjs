import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = process.env.M471_OUTPUT_DIR
  ? new URL('file://' + process.env.M471_OUTPUT_DIR.replace(/\/$/, '') + '/')
  : new URL('../docs/milestone-4-7-1/', import.meta.url)
await mkdir(output, { recursive: true })
const port = 5194,
  origin = `http://127.0.0.1:${port}`
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port, strictPort: true },
  logLevel: 'error',
})
const sections = [
  'brief',
  'discussions',
  'clients',
  'structure',
  'notifications',
  'settings',
  'export',
]
const results = []
let browser
async function fixture(config) {
  let selected = 'studio',
    denied = false
  const trash = []
  const requests = []
  const user = {
    notificationPreferenceId: 'admin-fixture-id',
    name: config.language === 'ru' ? 'Алексей Морозов' : 'Alex Morgan',
    email: 'admin@example.test',
    preferredLocale: config.language,
    membershipRole: config.role,
  }
  const workspaces = [
    { id: 'studio', name: 'Design Studio' },
    { id: 'other-workspace', name: 'Other workspace' },
  ]
  const f = await taskWorkspaceFixture(browser, config, {
    output,
    port,
    setup: async ({ context, data, project }) => {
      data.tasks[1].sectionId = data.tasks[0].sectionId
      for (const section of data.sections)
        data.tasks
          .filter((task) => task.sectionId === section.id)
          .sort((a, b) => a.position - b.position)
          .forEach((task, i) => (task.position = i + 1))
      trash.push({
        ...data.tasks[1],
        id: 'saved-trash-idea',
        title: config.language === 'ru' ? 'Сохранённая идея из корзины' : 'Saved idea from trash',
        status: 'new',
        clientApproved: false,
        sectionId: data.sections[0].id,
        position: 1,
      })
      await context.route('**/api/**', async (route) => {
        const request = route.request(),
          path = new URL(request.url()).pathname
        requests.push({ path, method: request.method() })
        if (denied && path.startsWith(`/api/admin/projects/${uuid}`))
          return route.fulfill({ status: 404, json: { message: 'Unavailable' } })
        if (path.endsWith('/select')) {
          selected = path.split('/').at(-2)
          return route.fulfill({ json: { message: 'Selected' } })
        }
        if (path === '/api/admin/me')
          return route.fulfill({
            json: { user, workspace: workspaces.find((item) => item.id === selected) },
          })
        if (path === '/api/admin/workspaces') return route.fulfill({ json: { data: workspaces } })
        if (path === '/api/admin/projects/trash') return route.fulfill({ json: { data: [] } })
        if (path === '/api/admin/projects')
          return route.fulfill({
            json: { data: selected === 'studio' && !denied ? [project] : [] },
          })
        if (path === '/api/admin/notifications')
          return route.fulfill({
            json: {
              user,
              workspaces,
              preferences: { email: true, push: true, telegram: true },
              email: 'admin@example.test',
              accountEmail: 'admin@example.test',
              emailAvailable: false,
              pendingEmail: null,
              emailUnified: false,
              legacyEmails: [],
            },
          })
        if (path.endsWith('/trash')) return route.fulfill({ json: { data: trash } })
        if (path.endsWith('/restore')) {
          const task = trash.shift()
          const items = data.tasks
            .filter((item) => item.sectionId === task.sectionId)
            .sort((a, b) => a.position - b.position)
          items.splice(Math.min(task.position - 1, items.length), 0, task)
          items.forEach((item, i) => (item.position = i + 1))
          data.tasks.push(task)
          return route.fulfill({ json: { data: task } })
        }
        if (path.endsWith('/place')) {
          const body = request.postDataJSON(),
            id = path.split('/').at(-2),
            task = data.tasks.find((item) => item.id === id)
          const previousSection = task.sectionId
          const items = data.tasks
            .filter((item) => item.sectionId === body.sectionId && item.id !== id)
            .sort((a, b) => a.position - b.position)
          let index = items.length
          if (body.beforeTaskId || body.afterTaskId)
            index =
              items.findIndex((item) => item.id === (body.beforeTaskId || body.afterTaskId)) +
              (body.afterTaskId ? 1 : 0)
          task.sectionId = body.sectionId
          items.splice(index, 0, task)
          items.forEach((item, i) => (item.position = i + 1))
          if (previousSection !== body.sectionId)
            data.tasks
              .filter((item) => item.sectionId === previousSection)
              .sort((a, b) => a.position - b.position)
              .forEach((item, i) => (item.position = i + 1))
          return route.fulfill({ json: { data: data.tasks } })
        }
        return route.fallback()
      })
    },
  })
  return {
    ...f,
    requests,
    deny: () => {
      denied = true
    },
  }
}
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const width of [1440, 1280, 768, 390, 320])
    for (const language of ['ru', 'en'])
      for (const mode of ['light', 'dark', 'system']) {
        const config = {
          width,
          language,
          mode,
          admin: true,
          role: language === 'en' ? 'admin' : 'owner',
          longNames: mode === 'system' || width === 320,
        }
        if (process.env.M471_CASE && process.env.M471_CASE !== `${width}-${language}-${mode}`)
          continue
        const f = await fixture(config),
          { page, data } = f
        const stamp = `${width}-${language}-${mode}`,
          mobileDrawer = width <= 960
        const overflow = async () =>
          assert.equal(
            await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            false,
            stamp + ' overflows',
          )
        async function openMenu() {
          if (await page.locator('.task-admin-trigger').count())
            await page.locator('.task-admin-trigger').click()
          else if (mobileDrawer) await page.locator('.admin-menu-trigger').click()
          if (mobileDrawer || (await page.locator('.task-admin-trigger').count()))
            await page.locator('#admin-drawer[open]').waitFor()
        }
        const menu = () =>
          mobileDrawer
            ? page.locator('#admin-drawer .project-panel-nav')
            : page.locator('.admin-sidebar .project-panel-nav')
        async function go(section) {
          await openMenu()
          await menu().locator(`a[href$="/${section}"]`).click()
          await page.waitForURL(`**${adminRoot}/${section}`)
          await page.locator('#admin-drawer[open]').waitFor({ state: 'hidden' })
          assert.equal(
            await menu().locator(`a[href$="/${section}"]`).getAttribute('aria-current'),
            'page',
          )
          assert.equal(await page.locator('.project-subnav').count(), 0)
          assert.equal(await page.locator('main h1').count(), 1)
          await overflow()
        }
        await page.goto(origin + adminRoot + '/brief')
        await page.locator('.dashboard').waitFor()
        assert.equal(await page.locator('.sidebar-project-group').count(), 0)
        assert.equal(await page.locator('.project-subnav').count(), 0)
        assert.equal(await page.locator('main h1').count(), 1)
        assert.equal(await page.locator('.project-trash-trigger').count(), 0)
        assert.equal(await page.locator('#project-trash-title').count(), 1)
        const intro = await page.locator('.admin-project-overview .project-intro').boundingBox(),
          overview = await page.locator('.admin-project-overview .project-overview').boundingBox()
        if (width >= 768) {
          assert.ok(
            Math.abs(intro.width - overview.width) < 2,
            stamp + ' header uses equal columns',
          )
          assert.ok(overview.x > intro.x)
        } else assert.ok(overview.y >= intro.y + intro.height)
        await f.screenshot('project-' + stamp)
        await openMenu()
        const projectMenu = mobileDrawer ? page.locator('#admin-drawer .project-panel-nav') : menu()
        assert.equal(await projectMenu.locator('a').count(), language === 'en' ? 7 : 8)
        assert.equal(await projectMenu.locator('.discussion-badge').innerText(), '3')
        assert.equal(
          await projectMenu.locator('button:last-child').getAttribute('class'),
          'project-trash-link',
        )
        assert.equal(
          await projectMenu.locator('[href$="/team"]').count(),
          language === 'en' ? 0 : 1,
        )
        if (mobileDrawer) {
          await page.screenshot({ path: new URL('menu-' + stamp + '.png', output).pathname })
          await page.keyboard.press('Escape')
          assert.equal(
            await page
              .locator('.admin-menu-trigger')
              .evaluate((node) => node === document.activeElement),
            true,
          )
        }
        // Both controls share the status selection; shrinking resets the hidden section.
        if (width > 640) {
          await page.selectOption('#section-filter', data.sections[0].id)
          await page.locator('.filter-tabs button').last().click()
          await page.setViewportSize({ width: 390, height: 844 })
          await page.locator('#idea-status-filter').waitFor()
          assert.equal(await page.locator('#idea-status-filter').inputValue(), 'done')
          await page.locator('#section-filter').waitFor({ state: 'detached' })
          assert.equal(await page.locator('#section-filter').count(), 0)
          await page.selectOption('#idea-status-filter', 'all')
          assert.equal(await page.locator('.task-card').count(), data.tasks.length)
          assert.equal(await page.locator('.task-grip:enabled').count(), data.tasks.length)
          await page.setViewportSize({ width, height: width > 960 ? 1050 : 844 })
          await page.locator('#section-filter').waitFor()
          assert.equal(await page.locator('#section-filter').inputValue(), '')
          assert.equal(
            await page.locator('.filter-tabs button').first().getAttribute('aria-pressed'),
            'true',
          )
        } else {
          assert.equal(await page.locator('#idea-status-filter option').count(), 5)
          await page.selectOption('#idea-status-filter', 'done')
          await page.waitForFunction(
            (count) => document.querySelectorAll('.task-card').length === count,
            data.tasks.filter((task) => task.status === 'done').length,
          )
          assert.equal(
            await page.locator('.task-card').count(),
            data.tasks.filter((task) => task.status === 'done').length,
          )
          assert.equal(await page.locator('.task-grip:enabled').count(), 0)
          await page.setViewportSize({ width: 1280, height: 1050 })
          assert.equal(
            await page.locator('.filter-tabs button').last().getAttribute('aria-pressed'),
            'true',
          )
          await page.setViewportSize({ width, height: 844 })
          assert.equal(await page.locator('#idea-status-filter').inputValue(), 'done')
          await page.selectOption('#idea-status-filter', 'all')
          await page.screenshot({ path: new URL('filter-' + stamp + '.png', output).pathname })
        }
        for (const section of sections.slice(1)) await go(section)
        // Open the same trash from a fresh management-page session, where task data is not loaded.
        await page.goto(origin + adminRoot + '/settings')
        await page.locator('.admin-project-header').waitFor()
        await openMenu()
        await menu().locator('.project-trash-link').click()
        const trashDialog = page.locator('dialog[open][aria-labelledby="project-trash-title"]')
        await trashDialog.waitFor()
        assert.equal(await page.locator('#admin-drawer[open]').count(), 0)
        assert.equal(await trashDialog.locator('.trash-list li').count(), 1)
        await trashDialog.locator('.trash-list .button').click()
        await trashDialog.locator('.trash-list').waitFor({ state: 'detached' })
        assert.ok(
          f.requests.some((request) => request.path === `/api/admin/projects/${uuid}/tasks`),
        )
        await trashDialog.locator('.modal-header button').click()
        await go('brief')
        // Actual drag inputs after the responsive/filter transitions.
        const mainIdeas = data.tasks
          .filter((task) => task.sectionId === data.sections[0].id)
          .sort((a, b) => a.position - b.position)
        const [first, second] = mainIdeas
        const card = (id) => page.locator(`[data-task-id="${id}"]`)
        await card(second.id)
          .locator('.task-grip')
          .evaluate((node) => node.scrollIntoView({ block: 'center' }))
        const start = await card(second.id).locator('.task-grip').boundingBox(),
          end = await card(first.id).boundingBox()
        const x = start.x + start.width / 2,
          y = start.y + start.height / 2,
          tx = end.x + 10,
          ty = end.y + Math.min(55, end.height / 3)
        const placement = page.waitForResponse(
          (response) =>
            response.url().endsWith(`/tasks/${second.id}/place`) &&
            response.request().method() === 'POST',
        )
        if (width <= 960) {
          const cdp = await f.context.newCDPSession(page)
          await cdp.send('Input.dispatchTouchEvent', {
            type: 'touchStart',
            touchPoints: [{ x, y }],
          })
          for (let i = 1; i <= 15; i++)
            await cdp.send('Input.dispatchTouchEvent', {
              type: 'touchMove',
              touchPoints: [{ x: x + ((tx - x) * i) / 15, y: y + ((ty - y) * i) / 15 }],
            })
          await page.evaluate(() => new Promise(requestAnimationFrame))
          await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
          await cdp.detach()
        } else {
          await page.mouse.move(x, y)
          await page.mouse.down()
          await page.mouse.move(tx, ty, { steps: 15 })
          await page.mouse.up()
        }
        assert.equal((await placement).status(), 200)
        await page.locator('.section-list[aria-busy="true"]').waitFor({ state: 'hidden' })
        assert.ok(
          data.tasks.find((task) => task.id === second.id).position <
            data.tasks.find((task) => task.id === first.id).position,
          stamp + ' drag saves',
        )
        await page.reload()
        await card(second.id).waitFor()
        assert.ok(
          (await card(second.id).locator('.brief-number').innerText()) !==
            (await card(first.id).locator('.brief-number').innerText()),
        )
        // The detail view retains task navigation and uses only its existing project drawer.
        await page.goto(origin + f.path(first.id))
        await page.locator('.task-page h1').waitFor()
        assert.equal(
          await page.locator('.admin-sidebar, .project-subnav, .admin-project-header').count(),
          0,
        )
        assert.equal(await page.locator('#project-task-navigation').isVisible(), !mobileDrawer)
        await page.locator('.task-admin-trigger').click()
        const taskMenu = page.locator('#admin-drawer[open]')
        await taskMenu.waitFor()
        assert.equal(
          await taskMenu.locator('.project-panel-nav a').count(),
          language === 'en' ? 7 : 8,
        )
        assert.equal(await taskMenu.locator('.project-trash-link').count(), 1)
        assert.equal(await taskMenu.locator('.task-all-projects').count(), 1)
        if (mode === 'light')
          await page.screenshot({ path: new URL('task-menu-' + stamp + '.png', output).pathname })
        await page.keyboard.press('Escape')
        if (language === 'en' && mode === 'light' && [1280, 390].includes(width)) {
          await page.locator('.notes-heading').click()
          await page.locator('.developer-notes textarea').fill('Unsaved layout regression draft')
          await page.locator('.task-admin-trigger').click()
          page.once('dialog', (dialog) => dialog.dismiss())
          await taskMenu.locator('a[href$="/clients"]').click()
          assert.equal(new URL(page.url()).pathname, f.path(first.id))
          assert.equal(
            await page.locator('.developer-notes textarea').inputValue(),
            'Unsaved layout regression draft',
          )
          await page.locator('.task-admin-trigger').click()
          page.once('dialog', (dialog) => dialog.accept())
          await taskMenu.locator('.task-all-projects').click()
          await page.waitForURL('**/admin')
        } else {
          await page.locator('.task-admin-trigger').click()
          await taskMenu.locator('.task-all-projects').click()
          await page.waitForURL('**/admin')
        }
        // Global and personal routes use the unchanged workspace/project sidebar.
        assert.equal(await page.locator('.project-panel-nav').count(), 0)
        const globalSidebar = mobileDrawer
          ? page.locator('#admin-drawer')
          : page.locator('.admin-sidebar')
        if (mobileDrawer) await page.locator('.admin-menu-trigger').click()
        await globalSidebar
          .locator('.personal-settings-nav a[href="/admin/settings/notifications"]')
          .click()
        await page.waitForURL('**/admin/settings/notifications')
        await page.locator('#personal-notification-email').waitFor()
        assert.equal(await page.locator('.project-panel-nav').count(), 0)
        if (mobileDrawer) await page.locator('.admin-menu-trigger').click()
        await globalSidebar
          .locator('.sidebar-general:not(.personal-settings-nav) a[href="/admin"]')
          .click()
        await page.waitForURL('**/admin')
        if (
          (width === 1440 && language === 'ru' && mode === 'light') ||
          (width === 390 && language === 'en' && mode === 'dark')
        ) {
          if (mobileDrawer) await page.locator('.admin-menu-trigger').click()
          await globalSidebar.locator('.sidebar-workspace select').selectOption('other-workspace')
          await page.waitForFunction(
            () =>
              document.querySelector('.admin-toolbar-workspace')?.textContent.trim() ===
              'Other workspace',
          )
          assert.equal(await page.locator('.project-sidebar-content').count(), 0)
          assert.equal(await page.locator(`.sidebar-project[href*="${uuid}"]`).count(), 0)
          if (mobileDrawer) await page.locator('.admin-menu-trigger').click()
          await globalSidebar.locator('.sidebar-workspace select').selectOption('studio')
          await page.waitForFunction(
            () =>
              document.querySelector('.admin-toolbar-workspace')?.textContent.trim() ===
              'Design Studio',
          )
          await page.goto(origin + adminRoot + '/brief')
          await page.locator('.dashboard').waitFor()
          f.deny()
          await openMenu()
          await menu().locator('a[href$="/clients"]').click()
          await page.waitForURL('**/admin/unavailable')
          assert.equal(await page.locator('.project-sidebar-content').count(), 0)
          assert.equal(await page.locator(`.sidebar-project[href*="${uuid}"]`).count(), 0)
          assert.equal(
            await page.evaluate(async () => {
              const { useProjectStore } = await import('/src/stores/project.js')
              return useProjectStore().tasks.length
            }),
            0,
          )
        }
        await overflow()
        assert.deepEqual(f.errors, [], f.errors.join('\n'))
        results.push({
          ...config,
          passed: true,
          routes: sections,
          sharedTrash: true,
          resizeFilters: true,
          dragInput: width <= 960 ? 'touch' : 'mouse',
          taskDrawer: true,
          personalSettings: true,
        })
        console.log('PASS ' + stamp)
        await f.context.close()
      }
  for (const width of [1440, 390]) {
    const f = await taskWorkspaceFixture(
      browser,
      { width, language: 'en', mode: 'light', admin: false },
      { output, port },
    )
    await f.page.goto(origin + `/project/${uuid}`)
    await f.page.locator('.dashboard').waitFor()
    assert.equal(
      await f.page
        .locator('.project-sidebar-content, .mobile-status-filter, .admin-project-overview')
        .count(),
      0,
    )
    assert.equal(await f.page.locator('.filter-tabs').isVisible(), true)
    assert.equal(await f.page.locator('.section-filter').isVisible(), true)
    results.push({ client: true, width, passed: true, unchanged: true })
    await f.context.close()
  }
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify(
      {
        passed: true,
        api: 'fixtures; real Chromium mouse/touch/keyboard input',
        results,
        unavailableRoutes: ['team', 'personal profile', 'personal security'],
      },
      null,
      2,
    ) + '\n',
  )
  console.log(`PASS ${results.length} configurations`)
} finally {
  await browser?.close()
  await server.close()
}
