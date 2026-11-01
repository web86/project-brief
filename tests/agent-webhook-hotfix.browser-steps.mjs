import assert from 'node:assert/strict'
import { writeFile } from 'node:fs/promises'
import path from 'node:path'
export async function runHotfix({ page, origin, fixture, call, output, packageRoot }) {
  const errors = [],
    saves = [],
    results = []
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('request', (request) => {
    if (
      /\/api\/admin\/integrations\/agents(?:\/[^/]+)?$/.test(new URL(request.url()).pathname) &&
      ['POST', 'PATCH'].includes(request.method())
    )
      saves.push(Object.keys(request.postDataJSON()))
  })
  await page.goto(origin + '/admin/settings/integrations')
  assert.equal((await call('/api/admin/integrations/agents')).data.length, 0)
  await page.locator('.credential-filters select').waitFor()
  assert.equal(await page.locator('.credential-filters select').count(), 1)
  await page.locator('#integration-tab-webhooks').click()
  await page.getByRole('button', { name: 'Create webhook', exact: true }).click()
  assert.equal(await page.locator('#hook-agent').count(), 0)
  await page.locator('#hook-event-type').selectOption('agent')
  assert.equal(await page.locator('#hook-event-type option').count(), 2)
  await page.locator('#create-webhooks input[value="agent.run.requested"]').waitFor()
  assert.equal(
    await page.locator('#create-webhooks .integration-choices').last().locator('input').count(),
    7,
  )
  await page.locator('#hook-name').fill('Independent synthetic receiver')
  await page.locator('#hook-url').fill('https://example.com/hotfix-events')
  for (const event of ['agent.task.assigned', 'agent.run.requested'])
    await page.locator(`#create-webhooks input[value="${event}"]`).check()
  await page.locator('button[form=create-webhooks]').click()
  await page.locator('.integration-secret-value').waitFor()
  await page.locator('dialog[open]').getByRole('button', { name: 'Close', exact: true }).click()
  const hook = (await call('/api/admin/integrations')).webhooks.find(
    (row) => row.name === 'Independent synthetic receiver',
  )
  assert.equal(hook.agentId, null)
  assert.deepEqual(hook.events, ['agent.task.assigned', 'agent.run.requested'])
  results.push(
    'Agent-event webhook created before any agent, independent identity, seven event choices and no agent-name selector.',
  )
  const first = await call('/api/admin/integrations/tokens', 'POST', {
    type: 'agent',
    name: 'Shared synthetic profile',
    scopes: ['agent:profile'],
  })
  const replacement = await call('/api/admin/integrations/tokens', 'POST', {
    type: 'agent',
    name: 'Replacement synthetic profile',
    scopes: ['agent:profile'],
  })
  await page.reload()
  await page.locator('#integration-tab-agents').click()
  const ids = []
  const editor = page.locator('#agent-editor-title').locator('xpath=ancestor::dialog')
  for (const [name, key] of [
    ['Unbound synthetic agent', null],
    ['Bound synthetic agent', first],
  ]) {
    await page.getByRole('button', { name: 'Create agent', exact: true }).click()
    await page.locator('#agent-name').fill(name)
    await page.locator(`#agent-project-options input[value="${fixture.project}"]`).check()
    assert.equal(await editor.locator('#agent-identity-id').count(), 0)
    assert.equal(await editor.locator('input[type=url]').count(), 0)
    if (key) await page.locator('#agent-credential').selectOption(key.id)
    await page.locator('button[form=agent-editor]').click()
    await editor.waitFor({ state: 'hidden' })
    const agent = (await call('/api/admin/integrations/agents')).data.find(
      (agent) => agent.name === name,
    )
    ids.push(agent.id)
    const card = page.locator(`[data-agent-id="${agent.id}"]`)
    assert.equal(await card.locator('.agent-api-id span').innerText(), agent.id)
    assert.equal(await card.locator('.agent-api-id input,.agent-api-id button').count(), 0)
  }
  // Keep the original settings rate limiter enabled while exercising edit/bind operations.
  await page.waitForTimeout(61000)
  const headers = (key, id) => ({
    Authorization: `Bearer ${key.secret}`,
    'X-ProjectBrief-Agent-ID': id,
  })
  async function configure(id, key, name) {
    await page
      .locator(`[data-agent-id="${id}"]`)
      .getByRole('button', { name: 'Configure', exact: true })
      .click()
    if (key !== undefined) await page.locator('#agent-credential').selectOption(key?.id || '')
    if (name) await page.locator('#agent-name').fill(name)
    await page.locator('button[form=agent-editor]').click()
    await editor.waitFor({ state: 'hidden' })
  }
  await configure(ids[0], first)
  for (const id of ids)
    assert.equal(
      (await fetch(origin + '/api/v1/agent/me', { headers: headers(first, id) })).status,
      200,
    )
  await configure(ids[0], replacement, 'Edited synthetic agent')
  assert.equal(
    (await fetch(origin + '/api/v1/agent/me', { headers: headers(first, ids[0]) })).status,
    403,
  )
  assert.equal(
    (await fetch(origin + '/api/v1/agent/me', { headers: headers(replacement, ids[0]) })).status,
    200,
  )
  await configure(ids[0], null)
  assert.equal(
    (await fetch(origin + '/api/v1/agent/me', { headers: headers(replacement, ids[0]) })).status,
    403,
  )
  await configure(ids[0], first)
  assert.ok(saves.length >= 6 && saves.every((keys) => !keys.includes('webhookUrl')))
  results.push(
    'Platform owner on MariaDB creates with/without a key, edits, attaches/replaces/detaches/rebinds; UUID is selectable card text only and saves never contain webhookUrl.',
  )
  await page.waitForTimeout(61000)
  const mixed = ['project.created', 'agent.task.assigned', 'agent.run.requested']
  await call('/api/admin/integrations/webhooks/' + hook.id, 'PATCH', { events: mixed })
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-webhooks').click()
  async function editHook() {
    await page
      .locator('#integration-panel-webhooks .webhook-item')
      .filter({ hasText: 'Independent synthetic receiver' })
      .getByRole('button', { name: /Edit|Изменить|Düzenle|编辑/, exact: true })
      .click()
  }
  await editHook()
  assert.equal(await page.locator('#hook-event-type').inputValue(), 'integration')
  for (const event of mixed)
    assert.ok(await page.locator(`#create-webhooks input[value="${event}"]`).isChecked())
  await page.locator('button[form=create-webhooks]').click()
  await page
    .locator('#webhook-editor')
    .locator('xpath=ancestor::dialog')
    .waitFor({ state: 'hidden' })
  const saved = (await call('/api/admin/integrations')).webhooks.find((row) => row.id === hook.id)
  assert.deepEqual(saved.events, mixed)
  assert.equal(saved.agentId, null)
  results.push(
    'Editing a mixed ordinary subscription retains all selected agent and integration events and the null identity binding.',
  )
  let captures = 0
  if (process.env.AGENT_UX_SKIP_VISUALS !== '1') {
    await page.waitForTimeout(61000)
    for (const lang of ['ru', 'en', 'tr', 'zh-CN']) {
      await page.waitForTimeout(35000)
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
              `${name} ${lang} ${mode} ${width}`,
            )
            assert.equal(await page.locator('html').getAttribute('lang'), lang)
            assert.equal(await page.locator('html').getAttribute('data-theme'), mode)
            await page.screenshot({
              path: path.join(output, `${name}-${lang}-${mode}-${width}.png`),
              fullPage: true,
            })
            captures++
          }
          await page.goto(origin + '/admin/settings/integrations')
          await page.locator('.credential-filters select').selectOption('agent')
          assert.equal(await page.locator('.credential-filters select').count(), 1)
          await capture('keys')
          await page.locator('#integration-tab-agents').click()
          await page.locator(`[data-agent-id="${ids[0]}"] .agent-api-id`).waitFor()
          await capture('agent-cards')
          await page
            .locator(`[data-agent-id="${ids[0]}"]`)
            .getByRole('button', { name: /Configure|Настроить|Yapılandır|配置/ })
            .click()
          assert.equal(await editor.locator('#agent-identity-id,input[type=url]').count(), 0)
          await capture('agent-editor')
          await page.keyboard.press('Escape')
          await page.locator('#integration-tab-webhooks').click()
          await page
            .locator('#integration-panel-webhooks .integration-section-heading button')
            .click()
          await page.locator('#hook-event-type').selectOption('agent')
          assert.equal(await page.locator('#hook-agent').count(), 0)
          await capture('webhook-agent')
          await page.locator('#hook-event-type').selectOption('integration')
          await capture('webhook-integration')
          await page.keyboard.press('Escape')
          await editHook()
          for (const event of mixed)
            assert.ok(await page.locator(`#create-webhooks input[value="${event}"]`).isChecked())
          await capture('webhook-mixed-edit')
          await page.keyboard.press('Escape')
        }
    }
  }
  await call('/api/security/confirm', 'POST', { recovery_code: 'Live-browser-code-5' })
  await call('/api/admin/integrations/tokens/' + first.id, 'DELETE')
  for (const id of ids)
    assert.equal(
      (await fetch(origin + '/api/v1/agent/me', { headers: headers(first, id) })).status,
      401,
    )
  assert.deepEqual(errors, [])
  const report = {
    ok: true,
    package: !!packageRoot,
    database: 'isolated MariaDB',
    platformOwner: true,
    results,
    captures,
    errors,
    providerRequests: 0,
    webhookNotificationsGrantApiAccess: false,
  }
  await writeFile(path.join(output, 'QA.json'), JSON.stringify(report, null, 2) + '\n')
  console.log(JSON.stringify(report))
}
