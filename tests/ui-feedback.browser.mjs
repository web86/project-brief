import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const before = process.env.UI_FEEDBACK_BEFORE === '1'
const output = new URL('../docs/milestone-7-4/screenshots/', import.meta.url)
await mkdir(output, { recursive: true })
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5194, strictPort: true },
  logLevel: 'error',
})
const results = []
let browser
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const width of [390, 768, 1440])
    for (const mode of ['light', 'dark']) {
      for (const admin of [true, false]) {
        const language =
          width === 390 ? 'ru' : width === 768 ? 'tr' : mode === 'dark' ? 'zh-CN' : 'en'
        let projectReference
        const clientBodies = []
        let failComment = false,
          failFeedback = false,
          submittedCount = 0
        const feedback = {
          eligible: true,
          requested: true,
          shown: false,
          dismissed: false,
          submitted: false,
        }
        const f = await taskWorkspaceFixture(
          browser,
          { width, mode, admin, language },
          {
            port: 5194,
            output,
            setup: async ({ context, data, project }) => {
              projectReference = project
              await context.route(`**/api/admin/projects/${uuid}/feedback/request`, (route) => {
                project.feedback = { eligible: true, requested: true, submitted: false }
                return route.fulfill({ json: { requested: true } })
              })
              await context.route('**/api/*/tasks/*/comments', async (route) => {
                if (failComment) return route.fulfill({ status: 503, json: { message: 'Offline' } })
                const request = route.request()
                const raw = request.postData() || ''
                const text = request.headers()['content-type']?.includes('application/json')
                  ? request.postDataJSON().text
                  : raw.match(/name="text"\r\n\r\n([\s\S]*?)\r\n--/)?.[1]
                const task = data.tasks.find((task) => request.url().includes(task.id))
                task.comments.push({
                  id: 'newest-comment',
                  sequence: 100,
                  author: admin ? 'developer' : 'client',
                  text,
                  createdAt: new Date().toISOString(),
                })
                return route.fulfill({ json: { data: task } })
              })
              await context.route(`**/api/client/project/${uuid}/feedback**`, async (route) => {
                const request = route.request()
                if (request.method() === 'POST') {
                  if (request.url().endsWith('/invitation')) {
                    const action = request.postDataJSON().action
                    const autoOpen = action === 'claim' && !feedback.shown && !feedback.submitted
                    feedback.shown = true
                    if (action === 'dismiss') feedback.dismissed = true
                    return route.fulfill({ json: { ...feedback, autoOpen } })
                  }
                  if (failFeedback)
                    return route.fulfill({ status: 503, json: { message: 'Offline' } })
                  feedback.submitted = true
                  submittedCount++
                }
                return route.fulfill({ json: feedback })
              })
              await context.route('**/api/security/status', (route) =>
                route.fulfill({
                  json: {
                    authenticated: true,
                    fresh: true,
                    recommended: false,
                    passkeys: [],
                    totp: { enabled: false },
                  },
                }),
              )
              await context.route(`**/api/admin/projects/${uuid}/team`, (route) =>
                route.fulfill({
                  json: {
                    projects: [{ id: uuid, name: 'Project Brief' }],
                    invitations: [
                      {
                        id: 'invite',
                        name: 'Мария Александрова',
                        email: 'maria.invitation@example.test',
                        status: 'pending',
                        expiresAt: '2026-11-03',
                        projects: [uuid],
                      },
                    ],
                    members: [
                      {
                        id: 'owner',
                        user: {
                          name: 'Александр Константинопольский',
                          email: 'alexander.long.contact.address@example.test',
                        },
                        role: 'owner',
                        joinedAt: '2026-10-01',
                        projects: [uuid],
                      },
                      {
                        id: 'dev',
                        user: { name: 'Mehmet Yılmaz', email: 'mehmet.developer@example.test' },
                        role: 'admin',
                        joinedAt: '2026-10-20',
                        projects: [uuid],
                      },
                    ],
                  },
                }),
              )
              await context.route(
                `**/api/admin/projects/${uuid}/clients`,
                (route) => (
                  clientBodies.push(
                    route.request().method() === 'POST' ? route.request().postDataJSON() : null,
                  ),
                  route.fulfill({
                    json: {
                      invitation: route.request().method() === 'POST' ? 'failed' : 'not_requested',
                      data: [
                        {
                          id: 'anna',
                          name: 'Анна Смирнова — представитель международной команды',
                          email: 'anna.long.client.contact.address@example.test',
                          active: true,
                          lastUsedAt: '2026-10-27',
                          accessLinks: [{ id: 1, active: true }],
                        },
                        {
                          id: 'john',
                          name: 'John Smith',
                          email: 'john@example.test',
                          active: false,
                          accessLinks: [],
                        },
                      ],
                    },
                  })
                ),
              )
            },
          },
        )
        const { page } = f
        const label = `${width}-${mode}-${admin ? 'admin' : 'client'}`
        await f.screenshot(`${before ? 'before' : 'after'}-idea-${label}`)
        if (!before) {
          await page.locator('#comment-text').fill('DRAFT: keep this while collapsing')
          await page.locator('.idea-disclosure > summary').click()
          assert.equal(
            await page.locator('#comment-text').inputValue(),
            'DRAFT: keep this while collapsing',
          )
          assert.equal(await page.locator('.idea-disclosure').getAttribute('open'), null)
          await page.reload()
          await page.locator('.task-page h1').waitFor()
          assert.equal(await page.locator('.idea-disclosure').getAttribute('open'), null)
          await page.locator('.idea-disclosure > summary').click()
          const form = await page.locator('.comment-form').boundingBox()
          const list = await page.locator('.comment-list').boundingBox()
          assert.ok(form.y < list.y)
          failComment = true
          await page.locator('#comment-text').fill('NEWEST_COMMENT')
          await page.locator('.comment-form button[type="submit"]').click()
          await page.locator('.toast-error').waitFor()
          assert.equal(await page.locator('#comment-text').inputValue(), 'NEWEST_COMMENT')
          failComment = false
          await page.locator('#comment-text').fill('NEWEST_COMMENT')
          await page.locator('.comment-form button[type="submit"]').click()
          await page.locator('.comment').first().getByText('NEWEST_COMMENT').waitFor()
          assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
          if (!admin) {
            const details = await page.locator('.details-surface').boundingBox()
            const discussion = await page.locator('.comments-panel').boundingBox()
            assert.ok(discussion.y >= details.y + details.height)
            assert.ok(Math.abs(discussion.x - details.x) < 2)
          }
        }
        if (!before && admin) {
          f.data.tasks.forEach((task) => (task.status = 'done'))
          projectReference.feedback = { eligible: true, requested: false, submitted: false }
          projectReference.tasksCount = f.data.tasks.length
          projectReference.doneCount = f.data.tasks.length
        }
        if (admin)
          for (const section of ['team', 'clients']) {
            await page.goto(`http://127.0.0.1:5194${adminRoot}/${section}`)
            await page
              .locator(section === 'team' ? '.team-member' : '.managed-client')
              .first()
              .waitFor()
            await f.screenshot(`${before ? 'before' : 'after'}-${section}-${width}-${mode}`)
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
            if (!before && section === 'clients') {
              await page.locator('.management-heading button').click()
              await page.locator('#new-client-name').fill('New invited client')
              await page.locator('.client-invitation-option input').check()
              assert.equal(await page.locator('#new-client-email').getAttribute('required'), '')
              await page.locator('#new-client-email').fill('new-client@example.test')
              await f.screenshot(`after-client-invitation-${width}-${mode}`)
              await page.locator('.client-edit button[type="submit"]').click()
              await page.locator('.toast-warning').waitFor()
              assert.equal(clientBodies.filter(Boolean).at(-1).sendInvitation, true)
              await page.locator('.feedback-request').click()
              assert.equal(await page.locator('.feedback-request').isDisabled(), true)
              await page.goto(`http://127.0.0.1:5194/admin`)
              await page.locator('.admin-project-card').first().waitFor()
              assert.equal(
                await page
                  .locator('.admin-project-card')
                  .first()
                  .locator('.feedback-request')
                  .isDisabled(),
                true,
              )
              await page
                .locator('.admin-project-card')
                .first()
                .screenshot({
                  path: new URL(`after-project-card-${width}-${mode}.png`, output).pathname,
                })
            }
          }
        if (!before && !admin) {
          f.data.tasks.forEach((task) => (task.status = 'done'))
          await page.goto(`http://127.0.0.1:5194/project/${uuid}`)
          await page.locator('#project-feedback-title').waitFor({ state: 'visible' })
          assert.equal(await page.locator('[form="project-feedback-form"]').isDisabled(), true)
          await page.screenshot({
            path: new URL(`after-feedback-${width}-${mode}.png`, output).pathname,
          })
          await page.keyboard.press('Escape')
          await page.locator('dialog[open]').waitFor({ state: 'hidden' })
          await page.reload()
          await page.locator('.feedback-link').waitFor()
          assert.equal(await page.locator('dialog[open]').count(), 0)
          await page.locator('button.feedback-link').click()
          await page.locator('input[name="feedback-rating"]').first().focus()
          await page.keyboard.press('Space')
          for (let index = 0; index < 4; index++) await page.keyboard.press('ArrowRight')
          assert.equal(
            await page.locator('input[name="feedback-rating"]:checked').inputValue(),
            '5',
          )
          await page.locator('#feedback-text').fill('Спасибо! <script>UNTRUSTED</script>')
          failFeedback = true
          await page.locator('[form="project-feedback-form"]').click()
          await page.locator('#project-feedback-form .field-error').waitFor()
          assert.equal(
            await page.locator('#feedback-text').inputValue(),
            'Спасибо! <script>UNTRUSTED</script>',
          )
          failFeedback = false
          await page.locator('[form="project-feedback-form"]').click()
          await page.locator('dialog[open]').waitFor({ state: 'hidden' })
          assert.equal(submittedCount, 1)
          await page.reload()
          await page.locator('span.feedback-link').waitFor()
          assert.equal(await page.locator('dialog[open]').count(), 0)
          assert.equal(await page.locator('button.feedback-link').count(), 0)
        }
        await page.evaluate(async () => {
          const { toast } = await import('/src/services/toast.js')
          toast.clear()
          toast.success('Сохранено')
          toast.error(
            'Длинное сообщение: проверьте соединение с сервером и повторите попытку позже.',
          )
          toast.warning('Проверьте поля')
          toast.info('Новая информация')
        })
        await page.screenshot({
          path: new URL(`${before ? 'before' : 'after'}-toasts-${label}.png`, output).pathname,
        })
        if (!before && width === 390) {
          const viewport = await page.locator('.toast-viewport').boundingBox()
          assert.equal(viewport.x, 0)
          assert.equal(viewport.y, 0)
          assert.equal(viewport.width, width)
          const boxes = await page.locator('.app-toast').evaluateAll((nodes) =>
            nodes.map((n) => {
              const b = n.getBoundingClientRect()
              return { y: b.y, bottom: b.bottom }
            }),
          )
          boxes.slice(1).forEach((box, i) => assert.ok(box.y >= boxes[i].bottom))
        }
        assert.deepEqual(f.errors, [])
        results.push({ width, mode, admin, language, passed: true })
        await f.context.close()
      }
    }
  await writeFile(
    new URL(`../${before ? 'BEFORE' : 'BROWSER'}-RESULTS.json`, output),
    JSON.stringify(results, null, 2) + '\n',
  )
  console.log(`PASS: UI feedback ${before ? 'baseline' : 'checks'} (${results.length})`)
} finally {
  await browser?.close()
  await server.close()
}
