import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const root = path.resolve(import.meta.dirname, '..')
const origin = process.env.DEMO_ORIGIN || 'http://127.0.0.1:18080'
const output = path.join(root, 'artifacts/demo')
await mkdir(output, { recursive: true })
function audit() {
  const command = spawnSync(path.join(root, 'bin/demo'), ['audit'], { encoding: 'utf8', cwd: root })
  assert.equal(command.status, 0, command.stderr)
  return JSON.parse(command.stdout)
}
const initial = audit()
const browser = await chromium.launch({ headless: true })
const errors = [],
  results = [],
  leaks = [],
  external = []
const contexts = []
function record(page) {
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text())
  })
  page.on('request', (request) => {
    if (!request.url().startsWith(origin) && /^https?:/.test(request.url()))
      external.push(request.url())
  })
  page.on('response', async (response) => {
    if (
      !response.url().includes('/api/client/') ||
      !(response.headers()['content-type'] || '').includes('json') ||
      response.status() !== 200
    )
      return
    const body = await response.text().catch(() => '')
    for (const value of [
      'agentAssignments',
      'agentName',
      'agentKind',
      'agentReport',
      'agentState',
      'hasAgentHistory',
      'Frontend Implementation Agent',
      'QA & Accessibility Agent',
      'developerNotes',
      'estimateHours',
      '"price"',
      'agentSourceCommentId',
    ])
      if (body.includes(value)) leaks.push(value + ': ' + response.url())
  })
}
async function ready(page) {
  await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
  await page.locator('.loading-overlay').waitFor({ state: 'hidden' })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(150)
}
async function capture(page, file) {
  await ready(page)
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1),
    false,
    file + ' overflows',
  )
  assert.equal(
    await page
      .locator('img')
      .evaluateAll((images) => images.some((image) => image.complete && image.naturalWidth === 0)),
    false,
    file + ' has broken images',
  )
  await page.screenshot({ path: path.join(output, file), fullPage: false })
}
async function get(context, url) {
  const response = await context.request.get(origin + url, {
    headers: { Accept: 'application/json' },
  })
  assert.equal(response.status(), 200, url + ': ' + (await response.text()))
  return response.json()
}
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  contexts.push(context)
  const page = await context.newPage()
  record(page)
  await page.goto(origin + '/demo')
  await capture(page, 'demo-entry.png')
  await page.getByRole('button', { name: 'Explore the studio' }).click()
  await page.waitForURL('**/admin')
  await capture(page, 'developer-overview.png')
  assert.equal(await page.locator('.demo-view-indicator').count(), 1)
  const projects = (await get(context, '/api/admin/projects')).data
  assert.equal(projects.length, 10)
  const project = projects.find((item) => item.name === 'Northstar Commerce')
  const tasks = (await get(context, `/api/admin/projects/${project.id}/tasks`)).data
  const task = tasks.find((item) => item.hasAgentHistory)
  assert.ok(task)
  for (const [url, name] of [
    [`/admin/projects/${project.id}/brief`, 'developer-project'],
    [`/admin/projects/${project.id}/brief/task/${task.id}`, 'developer-task'],
    [`/admin/projects/${project.id}/team`, 'developer-team'],
    [`/admin/projects/${project.id}/clients`, 'developer-clients'],
    [`/admin/projects/${project.id}/discussions`, 'developer-discussions'],
    ['/admin/requests', 'developer-requests'],
    ['/admin/reviews', 'developer-reviews'],
    ['/admin/administrators', 'developer-administrators'],
    ['/admin/settings/integrations', 'developer-integrations'],
    ['/admin/settings/branding', 'developer-branding'],
    ['/admin/settings/notifications', 'developer-notifications'],
  ]) {
    await page.goto(origin + url)
    await ready(page)
    assert.equal(new URL(page.url()).pathname, url)
    assert.ok((await page.locator('main').innerText()).length > 50, name)
    await capture(page, name + '.png')
    if (name === 'developer-task') {
      const conversation = page.locator('[data-agent-chat]')
      await conversation.waitFor()
      const collaboration = await get(context, `/api/admin/tasks/${task.id}/agent-collaboration`)
      assert.equal(collaboration.data[0].runCount, 2)
      assert.ok(collaboration.data[0].messages.length >= 6)
      await conversation.scrollIntoViewIfNeeded()
      await capture(page, 'developer-agent-conversation.png')
    }
    results.push(name)
  }
  const keys = (await get(context, '/api/admin/integrations')).tokens
  assert.ok(keys.some((key) => key.shared && key.linkedAgents.length === 3))
  assert.ok(keys.every((key) => key.demoMaskedCredential && key.synthetic))
  await page.goto(origin + '/admin/settings/integrations')
  await ready(page)
  for (const tab of ['Webhooks', 'Agents', 'SSO', 'Recent operations']) {
    const button = page.getByRole('button', { name: tab, exact: true })
    if (await button.count()) {
      await button.click()
      await capture(page, 'developer-' + tab.toLowerCase().replaceAll(' ', '-') + '.png')
    }
  }
  for (const other of projects.filter((item) =>
    ['Willow & Co.', 'Atlas Property Group', 'Vertex Analytics'].includes(item.name),
  )) {
    await page.goto(origin + `/admin/projects/${other.id}/brief`)
    await ready(page)
    assert.ok((await page.locator('main').innerText()).includes(other.name))
    results.push(other.name)
  }
  const rejected = await context.request.post(origin + `/api/admin/tasks/${task.id}/comments`, {
    data: { text: 'Must remain blocked' },
  })
  assert.equal(rejected.status(), 403)
  assert.equal((await rejected.json()).code, 'demo_view_only')
  for (const method of ['post', 'put', 'patch', 'delete']) {
    const response = await context.request[method](origin + '/api/v1/projects', {
      headers: { Authorization: 'Bearer manual-credential' },
      data: {},
    })
    assert.equal(response.status(), 403)
  }
  for (const url of [
    '/api/security/passkey/login/options',
    '/api/platform/overview',
    '/api/admin/account',
    '/access/test',
    '/client-sso/complete?code=test',
  ])
    assert.equal((await context.request.get(origin + url)).status(), 403)
  await page.goto(origin + '/admin')
  await ready(page)
  const help = page.locator('.workspace-help:visible')
  await help.focus()
  await page.waitForTimeout(80)
  assert.equal(
    await page
      .locator('.viewport-workspace-tooltip:popover-open')
      .count()
      .then((count) => count > 0),
    true,
  )
  const bounds = await page.locator('.viewport-workspace-tooltip:popover-open').boundingBox()
  assert.ok(bounds.x >= 0 && bounds.y >= 0 && bounds.x + bounds.width <= 1440)
  await page.keyboard.press('Escape')
  assert.equal(
    await page
      .locator('.viewport-workspace-tooltip:popover-open')
      .count()
      .then((count) => count > 0),
    false,
  )
  for (const width of [1280, 1024, 390, 375]) {
    await page.setViewportSize({ width, height: 900 })
    await capture(page, `developer-${width}.png`)
    if (width < 500) {
      const menu = page.getByRole('button', { name: 'Open navigation menu', exact: true })
      if (await menu.isVisible()) {
        await menu.click()
        await page.locator('dialog .workspace-help').click()
        await page.keyboard.press('Escape')
        await page.keyboard.press('Escape')
      }
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 })
  const theme = page.getByLabel('Color theme').first()
  await theme.selectOption('dark')
  await capture(page, 'developer-dark.png')
  await theme.selectOption('system')
  const language = page.locator('.language-switcher select:visible').first()
  await language.selectOption('ru')
  await ready(page)
  assert.ok((await page.locator('body').innerText()).includes('Проекты'))
  await language.selectOption('en')
  const clientContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  contexts.push(clientContext)
  const client = await clientContext.newPage()
  record(client)
  await client.goto(origin + '/demo/client')
  await ready(client)
  await capture(client, 'client-project.png')
  assert.ok((await client.locator('body').innerText()).includes('Northstar Commerce'))
  const clientTask = await get(clientContext, `/api/client/tasks/${task.id}`)
  assert.ok(clientTask.data.history.every((item) => !item.type.startsWith('agent_')))
  await client.goto(origin + `/project/${project.id}/task/${task.id}`)
  await capture(client, 'client-task.png')
  await client
    .getByText('Change history', { exact: false })
    .first()
    .click()
    .catch(() => {})
  await capture(client, 'client-task-history.png')
  assert.ok(!(await client.locator('body').innerText()).includes('Implementation Agent'))
  await client.goto(origin + `/project/${project.id}/discussions`)
  await capture(client, 'client-discussions.png')
  for (const width of [1280, 1024, 390, 375]) {
    await client.setViewportSize({ width, height: 900 })
    await client.goto(origin + `/project/${project.id}`)
    await capture(client, `client-${width}.png`)
  }
  await client.setViewportSize({ width: 1440, height: 1000 })
  await client.getByLabel('Color theme').first().selectOption('dark')
  await capture(client, 'client-dark.png')
  const foreign = projects.find((item) => item.name === 'Lumen Health')
  assert.equal(
    (await clientContext.request.get(origin + `/api/client/project/${foreign.id}`)).status(),
    404,
  )
  assert.equal((await clientContext.request.get(origin + '/api/admin/integrations')).status(), 401)
  assert.equal(
    (
      await clientContext.request.post(origin + `/api/client/tasks/${task.id}/approve`, {
        data: {},
      })
    ).status(),
    403,
  )
  await page.waitForTimeout(200)
  const final = audit()
  assert.equal(final.databaseHash, initial.databaseHash, 'Demo browsing changed database contents')
  assert.deepEqual(leaks, [], 'Client JSON leaks private fields')
  assert.deepEqual(external, [], 'External browser requests')
  assert.deepEqual(errors, [], 'Browser errors')
  await writeFile(
    path.join(output, 'walkthrough.json'),
    JSON.stringify(
      {
        results,
        roles: ['developer', 'client'],
        widths: [1440, 1280, 1024, 390, 375],
        themes: ['light', 'dark', 'system'],
        errors,
        leaks,
        external,
        before: initial.databaseHash,
        after: final.databaseHash,
      },
      null,
      2,
    ),
  )
  console.log(
    JSON.stringify({
      passed: true,
      screens: results.length,
      databaseUnchanged: true,
      errors,
      leaks,
      external,
    }),
  )
} finally {
  await Promise.all(contexts.map((context) => context.close()))
  await browser.close()
}
