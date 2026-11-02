import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { staticDemoServer } from '../deployment/static-demo-server.mjs'
import { validateStaticDemo } from '../deployment/validate-static-demo.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(process.env.STATIC_DEMO_PACKAGE_ROOT || '.tools/static-demo-build')
const output = path.resolve(process.env.STATIC_DEMO_OUTPUT_DIRECTORY || 'artifacts/demo/static')
await mkdir(output, { recursive: true })
const { manifest, ...validated } = await validateStaticDemo(root)
async function snapshot(role, endpoint) {
  return JSON.parse(await readFile(path.join(root, manifest.roles[role].routes[endpoint].file)))
}
const projects = (await snapshot('developer', '/api/admin/projects')).data
const selected = projects.find((row) => row.name === 'Northstar Commerce')
const tasks = (await snapshot('developer', `/api/admin/projects/${selected.id}/tasks`)).data
const task = tasks.find((row) => row.hasAgentHistory)
const server = staticDemoServer(root)
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
const origin = `http://127.0.0.1:${server.address().port}`
const browser = await chromium.launch({ headless: true })
const errors = [],
  apiRequests = [],
  external = [],
  writes = [],
  checked = [],
  requests = []
function record(page) {
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text())
  })
  page.on('request', (request) => {
    const url = new URL(request.url())
    requests.push(url.pathname)
    if (/^https?:/.test(url.protocol) && url.origin !== origin) external.push(request.url())
    if (url.pathname.startsWith('/api/')) apiRequests.push(request.url())
    if (!['GET', 'HEAD'].includes(request.method()))
      writes.push(request.method() + ' ' + request.url())
  })
}
async function ready(page) {
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.locator('.loading-overlay').waitFor({ state: 'hidden' })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(130)
}
async function check(page, url, name, screenshot = true) {
  await page.goto(origin + url)
  await ready(page)
  assert.equal(new URL(page.url()).pathname, url, name)
  assert.ok((await page.title()).includes('Dev2Done'), name + ': product title')
  const visible = (await page.locator('body').innerText()).replace(/X-ProjectBrief-[A-Za-z-]+/g, '')
  assert.ok(!/\bProject ?Brief\b/i.test(visible), name + ': old visible product name')
  assert.ok((await page.locator('main').innerText()).length > 30, name)
  assert.equal(
    await page.locator('main [role=alert]:visible').count(),
    0,
    name + ': unexpected alert',
  )
  assert.ok(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
    name + ': overflow',
  )
  await page
    .locator('img')
    .evaluateAll((images) => Promise.all(images.map((image) => image.decode().catch(() => {}))))
  assert.equal(
    await page
      .locator('img')
      .evaluateAll((images) => images.some((image) => image.complete && image.naturalWidth === 0)),
    false,
    name + ': broken image',
  )
  if (screenshot) await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true })
  checked.push(name)
}
try {
  assert.equal((await fetch(origin + '/api/session')).status, 404)
  assert.equal((await fetch(origin + '/snapshots/missing.json')).status, 404)
  assert.equal((await fetch(origin + '/admin/account')).status, 200)
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    serviceWorkers: 'block',
  })
  const page = await context.newPage()
  record(page)
  await check(page, '/demo', 'entry')
  await page.getByRole('button', { name: 'Explore the studio' }).click()
  await page.waitForURL('**/admin')
  await ready(page)
  assert.equal(await page.locator('.admin-project-card').count(), 10)
  const global = [
    ['/admin/account', 'profile'],
    ['/admin/account/security', 'security'],
    ['/admin/settings/agreement', 'agreement'],
    ['/admin/settings/notifications', 'notifications'],
    ['/admin/settings/branding', 'branding'],
    ['/admin/administrators', 'administrators'],
    ['/admin/settings/integrations', 'integrations'],
    ['/admin/requests', 'requests'],
    ['/admin/reviews', 'reviews'],
  ]
  for (const [url, name] of global) {
    await check(page, url, 'developer-' + name)
    if (name === 'profile') {
      assert.equal(await page.locator('#account-name').inputValue(), 'Morgan Ellis')
      assert.equal(await page.locator('#account-name').isDisabled(), true)
      assert.ok(await page.locator('.account-heading img').count())
    }
    if (name === 'security') {
      assert.ok((await page.locator('main').innerText()).includes('Morgan’s studio laptop'))
      assert.equal(await page.locator('main button:enabled').count(), 0)
    }
    if (name === 'agreement') {
      assert.ok(
        (await page.locator('.editor-content').innerText()).includes(
          'Harborline Studio Team Agreement',
        ),
      )
      assert.equal(await page.locator('.editor-content').getAttribute('contenteditable'), 'false')
      assert.equal(await page.locator('.agreement-settings-actions button:enabled').count(), 0)
    }
    if (name === 'branding') assert.equal(await page.locator('input[type=file]:enabled').count(), 0)
    if (name === 'integrations') {
      page.once('dialog', (dialog) => dialog.accept())
      await page.getByRole('button', { name: 'Revoke', exact: true }).first().click()
      await page
        .getByText('Demo · View only. Changes are unavailable.', { exact: false })
        .first()
        .waitFor()
      assert.equal(await page.locator('.toast-success').count(), 0)
    }
    if (name === 'reviews') assert.equal(await page.locator('.review-card').count(), 8)
  }
  await check(page, `/w/${manifest.workspace}/agreement`, 'published-agreement')
  assert.ok(
    (await page.locator('.agreement-document').innerText()).includes(
      'Confidentiality and client trust',
    ),
  )
  for (const project of projects) {
    await check(page, `/admin/projects/${project.id}/brief`, 'project-' + project.id, false)
    const ownTasks = (await snapshot('developer', `/api/admin/projects/${project.id}/tasks`)).data
    await check(
      page,
      `/admin/projects/${project.id}/brief/task/${ownTasks[0].id}`,
      'task-' + project.id,
      false,
    )
  }
  for (const section of [
    'team',
    'clients',
    'discussions',
    'structure',
    'notifications',
    'settings',
    'export',
  ])
    await check(page, `/admin/projects/${selected.id}/${section}`, 'project-' + section)
  await check(page, `/admin/projects/${selected.id}/discussions`, 'team-chat')
  await page.locator('#discussion-tab-team').click()
  await page.locator('.team-chat-message').first().waitFor()
  assert.equal(await page.locator('main [role=alert]:visible').count(), 0)
  await page.screenshot({ path: path.join(output, 'team-chat.png'), fullPage: true })
  await check(page, `/admin/projects/${selected.id}/brief/task/${task.id}`, 'agent-task')
  await page.locator('[data-agent-chat]').waitFor()
  await check(page, '/admin/settings/integrations', 'integration-tabs')
  for (const tab of ['Webhooks', 'Agents', 'SSO', 'Recent operations']) {
    const button = page.getByRole('tab', { name: tab, exact: true })
    assert.equal(await button.count(), 1, tab)
    await button.click()
    await ready(page)
    await page.screenshot({
      path: path.join(output, 'integration-' + tab.replaceAll(' ', '-') + '.png'),
      fullPage: true,
    })
    assert.equal(await page.locator('main [role=alert]:visible').count(), 0, tab)
    checked.push(tab)
  }
  await check(page, '/admin', 'project-search')
  await page.locator('input[type=search]').fill('Northstar')
  await page.locator('input[type=search]').press('Enter')
  await page.waitForFunction(() => document.querySelectorAll('.admin-project-card').length === 1)
  assert.ok((await page.locator('.admin-project-card').innerText()).includes('Northstar'))
  await check(page, '/admin', 'project-status-filter', false)
  if (await page.locator('.project-filter-trigger').isVisible())
    await page.locator('.project-filter-trigger').click()
  await page.locator('#project-list-filters select').first().selectOption('inactive')
  await page.waitForFunction(() => document.querySelectorAll('.admin-project-card').length === 1)
  assert.ok(
    (await page.locator('.admin-project-card').innerText()).includes(
      projects.find((row) => row.status === 'inactive').name,
    ),
  )
  await check(page, '/admin/account', 'history-profile', false)
  await page.getByRole('link', { name: 'Security', exact: true }).last().click()
  await ready(page)
  await page.goBack()
  assert.equal(new URL(page.url()).pathname, '/admin/account')
  await page.goForward()
  assert.equal(new URL(page.url()).pathname, '/admin/account/security')
  await page.reload()
  await ready(page)
  for (const theme of ['light', 'dark', 'system']) {
    await page.getByLabel('Color theme').first().selectOption(theme)
    await page.screenshot({
      path: path.join(output, 'security-theme-' + theme + '.png'),
      fullPage: true,
    })
  }
  await page.locator('.language-switcher select:visible').first().selectOption('ru')
  await ready(page)
  assert.ok((await page.locator('body').innerText()).includes('Безопасность'))
  await page.locator('.language-switcher select:visible').first().selectOption('en')
  for (const width of [390, 375]) {
    await page.setViewportSize({ width, height: 844 })
    for (const [url, name] of global) await check(page, url, `mobile-${width}-${name}`)
  }
  const clientContext = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    serviceWorkers: 'block',
  })
  const client = await clientContext.newPage()
  record(client)
  await client.goto(origin + '/demo/client')
  await client.waitForURL('**/project/' + selected.id)
  await ready(client)
  assert.equal(await client.locator('.section-title').first().innerText(), '1. Core experience')
  for (const [url, name] of [
    [`/project/${selected.id}`, 'overview'],
    [`/project/${selected.id}/task/${task.id}`, 'task'],
    [`/project/${selected.id}/discussions`, 'discussions'],
    [`/project/${selected.id}/notifications`, 'notifications'],
    [`/project/${selected.id}/export`, 'export'],
  ])
    await check(client, url, 'client-' + name)
  for (const width of [390, 375]) {
    await client.setViewportSize({ width, height: 844 })
    await check(client, `/project/${selected.id}`, 'client-mobile-' + width)
    await check(client, `/project/${selected.id}/task/${task.id}`, 'client-task-mobile-' + width)
  }
  assert.equal(await client.locator('[data-agent-chat]').count(), 0)
  assert.ok(!(await client.locator('main').innerText()).includes('Implementation Agent'))
  assert.deepEqual(apiRequests, [])
  assert.deepEqual(writes, [])
  assert.deepEqual(external, [])
  assert.deepEqual(errors, [])
  await context.close()
  await clientContext.close()
  const report = {
    passed: true,
    runtime: 'Node static files only; no PHP or DB',
    archive: process.env.STATIC_DEMO_PACKAGE_ROOT || null,
    browser: browser.version(),
    checked,
    apiRequests,
    writes,
    external,
    errors,
    staticRequests: requests.length,
    schemas: validated.contracts,
  }
  await writeFile(path.join(output, 'browser-results.json'), JSON.stringify(report, null, 2) + '\n')
  console.log(JSON.stringify(report, null, 2))
} finally {
  await browser.close()
  await new Promise((resolve) => server.close(resolve))
}
