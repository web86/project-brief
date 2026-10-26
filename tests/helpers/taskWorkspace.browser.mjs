import { allProjectPermissions } from './projectPermissions.js'
import { createDemoData } from '../../src/data/demo.js'
import { notificationEvents } from '../../src/services/notificationSettings.js'
export const uuid = '11111111-1111-4111-8111-111111111111'
export const adminRoot = `/admin/projects/${uuid}`
export async function taskWorkspaceFixture(browser, config, { output, port = 5187, setup }) {
  const context = await browser.newContext({
    viewport: { width: config.width, height: config.width > 960 ? 1050 : 844 },
    locale: config.language === 'ru' ? 'ru-RU' : 'en-GB',
    colorScheme: config.mode === 'light' ? 'light' : 'dark',
    hasTouch: config.width < 960,
    reducedMotion: 'reduce',
  })
  await context.addInitScript(({ language, mode }) => {
    localStorage.setItem('projectbrief.locale', language)
    localStorage.setItem('projectbrief.theme', mode)
  }, config)
  const accountName =
    config.language === 'ru'
      ? config.admin
        ? 'Алексей Морозов'
        : 'Анна Смирнова'
      : config.admin
        ? 'Alex Morgan'
        : 'Anna Smith'
  const data = createDemoData()
  const first = data.tasks[0],
    second = data.tasks.find((task) => task.sectionId !== first.sectionId)
  first.title = 'Добавить личный кабинет с историей заказов и сохранёнными адресами доставки'
  first.developerNotes = 'PRIVATE_TECHNICAL_NOTES'
  first.estimateHours = 5
  first.price = 12000
  first.comments = [
    {
      id: '22222222-2222-4222-8222-222222222222',
      author: 'client',
      authorName: 'Анна',
      authorId: 'anna',
      text: 'Пожалуйста, сохраните выбранные фильтры после возврата к каталогу.',
      createdAt: '2026-10-20T08:00:00Z',
    },
  ]
  const project = {
    ...data.project,
    permissions: config.permissions || { ...allProjectPermissions },
    id: uuid,
    sections: data.sections,
    status: 'active',
    unreadCommentCount: 3,
    unreadCount: 2,
  }
  if (config.longNames)
    project.name =
      'Международная платформа управления проектами и взаимодействия с клиентами — расширенная рабочая область'
  if (config.longList)
    data.tasks.push(
      ...Array.from({ length: 45 }, (_, i) => ({
        ...first,
        id: 'extra-task-' + i,
        title: 'Дополнительная задача с длинным названием и подробным описанием ' + (i + 1),
        position: i + 10,
        comments: [],
        history: [],
      })),
    )
  let selectedWorkspace = 'studio'
  const workspace = { id: 'studio', name: 'Design Studio' }
  const calls = [],
    errors = []
  let failSave = false,
    notificationGate = null,
    releaseNotifications
  const safe = (task) =>
    config.admin
      ? task
      : Object.fromEntries(
          Object.entries(task).filter(
            ([key]) => !['developerNotes', 'estimateHours', 'price'].includes(key),
          ),
        )
  const path = (id) =>
    config.admin ? `${adminRoot}/brief/task/${id}` : `/project/${uuid}/task/${id}`
  await context.route(
    (url) => url.pathname.startsWith('/api/'),
    async (route) => {
      const request = route.request(),
        url = new URL(request.url()),
        pathname = url.pathname,
        body = request.postDataJSON()
      calls.push({ path: pathname, method: request.method(), body })
      let result
      if (pathname === '/api/csrf') result = { token: 'test-csrf' }
      else if (pathname === '/api/admin/me')
        result = {
          user: {
            notificationPreferenceId: 'admin-fixture-id',
            name: accountName,
            email: 'admin@example.test',
            preferredLocale: config.language,
            membershipRole: selectedWorkspace === 'studio' ? config.role || 'owner' : 'admin',
          },
          workspace:
            selectedWorkspace === 'studio'
              ? workspace
              : { id: 'other-workspace', name: 'Other workspace' },
        }
      else if (pathname.endsWith('/select')) {
        selectedWorkspace = pathname.split('/').at(-2)
        result = { message: 'Selected' }
      } else if (pathname === '/api/admin/workspaces')
        result = { data: [workspace, { id: 'other-workspace', name: 'Other workspace' }] }
      else if (pathname === '/api/admin/projects/trash') result = { data: [] }
      else if (pathname === '/api/admin/projects')
        result = {
          data: [
            project,
            ...Array.from({ length: 24 }, (_, i) => ({
              id: 'project-' + i,
              name: 'Дополнительный проект с длинным названием ' + (i + 1),
              unreadCount: i % 3,
            })),
          ],
        }
      else if (pathname === '/api/client/me')
        result = {
          id: config.clientId || 'anna',
          name: accountName,
          preferredLocale: config.language,
        }
      else if (pathname.endsWith('/me/locale')) result = { preferredLocale: body.locale }
      else if (pathname.endsWith('/activity/read')) result = { unreadCount: 0 }
      else if (pathname.endsWith('/discussions/read')) result = { unreadCount: 3 }
      else if (pathname.endsWith('/telegram')) result = { available: false, connected: false }
      else if (pathname.endsWith('/push/status')) result = { available: false }
      else if (pathname === '/api/admin/logout') result = { message: 'Logged out' }
      else if (pathname.endsWith('/notifications')) {
        if (notificationGate) await notificationGate
        result = {
          preferences: { email: true, push: true, telegram: true },
          email: 'fixture@example.test',
          accountEmail: 'fixture@example.test',
          pendingEmail: null,
          emailAvailable: true,
          legacyEmails: [],
          events: Object.fromEntries(
            notificationEvents.map((event) => [event, { email: true, push: true }]),
          ),
          notificationEmail: null,
        }
      } else if (pathname.endsWith('/discussions'))
        result = { data: [], nextCursor: null, unreadCount: 3 }
      else if (pathname.endsWith('/clients')) result = { data: [] }
      else if (pathname.endsWith('/sections')) result = { data: data.sections }
      else if (pathname.endsWith('/comments')) {
        const task = data.tasks.find((task) => pathname.includes(task.id))
        task.comments.push({
          id: '33333333-3333-4333-8333-333333333333',
          author: config.admin ? 'developer' : 'client',
          text: body.text,
          createdAt: new Date().toISOString(),
        })
        result = { data: safe(task) }
      } else if (pathname.endsWith('/approve')) {
        const task = data.tasks.find((task) => pathname.includes(task.id))
        task.clientApproved = true
        result = { data: safe(task) }
      } else if (request.method() === 'PATCH' && pathname.includes('/tasks/')) {
        if (failSave) return route.fulfill({ status: 503, json: { message: 'Try again' } })
        const task = data.tasks.find((task) => pathname.endsWith(task.id))
        Object.assign(task, body)
        result = { data: safe(task) }
      } else if (pathname.endsWith('/tasks')) result = { data: data.tasks.map(safe) }
      else if (
        pathname === `/api/admin/projects/${uuid}` ||
        pathname.startsWith('/api/admin/projects/project-') ||
        pathname === `/api/client/project/${uuid}`
      )
        result = {
          data: pathname.startsWith('/api/admin/projects/project-')
            ? { ...project, id: pathname.split('/').at(-1), name: 'Другой выбранный проект' }
            : project,
        }
      else throw new Error('Unexpected fixture request: ' + pathname)
      await route.fulfill({ json: result })
    },
  )
  await setup?.({ context, data, project, config })
  const page = await context.newPage()
  page.setDefaultTimeout(10000)
  page.on('pageerror', (error) => errors.push(error.stack))
  await page.goto(`http://127.0.0.1:${port}${path(first.id)}`)
  await page.locator('.task-page h1').waitFor()
  const screenshot = async (name) => {
    await page.evaluate(async () => {
      const { toast } = await import('/src/services/toast.js')
      toast.clear()
      scrollTo(0, 0)
    })
    await page
      .locator('dialog[open]')
      .evaluateAll((dialogs) =>
        Promise.all(
          dialogs.flatMap((dialog) =>
            dialog.getAnimations().map((animation) => animation.finished),
          ),
        ),
      )
    return page.screenshot({
      path: new URL(name + '.png', output).pathname,
      fullPage: !name.startsWith('management-') && !name.startsWith('task-navigation-'),
    })
  }
  const waitRoute = async (destination) => {
    await page.waitForURL('**' + destination)
    await page.locator('.loading-overlay').waitFor({ state: 'detached' })
  }
  const openTasks = async () => {
    if (config.width <= 960) {
      await page.locator('#task-navigation-trigger').click()
      await page.locator('#task-navigation-drawer[open]').waitFor()
    }
  }
  return {
    context,
    accountName,
    page,
    calls,
    errors,
    first,
    second,
    data,
    path,
    screenshot,
    waitRoute,
    openTasks,
    holdNotifications: () => {
      notificationGate = new Promise((resolve) => {
        releaseNotifications = resolve
      })
      return () => releaseNotifications()
    },
    setSaveError: (value) => {
      failSave = value
    },
  }
}
