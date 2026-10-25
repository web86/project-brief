import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = new URL('../docs/milestone-4-6-1/', import.meta.url)
await mkdir(output, { recursive: true })
const port = 5193
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port, strictPort: true },
  logLevel: 'error',
})
const results = []
let browser
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const language of ['ru', 'en'])
    for (const mode of ['light', 'dark', 'system'])
      for (const width of [1440, 820, 390]) {
        const config = { language, mode, width, admin: true }
        let failPlace = false,
          failDelete = false,
          gate = null,
          release
        const trash = []
        const f = await taskWorkspaceFixture(browser, config, {
          output,
          port,
          setup: async ({ context, data }) => {
            data.tasks.splice(3)
            data.sections.splice(2)
            data.tasks.forEach((task, index) =>
              Object.assign(task, {
                sectionId: data.sections[0].id,
                position: index + 1,
                status: 'new',
                clientApproved: false,
              }),
            )
            await context.route('**/api/**', async (route) => {
              const request = route.request(),
                path = new URL(request.url()).pathname
              const id = path.split('/').at(-2)
              if (path.endsWith('/place')) {
                if (gate) await gate
                if (failPlace) {
                  failPlace = false
                  return route.fulfill({ status: 503, json: { message: 'Try again' } })
                }
                const body = request.postDataJSON()
                const task = data.tasks.find((item) => item.id === id)
                const items = data.tasks
                  .filter((item) => item.sectionId === body.sectionId && item.id !== id)
                  .sort((a, b) => a.position - b.position)
                let index = items.length
                if (body.beforeTaskId || body.afterTaskId)
                  index =
                    items.findIndex((item) => item.id === (body.beforeTaskId || body.afterTaskId)) +
                    (body.afterTaskId ? 1 : 0)
                const old = task.sectionId
                task.sectionId = body.sectionId
                items.splice(index, 0, task)
                items.forEach((item, i) => (item.position = i + 1))
                if (old !== body.sectionId)
                  data.tasks
                    .filter((item) => item.sectionId === old)
                    .sort((a, b) => a.position - b.position)
                    .forEach((item, i) => (item.position = i + 1))
                return route.fulfill({ json: { data: data.tasks } })
              }
              if (path.endsWith('/trash')) return route.fulfill({ json: { data: trash } })
              if (path.endsWith('/restore')) {
                const task = trash.find((item) => item.id === id)
                trash.splice(trash.indexOf(task), 1)
                const items = data.tasks
                  .filter((item) => item.sectionId === task.sectionId)
                  .sort((a, b) => a.position - b.position)
                items.splice(Math.min(task.position - 1, items.length), 0, task)
                items.forEach((item, i) => (item.position = i + 1))
                data.tasks.push(task)
                return route.fulfill({ json: { data: task } })
              }
              if (request.method() === 'DELETE' && path.includes('/tasks/')) {
                if (failDelete)
                  return route.fulfill({ status: 503, json: { message: 'Try again' } })
                const taskId = path.split('/').at(-1),
                  task = data.tasks.find((item) => item.id === taskId)
                trash.push({ ...task })
                data.tasks.splice(data.tasks.indexOf(task), 1)
                data.tasks
                  .filter((item) => item.sectionId === task.sectionId)
                  .sort((a, b) => a.position - b.position)
                  .forEach((item, i) => (item.position = i + 1))
                return route.fulfill({ json: { deleted: true } })
              }
              return route.fallback()
            })
          },
        })
        const { page, data } = f
        const main = data.sections[0].id,
          empty = data.sections[1].id
        const ids = data.tasks.map((task) => task.id)
        const card = (id) => page.locator(`[data-task-id="${id}"]`)
        const order = () =>
          page
            .locator(`[data-drop-section="${main}"] [data-task-id]`)
            .evaluateAll((items) => items.map((item) => item.dataset.taskId))
        await page.goto(`http://127.0.0.1:${port}${adminRoot}/brief`)
        await card(ids[0]).waitFor()
        assert.equal(await page.locator('.task-grip').count(), 3)
        const stamp = `${language}-${mode}-${width}`
        await f.screenshot(`cards-${stamp}`)
        // Drag with actual mouse/touch input; no synthetic drag handlers or store calls.
        async function drag(id, targetLocator, side = 'before', touch = false) {
          await card(id).scrollIntoViewIfNeeded()
          const from = await card(id).locator('.task-grip').boundingBox()
          const to = await targetLocator.boundingBox()
          const x1 = from.x + from.width / 2,
            y1 = from.y + from.height / 2
          const x2 = side === 'after' ? to.x + to.width - 8 : to.x + 8,
            y2 = to.y + Math.min(65, to.height / 2)
          if (touch) {
            const cdp = await page.context().newCDPSession(page)
            await cdp.send('Input.dispatchTouchEvent', {
              type: 'touchStart',
              touchPoints: [{ x: x1, y: y1 }],
            })
            for (let step = 1; step <= 12; step++)
              await cdp.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: [{ x: x1 + ((x2 - x1) * step) / 12, y: y1 + ((y2 - y1) * step) / 12 }],
              })
            await pause(50)
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
            await cdp.detach()
          } else {
            await page.mouse.move(x1, y1)
            await page.mouse.down()
            await page.mouse.move(x2, y2, { steps: 15 })
            await page.locator('.task-drag-ghost').waitFor()
            if (language === 'ru' && mode === 'light')
              await page.screenshot({ path: new URL(`drag-active-${width}.png`, output).pathname })
            await page.mouse.up()
          }
          await page.locator('.task-drag-ghost').waitFor({ state: 'detached' })
        }
        if (width > 960) {
          await drag(ids[2], card(ids[0]))
          await page.waitForFunction(
            () => !document.querySelector('[aria-busy="true"].section-list'),
          )
          assert.deepEqual(await order(), [ids[2], ids[0], ids[1]])
          assert.equal(new URL(page.url()).pathname, `${adminRoot}/brief`)
          // Stable placeholder, no navigation, persistence and rollback.
          await page.reload()
          await card(ids[2]).waitFor()
          assert.deepEqual(await order(), [ids[2], ids[0], ids[1]])
          failPlace = true
          await drag(ids[2], card(ids[1]), 'after')
          await page.waitForFunction(
            () => !document.querySelector('[aria-busy="true"].section-list'),
          )
          assert.deepEqual(await order(), [ids[2], ids[0], ids[1]])
          if (language === 'ru' && mode === 'light') {
            gate = new Promise((resolve) => {
              release = resolve
            })
            await drag(ids[1], card(ids[2]))
            await page.locator('.section-list[aria-busy="true"]').waitFor()
            assert.equal(await page.locator('.task-grip:enabled').count(), 0)
            release()
            gate = null
            await page.locator('.section-list[aria-busy="true"]').waitFor({ state: 'detached' })
          }
          // Empty section and collapsed section accept drops.
          const emptySection = page.locator(`[data-drop-section="${empty}"]`)
          await emptySection.locator('.section-toggle').click()
          await drag(ids[0], emptySection.locator('.section-heading'))
          await page.waitForFunction(
            () => !document.querySelector('[aria-busy="true"].section-list'),
          )
          assert.equal(data.tasks.find((task) => task.id === ids[0]).sectionId, empty)
          await emptySection.locator('.section-toggle').click()
          await card(ids[0]).waitFor()
        } else {
          // Vertical touch drag on the dedicated handle.
          await drag(ids[1], card(ids[0]), 'before', true)
          await page.waitForFunction(
            () => !document.querySelector('[aria-busy="true"].section-list'),
          )
          assert.deepEqual(await order(), [ids[1], ids[0], ids[2]])
          // Scrolling on the card body does not reorder.
          const beforeCalls = f.calls.filter((call) => call.path.endsWith('/place')).length
          await page.mouse.wheel(0, 150)
          assert.equal(f.calls.filter((call) => call.path.endsWith('/place')).length, beforeCalls)
        }
        // Keyboard opens the compact placement dialog; select an exact position.
        await card(ids[2]).locator('.task-grip').focus()
        await page.keyboard.press('Enter')
        const moveDialog = page.locator('dialog[open]')
        await moveDialog.waitFor()
        await moveDialog.locator('select').first().selectOption(empty)
        await moveDialog.locator('.button.primary').click()
        await moveDialog.waitFor({ state: 'hidden' })
        assert.equal(data.tasks.find((task) => task.id === ids[2]).sectionId, empty)
        // Any narrowing filter disables all placement controls.
        await page.locator('.filter-tabs button').nth(1).click()
        assert.equal(await page.locator('.task-grip:enabled').count(), 0)
        await page.locator('.filter-tabs button').first().click()
        await page.selectOption('#section-filter', main)
        assert.equal(await page.locator('.task-grip:enabled').count(), 0)
        await page.selectOption('#section-filter', '')
        // Delete sits after comments, cancel retains idea, failed API stays on page.
        await page.goto(`http://127.0.0.1:${port}${f.path(ids[0])}`)
        const deletion = page.locator('.task-trash-action .delete-idea-action')
        await deletion.waitFor()
        assert.equal(await deletion.isEnabled(), true)
        assert.equal(await page.locator('.delete-idea-action').count(), 1)
        assert.equal(
          await page
            .locator('.task-trash-action')
            .evaluate(
              (node) =>
                !!(
                  document.querySelector('.comments-panel').compareDocumentPosition(node) &
                  Node.DOCUMENT_POSITION_FOLLOWING
                ),
            ),
          true,
        )
        await deletion.scrollIntoViewIfNeeded()
        await page.screenshot({
          path: new URL(`delete-${stamp}.png`, output).pathname,
          fullPage: true,
        })
        await deletion.click()
        const dialog = page.locator('dialog[open]')
        await dialog.waitFor()
        await page.screenshot({ path: new URL(`confirm-${stamp}.png`, output).pathname })
        await dialog.locator('.button.secondary').click()
        assert.equal(trash.length, 0)
        await deletion.click()
        failDelete = true
        await dialog.locator('.button.danger').click()
        await dialog.locator('.field-error').waitFor()
        assert.equal(new URL(page.url()).pathname, f.path(ids[0]))
        failDelete = false
        await dialog.locator('.button.danger').click()
        await page.waitForURL(`**${adminRoot}/brief`)
        assert.equal(trash.length, 1)
        await page.locator('.project-trash-trigger').click()
        await page.locator('dialog[open] .trash-list .button').click()
        await page.locator('dialog[open] .trash-list').waitFor({ state: 'detached' })
        assert.equal(trash.length, 0)
        assert.equal(f.errors.length, 0, f.errors.join('\n'))
        assert.equal(
          await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
          false,
        )
        results.push({
          language,
          mode,
          width,
          admin: true,
          mouse: width > 960,
          touch: width < 960,
          keyboard: true,
          rollback: width > 960,
          trash: true,
          restore: true,
        })
        await f.context.close()
      }
  // Client has no ordering controls; all statuses and approval conflict render safely.
  for (const language of ['ru', 'en']) {
    const f = await taskWorkspaceFixture(
      browser,
      { language, mode: 'light', width: 390, admin: false },
      { output, port },
    )
    await f.page.goto(`http://127.0.0.1:${port}/project/${uuid}`)
    await f.page.locator('.task-card').first().waitFor()
    assert.equal(await f.page.locator('.task-grip').count(), 0)
    assert.equal(await f.page.locator('.project-trash-trigger').count(), 0)
    for (const status of ['new', 'clarification', 'approved', 'in_progress', 'review', 'done']) {
      Object.assign(f.first, { status, clientApproved: false })
      await f.page.goto(`http://127.0.0.1:${port}${f.path(f.first.id)}`)
      const button = f.page.locator('.delete-idea-action')
      await button.waitFor()
      assert.equal(await button.isEnabled(), status === 'new')
    }
    Object.assign(f.first, { status: 'new', clientApproved: true })
    await f.page.reload()
    assert.equal(await f.page.locator('.delete-idea-action').isDisabled(), true)
    await f.screenshot(`client-approved-${language}`)
    Object.assign(f.first, { status: 'new', clientApproved: false })
    let deleteCount = 0,
      releaseDelete,
      startDelete
    const deletionStarted = new Promise((resolve) => {
      startDelete = resolve
    })
    const gate = new Promise((resolve) => {
      releaseDelete = resolve
    })
    await f.context.route('**/api/client/tasks/' + f.first.id, async (route) => {
      if (route.request().method() !== 'DELETE') return route.fallback()
      deleteCount++
      startDelete()
      await gate
      f.data.tasks.splice(
        f.data.tasks.findIndex((task) => task.id === f.first.id),
        1,
      )
      return route.fulfill({ json: { deleted: true } })
    })
    await f.page.reload()
    await f.page.locator('.delete-idea-action').click()
    const dialog = f.page.locator('dialog[open]')
    await dialog.locator('.button.danger').click()
    await dialog.locator('.button.danger[disabled]').waitFor()
    await deletionStarted
    await f.page.keyboard.press('Enter')
    assert.equal(deleteCount, 1)
    releaseDelete()
    await f.page.waitForURL(`**/project/${uuid}`)
    assert.equal(await f.page.locator(`[data-task-id="${f.first.id}"]`).count(), 0)

    results.push({
      language,
      client: true,
      statuses: 6,
      approvalProtected: true,
      clientDelete: true,
      duplicateBlocked: true,
      noOrderingControls: true,
    })
    await f.context.close()
  }
  await writeFile(
    new URL('browser-results.json', output),
    JSON.stringify({ passed: true, results }, null, 2) + '\n',
  )
  console.log(`PASS ${results.length} browser configurations`)
} finally {
  await browser?.close()
  await server.close()
}
