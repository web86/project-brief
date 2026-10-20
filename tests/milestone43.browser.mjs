// Uses the existing Playwright installation; no runtime dependency is added.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { createDemoData } from '../src/data/demo.js'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: { host: '127.0.0.1', port: 5186, strictPort: true },
  logLevel: 'error',
})
const output = new URL(
  process.env.MILESTONE43_OUTPUT_DIR || '../docs/milestone-4-3-implementation/',
  import.meta.url,
)
await mkdir(output, { recursive: true })
let browser
const results = []
const projectId = '11111111-1111-4111-8111-111111111111',
  base = `/admin/projects/${projectId}`
const demo = createDemoData()
const targetId = '22222222-2222-4222-8222-222222222222'
const task = {
  ...demo.tasks[0],
  estimateHours: 5,
  price: 12000,
  developerNotes: 'Проверить кеширование и обработку ошибок',
}
task.comments = Array.from({ length: 7 }, (_, i) => ({
  id: i === 5 ? targetId : `message-${i}`,
  author: 'client',
  authorId: 'client',
  authorName: 'Анна Смирнова',
  text: `Комментарий ${i + 1}: Просьба сохранить выбранные фильтры при возврате к списку. ${'Уточнение деталей интерфейса. '.repeat(5)}`,
  createdAt: '2026-10-20T08:00:00Z',
}))
const project = {
  ...demo.project,
  id: projectId,
  name: 'Редизайн интернет-магазина',
  sections: demo.sections,
  status: 'active',
  unreadCount: 0,
  unreadCommentCount: 2,
}
async function fixture({ width, locale, theme, colorScheme, client = false }) {
  const context = await browser.newContext({
    viewport: { width, height: width > 900 ? 1050 : 844 },
    locale: locale === 'ru' ? 'ru-RU' : 'en-GB',
    colorScheme,
    hasTouch: width < 900,
    reducedMotion: 'reduce',
  })
  await context.addInitScript(
    ({ locale, theme }) => {
      localStorage.setItem('projectbrief.locale', locale)
      localStorage.setItem('projectbrief.theme', theme)
    },
    { locale, theme },
  )
  const calls = [],
    errors = [],
    read = new Set()
  let delay = 0,
    fail = false
  await context.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request(),
        url = new URL(request.url()),
        path = url.pathname,
        body = request.postDataJSON()
      calls.push({ path, method: request.method(), body })
      let payload
      if (path === '/api/csrf') payload = { token: 'test' }
      else if (path === '/api/admin/me')
        payload = {
          user: {
            name: 'Алексей',
            email: 'alex@example.test',
            preferredLocale: locale,
            membershipRole: 'owner',
          },
          workspace: { id: 'workspace', name: 'Design Studio' },
        }
      else if (path === '/api/admin/workspaces')
        payload = { data: [{ id: 'workspace', name: 'Design Studio' }] }
      else if (path === '/api/admin/projects') payload = { data: [project] }
      else if (path.endsWith('/me/locale')) payload = { preferredLocale: body.locale }
      else if (path === '/api/client/me')
        payload = { id: 'client', name: 'Анна Смирнова', preferredLocale: locale }
      else if (path.endsWith('/activity/read')) payload = { unreadCount: 0 }
      else if (path.endsWith('/discussions/read')) {
        for (const id of body.ids || [targetId, 'general']) read.add(id)
        payload = { unreadCount: Math.max(0, 2 - read.size) }
      } else if (path.endsWith('/discussions')) {
        if (request.method() === 'POST')
          payload = {
            data: {
              id: 'sent',
              text: body.text,
              authorName: 'Алексей',
              createdAt: '2026-10-20T09:00:00Z',
              unread: false,
            },
          }
        else {
          const general = url.searchParams.get('kind') === 'general',
            cursor = url.searchParams.get('cursor')
          let data = general
            ? [
                {
                  id: 'general',
                  authorName: 'Мария Кузнецова',
                  createdAt: '2026-10-20T07:30:00Z',
                  text: 'Обсудим порядок запуска: сначала каталог и карточка товара, затем личный кабинет.',
                  unread: !read.has('general'),
                },
              ]
            : [
                {
                  ...task.comments[5],
                  unread: !read.has(targetId),
                  task: { id: task.id, title: task.title, section: demo.sections[0].name },
                },
              ]
          if (cursor)
            data = [
              {
                ...data[0],
                id: 'older',
                unread: false,
                text: 'Предыдущий комментарий по этой задаче.',
              },
            ]
          if (url.searchParams.get('filter') === 'unread') data = data.filter((item) => item.unread)
          payload = {
            data,
            unreadCount: Math.max(0, 2 - read.size),
            nextCursor: general || cursor ? null : 'older-page',
          }
        }
      } else if (path.endsWith('/tasks') || path === '/api/client/tasks') {
        const tasks = [task, ...demo.tasks.slice(1)]
        payload = {
          data: client
            ? tasks.map(({ estimateHours, price, developerNotes, ...safe }) => safe)
            : tasks,
        }
      } else if (path.endsWith('/clients')) payload = { data: [] }
      else if (path.endsWith('/sections')) payload = { data: demo.sections }
      else if (path.includes('/push/status'))
        payload = { available: false, subscribed: false, vapidPublicKey: null }
      else if (path.includes('/notifications')) payload = { settings: {}, delivery: {} }
      else payload = { data: project }
      if (
        delay &&
        (path.endsWith('?summary=1') ||
          url.searchParams.has('summary') ||
          path.endsWith('/discussions') ||
          path.endsWith('/clients'))
      ) {
        await new Promise((resolve) => setTimeout(resolve, delay))
        if (fail) return route.fulfill({ status: 503, json: { message: 'Unavailable' } })
      }
      await route.fulfill({ json: payload })
    },
  )
  const page = await context.newPage()
  page.on('pageerror', (error) => {
    errors.push(error.message)
    console.log(error.stack)
  })
  page.setDefaultTimeout(10000)
  await page.goto(`http://127.0.0.1:5186${client ? `/project/${projectId}` : base + '/brief'}`)
  try {
    await page.locator('.task-card').first().waitFor()
  } catch (error) {
    console.log(
      JSON.stringify({
        errors,
        calls,
        url: page.url(),
        text: await page.locator('body').innerText(),
      }),
    )
    throw error
  }
  const screenshot = (name) =>
    page.screenshot({ path: new URL(name + '.png', output).pathname, fullPage: true })
  const navigate = async (section, { wait = true } = {}) => {
    const inTask = await page.locator('.task-admin-trigger').count()
    if (inTask) {
      await page.locator('.task-admin-trigger').click()
      await page.locator('#admin-drawer[open]').waitFor()
    }
    const navigation = page.locator(inTask ? '.project-panel-nav' : '.project-subnav')
    await navigation.locator(`a[href="${base}/${section}"]`).click()
    if (wait) {
      try {
        await page.waitForURL(`**/${section}`)
      } catch (error) {
        console.log(
          JSON.stringify({
            section,
            url: page.url(),
            errors,
            calls: calls.slice(-12),
            text: await page.locator('body').innerText(),
          }),
        )
        throw error
      }
      await page.locator('.loading-overlay').waitFor({ state: 'detached' })
    }
  }
  return {
    context,
    page,
    calls,
    errors,
    screenshot,
    navigate,
    setDelay: (value, error = false) => {
      delay = value
      fail = error
    },
  }
}
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const config of [
    { width: 1440, locale: 'ru', theme: 'light', colorScheme: 'light' },
    { width: 1440, locale: 'en', theme: 'dark', colorScheme: 'dark' },
    { width: 390, locale: 'ru', theme: 'light', colorScheme: 'light' },
    { width: 320, locale: 'en', theme: 'system', colorScheme: 'dark' },
  ]) {
    const f = await fixture(config),
      { page } = f
    const label = `${config.width}-${config.locale}-${config.theme}`
    const noOverflow = async () =>
      assert.ok(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        'No horizontal overflow',
      )
    await noOverflow()
    await f.screenshot(`cards-${label}`)
    assert.equal(
      await page.locator('.card-estimate').first().innerText(),
      `5 ${config.locale === 'ru' ? 'ч' : 'h'}`,
    )
    const tooltip = page.locator('.card-price-tooltip').first()
    assert.equal(await tooltip.isVisible(), false)
    if (config.width > 900) await page.locator('.card-price').first().hover()
    else await page.locator('.card-price').first().focus()
    assert.equal(await tooltip.isVisible(), true)
    assert.match(await tooltip.innerText(), /12.*000/)
    await f.navigate('discussions')
    await page.locator('.discussion-page').waitFor()
    assert.equal(
      f.calls.some((call) => call.path.endsWith('/discussions/read')),
      false,
      'Opening discussions never marks messages read',
    )
    await noOverflow()
    await f.screenshot(`discussions-${label}`)
    await page
      .locator('.discussion-page')
      .getByRole('button', { name: config.locale === 'ru' ? 'Загрузить ещё' : 'Load more' })
      .click()
    await page.getByText('Предыдущий комментарий по этой задаче.').waitFor()
    assert.equal(await page.locator('.discussion-item').count(), 3)
    await page.locator('#project-comment').fill('Сохранённый черновик обсуждения')
    await f.navigate('export')
    await page.locator('.export-form').waitFor()
    const notes = page.getByRole('checkbox', {
      name: config.locale === 'ru' ? 'Показать технические заметки' : 'Show technical notes',
    })
    assert.equal(await notes.isChecked(), false)
    await notes.check()
    await noOverflow()
    await f.screenshot(`export-${label}`)
    await f.navigate('discussions')
    assert.equal(
      await page.locator('#project-comment').inputValue(),
      'Сохранённый черновик обсуждения',
    )
    await page.locator('.discussion-actions a').first().click()
    const target = page.locator(`#comment-${targetId}`)
    await target.waitFor()
    await page.waitForFunction(
      (id) => document.getElementById(`comment-${id}`)?.classList.contains('is-target'),
      targetId,
    )
    const bounds = await target.boundingBox()
    assert.ok(
      bounds.y < (config.width > 900 ? 1050 : 844) && bounds.y + bounds.height > 0,
      'Target comment is in viewport',
    )
    await f.screenshot(`comment-target-${label}`)
    await page.waitForTimeout(250)
    assert.ok(
      f.calls.some(
        (call) => call.path.endsWith('/discussions/read') && call.body.ids.includes(targetId),
      ),
      'Visible task comment is marked read',
    )
    await page.waitForTimeout(2400)
    assert.equal(await target.evaluate((element) => element.classList.contains('is-target')), false)
    await f.navigate('brief')
    f.setDelay(700)
    const transition = f.navigate('clients')
    await page.locator('.loading-overlay').waitFor()
    assert.equal(await page.locator('.dashboard').isVisible(), true)
    assert.ok(await page.locator('.dashboard').evaluate((element) => !!element.closest('[inert]')))
    await f.screenshot(`transition-${label}`)
    await transition
    await page.waitForURL('**/clients')
    await page.locator('.loading-overlay').waitFor({ state: 'detached' })
    f.setDelay(0)
    await f.navigate('brief')
    f.setDelay(350, true)
    await f.navigate('discussions', { wait: false })
    await page.locator('.toast-error').first().waitFor()
    if (f.errors.length)
      console.log(JSON.stringify({ errors: f.errors, calls: f.calls.slice(-15) }))
    assert.ok(page.url().endsWith('/brief'), 'Failed navigation preserves URL')
    assert.equal(await page.locator('.dashboard').isVisible(), true)
    assert.equal(await page.locator('.loading-overlay').count(), 0)
    const errorStyle = await page
      .locator('.toast-error')
      .first()
      .evaluate((element) => ({
        bg: getComputedStyle(element).backgroundColor,
        text: getComputedStyle(element).color,
      }))
    assert.deepEqual(errorStyle, { bg: 'rgb(153, 27, 27)', text: 'rgb(255, 247, 247)' })
    await page.evaluate(async () => {
      const { toast } = await import('/src/services/toast.js')
      toast.success('Сохранено / Saved')
      toast.info('Настройки обновлены / Settings updated')
    })
    await page.locator('.toast-success').waitFor()
    assert.equal(
      await page
        .locator('.toast-success')
        .evaluate((element) => getComputedStyle(element).backgroundColor),
      'rgb(20, 83, 45)',
    )
    await f.screenshot(`toasts-${label}`)
    f.setDelay(0)
    await f.navigate('discussions')
    await page.locator('.discussion-page').waitFor()
    assert.equal(await page.locator('.loading-overlay').count(), 0)
    assert.deepEqual(f.errors, [])
    results.push({ ...config, passed: true, requests: f.calls.length })
    await f.context.close()
  }
  const client = await fixture({
    width: 390,
    locale: 'ru',
    theme: 'light',
    colorScheme: 'light',
    client: true,
  })
  assert.equal(await client.page.locator('.card-price, .card-estimate, .project-subnav').count(), 0)
  await client.screenshot('client-mobile-privacy')
  await client.context.close()
  await writeFile(
    new URL('results.json', output),
    JSON.stringify({ results, clientPrivacy: true }, null, 2) + '\n',
  )
  console.log(JSON.stringify({ passed: results.length, clientPrivacy: true }))
} finally {
  await browser?.close()
  await server.close()
}
