// PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/project-trash.browser.mjs
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { allProjectPermissions } from './helpers/projectPermissions.js'
import { LANGUAGE_CATALOG } from '../src/i18n/catalog.js'

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = new URL('../docs/project-trash/screenshots/', import.meta.url)
await mkdir(output, { recursive: true })
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5197, strictPort: true },
  logLevel: 'error',
})
await server.listen()
const browser = await chromium.launch({ headless: true })
const root = 'http://127.0.0.1:5197'
const results = []
const id = '11111111-1111-4111-8111-111111111111',
  inactive = '22222222-2222-4222-8222-222222222222'
const trashedId = '33333333-3333-4333-8333-333333333333'
async function fixture({ lang = 'ru', mode = 'light', width = 1440, owner = true } = {}) {
  const messages = LANGUAGE_CATALOG.find((item) => item.code === lang).messages
  const context = await browser.newContext({
    viewport: { width, height: 1050 },
    locale: lang === 'ru' ? 'ru-RU' : 'en-GB',
    colorScheme: mode === 'system' ? 'dark' : mode,
    reducedMotion: 'reduce',
  })
  await context.addInitScript(
    ({ lang, mode }) => {
      localStorage.setItem('projectbrief.locale', lang)
      localStorage.setItem('projectbrief.theme', mode)
    },
    { lang, mode },
  )
  let projects = [
    {
      id,
      name: 'Northstar — Website',
      website: 'https://northstar.example',
      currency: 'EUR',
      status: 'active',
      clientsCount: 3,
      teamCount: 4,
      tasksCount: 12,
      doneCount: 8,
      updatedAt: '2026-10-26T09:30:00Z',
      permissions: { ...allProjectPermissions },
      unreadCount: 2,
    },
    {
      id: inactive,
      name: 'Studio Journal',
      website: 'https://journal.example',
      currency: 'USD',
      status: 'inactive',
      clientsCount: 1,
      teamCount: 2,
      tasksCount: 0,
      doneCount: 0,
      updatedAt: '2026-10-24T08:00:00Z',
      permissions: { ...allProjectPermissions },
    },
  ]
  let trash = [
    { id: trashedId, name: 'Archive — Summer campaign', deletedAt: '2026-10-25T10:00:00Z' },
  ]
  const archived = new Map(),
    calls = [],
    errors = [],
    mutations = []
  await context.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request(),
        path = new URL(request.url()).pathname,
        method = request.method(),
        body = request.postDataJSON()
      calls.push({ path, method, body })
      if (method !== 'GET') mutations.push({ path, method, body })
      let payload
      if (path === '/api/csrf') payload = { token: 'csrf' }
      else if (path === '/api/admin/me')
        payload = {
          user: {
            id: 'owner',
            name: 'Alex Morgan',
            email: 'alex@example.test',
            membershipRole: owner ? 'owner' : 'admin',
            preferredLocale: lang,
          },
          workspace: { id: 'studio', name: 'Northstar Studio' },
        }
      else if (path === '/api/admin/workspaces')
        payload = { data: [{ id: 'studio', name: 'Northstar Studio' }] }
      else if (path === '/api/admin/projects') payload = { data: projects }
      else if (path === '/api/admin/projects/trash' && method === 'GET') payload = { data: trash }
      else if (path === '/api/admin/projects/trash' && method === 'DELETE') {
        assert.equal(body.confirmed, true)
        assert.deepEqual(body.projectIds.sort(), trash.map((p) => p.id).sort())
        trash = []
        payload = { deleted: true }
      } else if (path.endsWith('/trash') && method === 'POST') {
        const uuid = path.split('/').at(-2),
          project = projects.find((p) => p.id === uuid)
        archived.set(uuid, project)
        projects = projects.filter((p) => p.id !== uuid)
        trash.push({ id: uuid, name: project.name, deletedAt: '2026-10-26T10:00:00Z' })
        payload = { trashed: true }
      } else if (path.endsWith('/restore') && method === 'POST') {
        const uuid = path.split('/').at(-2)
        projects.push(
          archived.get(uuid) || {
            ...projects[0],
            id: uuid,
            name: trash.find((p) => p.id === uuid).name,
          },
        )
        trash = trash.filter((p) => p.id !== uuid)
        payload = { restored: true }
      } else if (path.endsWith('/permanent')) {
        const uuid = path.split('/').at(-2)
        assert.equal(body.confirmed, true)
        trash = trash.filter((p) => p.id !== uuid)
        payload = { deleted: true }
      } else if (path.endsWith('/team'))
        payload = {
          projects: projects.map((p) => ({ id: p.id, name: p.name })),
          members: [],
          invitations: [],
        }
      else if (path.endsWith('/clients')) payload = { data: [] }
      else if (path.endsWith('/telegram')) payload = { available: false }
      else if (path.endsWith('/activity/read')) payload = { unreadCount: 0 }
      else if (path.startsWith('/api/admin/projects/'))
        payload = {
          data: { ...projects.find((p) => p.id === path.split('/').at(-1)), sections: [] },
        }
      else throw new Error(`Unexpected request ${method} ${path}`)
      await route.fulfill({ json: payload })
    },
  )
  const page = await context.newPage()
  page.on('pageerror', (e) => errors.push(e.message))
  await page.goto(root + '/admin')
  await page.locator('.admin-project-card').first().waitFor()
  await page.waitForFunction(() => !document.getElementById('bootstrap-splash'))
  const toggle = page.locator('.project-bin-toggle')
  await page.waitForFunction(
    (label) => document.querySelector('.project-bin-toggle')?.textContent.includes(label),
    messages.projectList.trashCount.replace('{count}', '1'),
  )
  return { context, page, messages, toggle, errors, mutations, calls }
}
try {
  for (const config of [
    { width: 1440, mode: 'light', lang: 'ru' },
    { width: 390, mode: 'light', lang: 'ru' },
    { width: 1440, mode: 'dark', lang: 'en' },
    { width: 390, mode: 'dark', lang: 'en' },
    { width: 320, mode: 'system', lang: 'tr' },
    { width: 390, mode: 'system', lang: 'zh-CN' },
  ]) {
    const { page, context, toggle, messages, errors } = await fixture(config)
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false')
    assert.equal(await page.locator('.project-bin-list').count(), 0)
    assert.equal(
      await page.locator('.project-card-progress progress').first().getAttribute('value'),
      '67',
    )
    assert.equal(
      await page.locator('.project-card-progress progress').nth(1).getAttribute('value'),
      '0',
    )
    const card = page.locator('.admin-project-card').first()
    for (const section of ['clients', 'team', 'settings', 'brief'])
      assert.ok(await card.locator(`a[href="/admin/projects/${id}/${section}"]`).count())
    assert.equal(await page.locator('.project-activity.is-active').count(), 1)
    const hit = await card.locator('.icon-button').boundingBox()
    assert.ok(hit.width >= 44 && hit.height >= 44)
    const title = await card.locator('h2 a').boundingBox()
    const badge = await card.locator('h2 .unread-badge').boundingBox()
    assert.ok(Math.abs(badge.x - title.x - title.width - 5) < 1)
    const settings = card.locator('a[href$="/settings"]')
    assert.equal(
      await settings.evaluate((element) => getComputedStyle(element).alignItems),
      'center',
    )
    const settingsBox = await settings.boundingBox()
    const buttons = await card.locator('.admin-project-actions > a').evaluateAll((elements) =>
      elements.map((element) => {
        const rect = element.getBoundingClientRect()
        return { y: rect.y, height: rect.height }
      }),
    )
    for (const button of buttons.filter((button) => Math.abs(button.y - settingsBox.y) < 3)) {
      assert.ok(Math.abs(button.y + button.height / 2 - settingsBox.y - settingsBox.height / 2) < 1)
    }
    await toggle.click()
    await page.locator('.project-bin-list li').waitFor()
    assert.equal(await toggle.getAttribute('aria-expanded'), 'true')
    assert.equal(
      await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
      false,
    )
    await page.screenshot({
      path: new URL(`${config.width}-${config.mode}-${config.lang}.png`, output).pathname,
      fullPage: true,
      animations: 'disabled',
    })
    const background = await page.locator('html').getAttribute('data-theme')
    assert.equal(background, config.mode === 'system' ? 'dark' : config.mode)
    assert.equal((await page.locator('main').innerText()).includes('projectList.'), false)
    assert.deepEqual(errors, [])
    results.push({
      ...config,
      passed: true,
      checks:
        'counts, routes, progress, activity, collapsed/expanded, hit area, overflow, theme, localization',
    })
    await context.close()
  }
  {
    const { page, context, messages: m, toggle, errors, mutations } = await fixture()
    const trashButton = page
      .locator('.admin-project-card')
      .first()
      .getByRole('button', { name: m.projectList.moveToTrash, exact: true })
    await trashButton.click()
    let dialog = page.locator('dialog[open]')
    assert.ok(await dialog.getByText(/Northstar/).count())
    await page.keyboard.press('Escape')
    assert.equal(await page.locator('dialog[open]').count(), 0)
    assert.equal(await trashButton.evaluate((e) => e === document.activeElement), true)
    assert.equal(mutations.length, 0)
    await trashButton.click()
    await page
      .locator('dialog[open]')
      .getByRole('button', { name: m.projectList.moveToTrash, exact: true })
      .click()
    await page.waitForFunction(() => document.querySelectorAll('.admin-project-card').length === 1)
    await page.waitForFunction(
      (label) => document.querySelector('.project-bin-toggle')?.textContent.includes(label),
      m.projectList.trashCount.replace('{count}', '2'),
    )
    await toggle.click()
    const row = page.locator('.project-bin-list li').filter({ hasText: 'Northstar — Website' })
    await row.getByRole('button', { name: m.management.restore, exact: true }).click()
    await page.waitForFunction(() => document.querySelectorAll('.admin-project-card').length === 2)
    await page
      .locator('.project-bin-list li')
      .getByRole('button', { name: m.projectList.deleteForever })
      .click()
    dialog = page.locator('dialog[open]')
    const remove = dialog.getByRole('button', { name: m.projectList.deleteForever, exact: true })
    assert.equal(await remove.isDisabled(), true)
    await dialog.getByRole('checkbox').check()
    await remove.click()
    await page.waitForFunction(() => document.querySelectorAll('.project-bin-list li').length === 0)
    // Refill through the visible trash action, then empty the whole bin.
    await page
      .locator('.admin-project-card')
      .first()
      .getByRole('button', { name: m.projectList.moveToTrash })
      .click()
    await page
      .locator('dialog[open]')
      .getByRole('button', { name: m.projectList.moveToTrash, exact: true })
      .click()
    await page.locator('.project-bin-list li').waitFor()
    await page.getByRole('button', { name: m.projectList.emptyTrash, exact: true }).click()
    dialog = page.locator('dialog[open]')
    const empty = dialog.getByRole('button', { name: m.projectList.emptyTrash, exact: true })
    assert.equal(await empty.isDisabled(), true)
    assert.ok((await dialog.innerText()).includes('(1)'))
    await dialog.getByRole('checkbox').check()
    await dialog.screenshot({ path: new URL('empty-confirmation-ru.png', output).pathname })
    await empty.click()
    await page.getByText(m.projectList.empty, { exact: true }).waitFor()
    assert.deepEqual(errors, [])
    results.push({
      actions:
        'cancel/Escape/focus, trash, restore, permanent-delete confirmation, empty exact snapshot, live refresh',
      passed: true,
    })
    await context.close()
  }
  {
    const { page, context, calls, errors } = await fixture()
    await page.locator(`.admin-project-card a[href="/admin/projects/${id}/team"]`).click()
    await page.waitForURL(`**/admin/projects/${id}/team`)
    await page.locator('.team-page .team-list').first().waitFor()
    assert.ok(calls.some((call) => call.path === `/api/admin/projects/${id}/team`))
    await page.goto(root + '/admin')
    await page.locator(`.admin-project-card a[href="/admin/projects/${inactive}/clients"]`).click()
    await page.waitForURL(`**/admin/projects/${inactive}/clients`)
    await page.locator('.management-panel').waitFor()
    assert.ok(calls.some((call) => call.path === `/api/admin/projects/${inactive}/clients`))
    assert.deepEqual(errors, [])
    results.push({
      navigation: 'team and clients buttons open settings of the selected project',
      passed: true,
    })
    await context.close()
  }
  {
    const { page, context, messages: m, toggle } = await fixture({ owner: false })
    assert.equal(await page.locator(`.admin-project-card a[href$="/team"]`).count(), 0)
    await toggle.click()
    assert.equal(await page.getByRole('button', { name: m.projectList.emptyTrash }).count(), 0)
    assert.equal(await page.getByRole('button', { name: m.projectList.deleteForever }).count(), 0)
    results.push({
      permissions: 'developer can restore with permission; owner-only team and purge hidden',
      passed: true,
    })
    await context.close()
  }
  await writeFile(
    new URL('../browser-results.json', output),
    JSON.stringify(
      {
        fixture:
          'API mocked; Laravel database and authorization verified separately in ProjectTrashTest',
        results,
      },
      null,
      2,
    ) + '\n',
  )
  console.log(JSON.stringify(results, null, 2))
} finally {
  await browser.close()
  await server.close()
}
