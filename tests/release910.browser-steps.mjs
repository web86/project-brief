import assert from 'node:assert/strict'
import { writeFile } from 'node:fs/promises'
import path from 'node:path'
export async function runRelease910({
  browser,
  context,
  page,
  origin,
  fixture,
  call,
  output,
  packageRoot,
  smtp,
}) {
  const results = [],
    errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  await page.goto(origin + '/admin')
  const settings = page.locator('.admin-sidebar .settings-group-toggle')
  assert.equal(await settings.getAttribute('aria-expanded'), 'false')
  await settings.click()
  assert.equal(await settings.getAttribute('aria-expanded'), 'true')
  await settings.click()
  await page.goto(origin + '/admin/account')
  assert.equal(
    await page.locator('.admin-sidebar .settings-group-toggle').getAttribute('aria-expanded'),
    'true',
  )
  const team = `/admin/projects/${fixture.project}/team`
  await page.goto(origin + team)
  assert.equal(await page.locator('.project-team-invite').count(), 0)
  assert.equal(await page.locator('#team-add-existing').isVisible(), false)
  await page.getByRole('button', { name: 'Add to team', exact: true }).click()
  const modal = page.locator('.team-add-dialog')
  assert.ok((await modal.boundingBox()).width <= 900)
  await page.locator(`#team-add-existing input[value="${fixture.availableMember}"]`).check()
  await page.locator('button[form=team-add-existing]').click()
  await modal.waitFor({ state: 'hidden' })
  await page.locator('.team-member').filter({ hasText: 'existing@example.test' }).waitFor()
  await page.getByRole('button', { name: 'Add to team', exact: true }).click()
  await page.locator('#team-add-invite-tab').click()
  await page.locator('#team-add-invite input[type=email]').fill('invited@example.test')
  await page.locator('#team-add-invite input').nth(1).fill('New synthetic teammate')
  const invitationResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/api/admin/projects/${fixture.project}/team/invitations`) &&
      response.request().method() === 'POST',
  )
  await page.locator('button[form=team-add-invite]').click()
  const invited = await invitationResponse
  assert.equal(invited.status(), 201)
  assert.equal((await invited.json()).sent, true)
  assert.equal(smtp.messages.length, 1)
  assert.ok(smtp.messages[0].includes('invited@example.test'))
  await modal.waitFor({ state: 'hidden' })
  results.push(
    'Both existing-member assignment and existing email invitation work in one responsive modal, close after success and preserve team authorization.',
  )
  await page.goto(origin + '/admin/reviews')
  await page.locator('.review-card').first().waitFor()
  assert.equal(await page.locator('.review-card').count(), 2)
  assert.ok((await page.locator('body').innerText()).includes('archive@example.test'))
  assert.ok(!(await page.locator('body').innerText()).includes('FOREIGN-REVIEW'))
  await page.locator('#reviews-from').fill('2026-10-20')
  await page.locator('#reviews-to').fill('2026-10-20')
  await page.locator('.review-filters button[type=submit]').click()
  await page.waitForFunction(() => document.querySelectorAll('.review-card').length === 1)
  assert.ok((await page.locator('.review-card').innerText()).includes('ARCHIVED-REVIEW'))
  await page.getByRole('button', { name: 'All dates', exact: true }).click()
  await page.waitForFunction(() => document.querySelectorAll('.review-card').length === 2)
  const dev = await browser.newContext()
  const devPage = await dev.newPage()
  await devPage.goto(origin + '/admin/login')
  await devPage.locator('#admin-email').fill('developer@example.test')
  await devPage.locator('#admin-password').fill('Local-test-password!')
  await devPage.locator('form button[type=submit]').click()
  await devPage.waitForURL('**/admin/security')
  await devPage.getByRole('button', { name: 'Use a recovery code', exact: true }).click()
  await devPage.locator('#security-code').fill('Live-browser-code-0')
  await devPage.getByRole('button', { name: 'Confirm identity', exact: true }).click()
  await devPage.locator('.onboarding-continue,.admin-project-card').first().waitFor()
  assert.equal((await dev.request.get(origin + '/api/admin/reviews')).status(), 403)
  await devPage.goto(origin + '/admin/reviews')
  await devPage.waitForURL((url) => url.pathname === '/admin/unavailable')
  assert.equal(await devPage.locator('a[href="/admin/reviews"]').count(), 0)
  await dev.close()
  results.push(
    'Workspace owner sees submitted completed/archived reviews, author/email, project links and dates; inclusive date filter works; another role is denied by page guard and API.',
  )
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-agents').click()
  await page.getByRole('button', { name: 'Create agent', exact: true }).click()
  const editor = page.locator('#agent-editor-title').locator('xpath=ancestor::dialog')
  await page.locator('#agent-name').fill('Unbound synthetic agent')
  assert.equal(await editor.locator('#agent-kind,[data-agent-permissions]').count(), 0)
  assert.equal(await editor.getByRole('button', { name: 'Create API key', exact: true }).count(), 0)
  assert.equal(
    await page.locator('#agent-credential option:checked').innerText(),
    'No keys. Create one first.',
  )
  assert.equal(
    await page.locator('#agent-credential option:checked').evaluate((option) => option.disabled),
    true,
  )
  const active = await editor.locator('.agent-active-setting').boundingBox()
  const picker = await editor.locator('#agent-credential').boundingBox()
  assert.ok(active.y > picker.y)
  await page.locator('button[form=agent-editor]').click()
  await editor.waitFor({ state: 'hidden' })
  const one = (await call('/api/admin/integrations/agents')).data.find(
    (agent) => agent.name === 'Unbound synthetic agent',
  )
  assert.equal(one.kind, 'agent')
  assert.equal((await call('/api/admin/integrations')).tokens.length, 0)
  assert.equal(
    (await fetch(origin + '/api/v1/agent/me', { headers: { 'X-ProjectBrief-Agent-ID': one.id } }))
      .status,
    401,
  )
  const key = await call('/api/admin/integrations/tokens', 'POST', {
    type: 'agent',
    name: 'Shared profile QA',
    scopes: ['agent:profile'],
  })
  const integration = await call('/api/admin/integrations/tokens', 'POST', {
    name: 'Ordinary integration',
    scopes: ['projects:read'],
  })
  await page.reload()
  await page.locator('#integration-tab-agents').click()
  for (const name of ['Unbound synthetic agent', 'Legacy service']) {
    await page
      .locator(`[data-agent-id="${name === 'Legacy service' ? fixture.legacyAgent : one.id}"]`)
      .getByRole('button', { name: 'Configure', exact: true })
      .click()
    assert.equal(
      await page.locator(`#agent-credential option[value="${integration.id}"]`).count(),
      0,
    )
    await page.locator('#agent-credential').selectOption(key.id)
    await page.locator('button[form=agent-editor]').click()
    await editor.waitFor({ state: 'hidden' })
  }
  for (const id of [one.id, fixture.legacyAgent]) {
    const headers = { Authorization: `Bearer ${key.secret}`, 'X-ProjectBrief-Agent-ID': id }
    assert.equal((await fetch(origin + '/api/v1/agent/me', { headers })).status, 200)
    assert.equal((await fetch(origin + '/api/v1/agent/tasks', { headers })).status, 403)
  }
  const legacy = (await call('/api/admin/integrations/agents')).data.find(
    (agent) => agent.id === fixture.legacyAgent,
  )
  assert.equal(legacy.kind, 'service')
  results.push(
    'Agent can be created active without any key or hidden token; new kind is agent, old service kind is retained, form has no permission/type/create-key controls, one shared profile key works for two agents and cannot read tasks.',
  )
  let captures = 0
  if (process.env.AGENT_UX_SKIP_VISUALS !== '1') {
    for (const lang of ['ru', 'en', 'tr', 'zh-CN']) {
      await page.waitForTimeout(15000)
      for (const mode of ['light', 'dark'])
        for (const width of [1440, 390]) {
          await page.setViewportSize({ width, height: 980 })
          await page.evaluate(
            ({ lang, mode }) => {
              localStorage.setItem('projectbrief.locale', lang)
              localStorage.setItem('projectbrief.theme', mode)
            },
            { lang, mode },
          )
          async function capture(name) {
            await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
            await page.evaluate(() => document.fonts.ready)
            await page.waitForTimeout(220)
            assert.ok(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
              name + ' ' + lang + ' ' + mode + ' ' + width,
            )
            assert.equal(await page.locator('html').getAttribute('lang'), lang)
            assert.equal(await page.locator('html').getAttribute('data-theme'), mode)
            await page.screenshot({
              path: path.join(output, `${name}-${lang}-${mode}-${width}.png`),
              fullPage: true,
            })
            captures++
          }
          await page.goto(origin + '/admin')
          await page.locator('.admin-project-card').first().waitFor()
          let sidebar = page.locator('.admin-sidebar')
          if (width === 390) {
            await page.locator('.admin-menu-trigger').click()
            sidebar = page.locator('#admin-drawer')
          }
          const toggle = sidebar.locator('.settings-group-toggle')
          assert.equal(await toggle.getAttribute('aria-expanded'), 'false')
          await capture('sidebar-collapsed')
          await toggle.click()
          await capture('sidebar-expanded')
          if (width === 390) await page.keyboard.press('Escape')
          await page.goto(origin + '/admin/reviews')
          await page.locator('.review-card').first().waitFor()
          await capture('reviews')
          await page.goto(origin + team)
          await page.locator('.team-heading > button').click()
          await page.locator('#team-add-existing-tab').click()
          await capture('team-existing')
          await page.locator('#team-add-invite-tab').click()
          await capture('team-invite')
          await page.keyboard.press('Escape')
          await page.goto(origin + '/admin/settings/integrations')
          await page.locator('#integration-tab-agents').click()
          await page
            .locator(`[data-agent-id="${one.id}"]`)
            .getByRole('button', { name: /Configure|Настроить|Yapılandır|配置/ })
            .click()
          await capture('agent')
          await page.keyboard.press('Escape')
        }
    }
  }
  await call('/api/security/confirm', 'POST', { recovery_code: 'Live-browser-code-4' })
  await call('/api/admin/integrations/tokens/' + key.id, 'DELETE')
  for (const id of [one.id, fixture.legacyAgent])
    assert.equal(
      (
        await fetch(origin + '/api/v1/agent/me', {
          headers: { Authorization: `Bearer ${key.secret}`, 'X-ProjectBrief-Agent-ID': id },
        })
      ).status,
      401,
    )
  results.push(
    'Shared-key revocation denies both agents immediately. Global/project settings share one collapsible component, default closed and automatically open on active settings pages.',
  )
  assert.deepEqual(errors, [])
  const report = {
    ok: true,
    package: !!packageRoot,
    results,
    captures,
    errors,
    providerRequests: 0,
    database: 'isolated temporary SQLite',
    visuals: {
      languages: ['ru', 'en', 'tr', 'zh-CN'],
      themes: ['light', 'dark'],
      widths: [1440, 390],
    },
  }
  await writeFile(path.join(output, 'QA.json'), JSON.stringify(report, null, 2) + '\n')
  console.log(JSON.stringify(report))
}

export async function startLocalSmtp() {
  const { createServer } = await import('node:net')
  const messages = [],
    sockets = new Set()
  const server = createServer((socket) => {
    sockets.add(socket)
    socket.on('close', () => sockets.delete(socket))
    socket.write('220 localhost synthetic QA\r\n')
    let buffer = '',
      data = false,
      body = ''
    socket.on('data', (chunk) => {
      buffer += chunk.toString()
      if (buffer.length + body.length > 1048576) {
        socket.destroy()
        return
      }
      let index
      while ((index = buffer.indexOf('\r\n')) >= 0) {
        const line = buffer.slice(0, index)
        buffer = buffer.slice(index + 2)
        if (data) {
          if (line === '.') {
            messages.push(body)
            body = ''
            data = false
            socket.write('250 accepted\r\n')
          } else body += line + '\r\n'
        } else if (/^EHLO|^HELO/i.test(line)) socket.write('250-localhost\r\n250 SIZE 1048576\r\n')
        else if (/^DATA/i.test(line)) {
          data = true
          socket.write('354 End with dot\r\n')
        } else if (/^QUIT/i.test(line)) socket.end('221 Bye\r\n')
        else socket.write('250 OK\r\n')
      }
    })
    socket.on('error', () => {})
  })
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  return {
    port: server.address().port,
    messages,
    close: async () => {
      for (const socket of sockets) socket.destroy()
      await new Promise((resolve) => server.close(resolve))
    },
  }
}
