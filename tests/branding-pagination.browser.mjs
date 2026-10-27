import assert from 'node:assert/strict'
import { mkdir, writeFile, readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { taskWorkspaceFixture, uuid, adminRoot } from './helpers/taskWorkspace.browser.mjs'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')
const output = new URL('../docs/milestone-7-5/screenshots/', import.meta.url)
await mkdir(output, { recursive: true })
const server = await createServer({
  mode: 'test',
  define: { 'import.meta.env.VITE_DATA_SOURCE': '"api"' },
  server: {
    host: '127.0.0.1',
    port: 5196,
    strictPort: true,
    hmr: false,
    watch: { ignored: ['**/docs/**', '**/api/**', '**/release/**'] },
  },
  logLevel: 'error',
})
const initial = () => ({
  visual: { companyName: 'ASTER Studio', primaryColor: '#17695d' },
  business: {
    legalName: 'ASTER Digital Ltd',
    taxId: 'TR 1234567890',
    bankName: 'International Bank',
    payee: 'ASTER Digital Ltd',
    account: 'TR00 0000 0000 0000 0000 0000 00',
    bic: 'EXAMPLEXX',
    note: '',
  },
  contact: {
    country: 'Türkiye',
    city: 'İstanbul',
    address: 'Tasarım Sokak 14',
    postalCode: '34000',
    phone: '+90 212 000 00 00',
    email: 'hello@example.test',
    website: 'https://agency.example.test',
    extra: '',
  },
  export: {
    logo: true,
    companyName: true,
    address: false,
    contact: false,
    business: false,
    platform: true,
  },
  email: { enabled: true, logo: true, companyName: true, contact: true, accent: true },
  assets: { logo: '/branding-assets/studio/logo.png', favicon: null },
})
const logo =
  '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><path fill="#17695d" d="M32 0l8 23 24 9-24 8-8 24-8-24L0 32l24-9Z"/><circle cx="32" cy="32" r="8" fill="#fff"/></svg>'
let browser
const results = []
try {
  await server.listen()
  browser = await chromium.launch({ headless: true })
  for (const width of [390, 768, 1440])
    for (const mode of ['light', 'dark']) {
      const language =
        width === 390 ? 'ru' : width === 768 ? 'tr' : mode === 'dark' ? 'zh-CN' : 'en'
      let settings = initial(),
        failSave = false,
        assetVersion = 0
      const requests = []
      const f = await taskWorkspaceFixture(
        browser,
        { width, mode, admin: true, language },
        {
          port: 5196,
          output,
          setup: async ({ context, project }) => {
            await context.route('**/api/security/status', (route) =>
              route.fulfill({
                json: {
                  state: 'authenticated',
                  fresh: true,
                  totp: false,
                  passkeys: [],
                  recommendationsPending: false,
                },
              }),
            )
            await context.route('**/branding-assets/**', (route) =>
              route.fulfill(
                route.request().url().endsWith('/icon.png')
                  ? {
                      contentType: 'image/png',
                      body: Buffer.from(
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS+4AAAAASUVORK5CYII=',
                        'base64',
                      ),
                    }
                  : { contentType: 'image/svg+xml', body: logo },
              ),
            )
            await context.route('**/api/admin/workspaces/*/branding**', async (route) => {
              const request = route.request(),
                url = new URL(request.url()),
                method = request.method()
              requests.push({
                path: url.pathname,
                method,
                multipart: request.headers()['content-type']?.includes('multipart/form-data'),
              })
              if (url.pathname.endsWith('/sanitize-svg'))
                return route.fulfill({ json: { svg: logo } })
              if (url.pathname.includes('/assets/')) {
                const kind = url.pathname.split('/').at(-1)
                settings.assets[kind] =
                  method === 'DELETE' ? null : `/branding-assets/studio/logo-${++assetVersion}.png`
                return route.fulfill({ json: { data: settings } })
              }
              if (url.pathname.endsWith('/reset')) {
                const defaults = initial()
                settings.visual = { companyName: '', primaryColor: '' }
                settings.assets = { logo: null, favicon: null }
                settings.email = { ...defaults.email, enabled: false }
                settings.export = defaults.export
              } else if (method === 'PATCH') {
                if (failSave) return route.fulfill({ status: 503, json: { message: 'Offline' } })
                const body = request.postDataJSON()
                requests.at(-1).body = body
                settings[body.section] = { ...settings[body.section], ...body.values }
              }
              return route.fulfill({ json: { data: settings } })
            })
            const projects = Array.from({ length: 55 }, (_, i) => ({
              ...project,
              id: i === 0 ? uuid : 'project-' + i,
              name:
                'Project ' +
                String(i).padStart(2, '0') +
                (i === 0 ? ' — multilingual portal' : ' with a thoughtful long project title'),
              status: i % 3 === 0 ? 'inactive' : 'active',
              updatedAt: '2026-10-27T12:00:00Z',
              tasksCount: 9,
              doneCount: 3,
              clientsCount: 2,
              teamCount: 2,
            }))
            await context.route('**/api/admin/projects?*', projectsRoute)
            await context.route('**/api/admin/projects', projectsRoute)
            async function projectsRoute(route) {
              const url = new URL(route.request().url())
              requests.push({ path: url.pathname, query: url.search })
              let selected = projects.filter(
                (p) =>
                  (!url.searchParams.get('q') ||
                    p.name.toLowerCase().includes(url.searchParams.get('q').toLowerCase())) &&
                  (!url.searchParams.get('status') || p.status === url.searchParams.get('status')),
              )
              if (url.searchParams.get('sort') === 'name')
                selected.sort((a, b) => a.name.localeCompare(b.name))
              const last = Math.max(1, Math.ceil(selected.length / 20)),
                page = Math.min(last, Number(url.searchParams.get('page')) || 1)
              return route.fulfill({
                json: {
                  data: selected.slice((page - 1) * 20, page * 20),
                  meta: {
                    current_page: page,
                    last_page: last,
                    total: selected.length,
                    per_page: 20,
                  },
                },
              })
            }
          },
        },
      )
      const { page } = f
      page.setDefaultTimeout(15000)
      await page.goto(`http://127.0.0.1:5196/admin/settings/branding`)
      await page.locator('#brand-companyName').waitFor()
      await page.locator('#brand-companyName').fill('ASTER / Студия / Çığ / 中文')
      await page.locator('.branding-tabs button').nth(1).click()
      await page.locator('#brand-account').fill('TEST_ONLY_UNSAVED_ACCOUNT')
      await page.locator('.branding-tabs button').nth(0).click()
      failSave = true
      await page.locator('.branding-save button').click()
      await page.locator('.field-error').waitFor()
      assert.equal(
        await page.locator('#brand-companyName').inputValue(),
        'ASTER / Студия / Çığ / 中文',
      )
      failSave = false
      await page.locator('.branding-save button').click()
      await page.waitForFunction(() => document.querySelector('.branding-save button').disabled)
      await page.locator('.branding-tabs button').nth(1).click()
      assert.equal(await page.locator('#brand-account').inputValue(), 'TEST_ONLY_UNSAVED_ACCOUNT')
      await page.locator('.branding-save button').click()
      await page.waitForFunction(() => document.querySelector('.branding-save button').disabled)
      await page.locator('.branding-tabs button').nth(0).click()
      const input = page.locator('input[type=file]').first()
      await input.setInputFiles({
        name: 'logo.svg',
        mimeType: 'image/svg+xml',
        buffer: Buffer.from(
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><script>bad()</script><path d="M0 0L20 20"/></svg>',
        ),
      })
      await page.waitForFunction(() =>
        document.querySelector('.brand-image-preview img')?.getAttribute('src')?.includes('logo-1'),
      )
      assert.ok(requests.some((r) => r.path.endsWith('/sanitize-svg')))
      assert.ok(requests.some((r) => r.path.endsWith('/assets/logo') && r.multipart))
      for (let i = 0; i < 6; i++) {
        await page.locator('.branding-tabs button').nth(i).click()
        await f.screenshot(
          `branding-${['visual', 'business', 'contact', 'export', 'email', 'future'][i]}-${width}-${mode}`,
        )
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
      }
      assert.equal(await page.locator('#future-domain').isDisabled(), true)
      await page.locator('.branding-tabs button').nth(0).click()
      await page.locator('.branding-tabs button').nth(1).click()
      await page.locator('#brand-account').fill('KEEP_DRAFT_AFTER_RESET')
      page.once('dialog', (dialog) => dialog.accept())
      await page.locator('.admin-page-heading > button').click()
      await page.waitForFunction(
        () => document.querySelector('.admin-page-heading > button')?.disabled === false,
      )
      assert.equal(await page.locator('#brand-account').inputValue(), 'KEEP_DRAFT_AFTER_RESET')
      await page.locator('.branding-tabs button').nth(0).click()
      await page.waitForFunction(() => document.querySelector('#brand-companyName')?.value === '')
      await page.locator('#brand-companyName').fill('UNSAVED')
      page.once('dialog', (dialog) => dialog.dismiss())
      await page.evaluate(async () => {
        const { default: router } = await import('/src/router/index.js')
        await router.push('/admin')
      })
      assert.ok(page.url().endsWith('/admin/settings/branding'))
      page.once('dialog', (dialog) => dialog.accept())
      await page.evaluate(async () => {
        const { default: router } = await import('/src/router/index.js')
        await router.push('/admin')
      })
      await page.locator('.admin-project-card').first().waitFor()
      assert.equal(await page.locator('.admin-project-card').count(), 20)
      await page.locator('.project-pagination button').last().click()
      await page.waitForURL('**/admin?page=2')
      await page.waitForFunction(
        () =>
          document.querySelector('.project-pagination [aria-current=page]')?.textContent === '2',
      )
      await f.screenshot(`projects-page2-${width}-${mode}`)
      await page.locator('.project-search input').fill('Project 42')
      await page.waitForFunction(
        () => document.querySelectorAll('.admin-project-card').length === 1,
      )
      assert.ok(page.url().includes('q=Project+42') || page.url().includes('q=Project%2042'))
      assert.ok(!page.url().includes('page=2'))
      await f.screenshot(`projects-filtered-${width}-${mode}`)
      await page.locator('.admin-project-card h2 a').click()
      await page.locator('.project-header h1').waitFor()
      if (width <= 960) {
        await page.locator('.admin-menu-trigger').click()
        await page.locator('#admin-drawer[open]').waitFor()
      }
      await page
        .locator(
          width <= 960
            ? '#admin-drawer .project-all-projects'
            : '.admin-sidebar .project-all-projects',
        )
        .click()
      await page.waitForFunction(
        () => document.querySelectorAll('.admin-project-card').length === 1,
      )
      assert.ok(page.url().includes('q='))
      await page.locator('.project-list-results button').click()
      await page.waitForFunction(
        () => document.querySelectorAll('.admin-project-card').length === 20,
      )
      await page.setViewportSize({ width, height: 600 })
      if (width <= 960) {
        await page.locator('.admin-menu-trigger').click()
        await page.locator('#admin-drawer[open]').waitFor()
      }
      const sidebar = page.locator(width <= 960 ? '#admin-drawer[open]' : '.admin-sidebar')
      const scroll = sidebar.locator('.sidebar-scroll-area')
      await page.waitForFunction(
        (selector) => document.querySelector(selector)?.dataset.fadeBottom === 'true',
        width <= 960 ? '#admin-drawer .sidebar-scroll-area' : '.admin-sidebar .sidebar-scroll-area',
      )
      await page.screenshot({ path: new URL(`sidebar-top-${width}-${mode}.png`, output).pathname })
      const account = await sidebar.locator('.task-account').boundingBox()
      assert.ok(account.y >= 0 && account.y + account.height <= 601)
      if (width <= 960) {
        const bounds = await scroll.boundingBox()
        const cdp = await f.context.newCDPSession(page)
        await cdp.send('Input.synthesizeScrollGesture', {
          x: Math.round(bounds.x + bounds.width / 2),
          y: Math.round(bounds.y + bounds.height / 2),
          yDistance: -180,
          xDistance: 0,
          gestureSourceType: 'touch',
          speed: 400,
        })
        assert.ok(
          await scroll.evaluate((node) => node.scrollTop > 0),
          'touch scroll moved middle area',
        )
        await cdp.detach()
      }
      await sidebar.locator('.sidebar-project').last().focus()
      await page.keyboard.press('Shift+Tab')
      assert.ok(
        await scroll.evaluate((node) => node.contains(document.activeElement)),
        'keyboard focus remains in middle navigation',
      )
      await scroll.evaluate((node) => (node.scrollTop = node.scrollHeight))
      await page.waitForFunction(
        (selector) => document.querySelector(selector)?.dataset.fadeBottom === 'false',
        width <= 960 ? '#admin-drawer .sidebar-scroll-area' : '.admin-sidebar .sidebar-scroll-area',
      )
      assert.equal(await scroll.getAttribute('data-fade-top'), 'true')
      await sidebar.locator('.sidebar-more').click()
      await page.waitForFunction(
        (selector) => document.querySelectorAll(selector).length === 40,
        width <= 960 ? '#admin-drawer .sidebar-project' : '.admin-sidebar .sidebar-project',
      )
      assert.equal(await sidebar.locator('.sidebar-project').count(), 40)
      await page.screenshot({
        path: new URL(`sidebar-bottom-${width}-${mode}.png`, output).pathname,
      })
      assert.equal(
        await sidebar.locator('.personal-settings-nav a[href="/admin/account"]').count(),
        0,
      )
      assert.equal(await sidebar.locator('a[href="/admin/settings/branding"]').count(), 1)
      assert.equal(await sidebar.locator('.task-account a[href="/admin/account"]').count(), 1)
      if (width <= 960) await page.keyboard.press('Escape')
      assert.deepEqual(f.errors, [])
      results.push({ width, mode, language, passed: true })
      await f.context.close()
    }
  for (const mode of ['light', 'dark']) {
    const f = await taskWorkspaceFixture(
      browser,
      { width: mode === 'dark' ? 1440 : 390, mode, admin: false, language: 'ru' },
      {
        port: 5196,
        output,
        setup: async ({ context, project }) => {
          await context.route('**/api/client/project/*/feedback', (route) =>
            route.fulfill({
              json: {
                eligible: false,
                requested: false,
                shown: false,
                dismissed: false,
                submitted: false,
              },
            }),
          )
          project.branding = {
            companyName: 'Client Studio',
            primaryColor: '#17695d',
            logoUrl: '/branding-assets/client/logo.png',
            faviconUrl: '/branding-assets/client/icon.png',
          }
          await context.route('**/branding-assets/**', (route) =>
            route.fulfill(
              route.request().url().endsWith('/icon.png')
                ? {
                    contentType: 'image/png',
                    body: Buffer.from(
                      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS+4AAAAASUVORK5CYII=',
                      'base64',
                    ),
                  }
                : { contentType: 'image/svg+xml', body: logo },
            ),
          )
        },
      },
    )
    await f.page.goto(`http://127.0.0.1:5196/project/${uuid}`)
    await f.page.locator('.workspace-brand-logo:visible').first().waitFor()
    assert.equal(
      await f.page.evaluate(() =>
        getComputedStyle(document.querySelector('.app-shell')).getPropertyValue('--action').trim(),
      ),
      '#17695d',
    )
    assert.ok(
      await f.page
        .locator('link[rel=icon]')
        .first()
        .getAttribute('href')
        .then((v) => v.includes('/client/icon.png')),
    )
    await f.screenshot('client-branded-' + mode)
    await f.page.evaluate(async () => {
      const { default: router } = await import('/src/router/index.js')
      await router.push('/brand-isolation-check')
    })
    await f.page.waitForFunction(
      () =>
        !document
          .querySelector('link[rel=icon]')
          ?.getAttribute('href')
          ?.includes('/client/icon.png'),
    )
    assert.equal(
      await f.page
        .locator('.app-shell')
        .getAttribute('style')
        .then((s) => s?.includes('--action:') || false),
      false,
    )
    assert.equal(await f.page.locator('.workspace-brand-logo:visible').count(), 0)
    results.push({ persona: 'client', mode, brandedPortalAndExit: true })
    assert.deepEqual(f.errors, [])
    await f.context.close()
  }
  await writeFile(
    new URL('../BROWSER-RESULTS.json', output),
    JSON.stringify(results, null, 2) + '\n',
  )
  console.log(
    'PASS branding, uploads, draft isolation, project filters/pagination, URL return, bounded sidebar and fade masks:',
    results.length,
  )
} finally {
  await browser?.close()
  await server.close()
}
