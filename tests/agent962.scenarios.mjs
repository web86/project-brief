import assert from 'node:assert/strict'
export async function runAgent962Qa({
  page,
  context,
  origin,
  fixture,
  agentId,
  attachmentId,
  taskUrl,
  call,
  agentCall,
  replaceAgentToken,
}) {
  // Exercise real throttles without exhausting the settings window from the legacy E2E phase.
  await page.waitForTimeout(55_000)
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-agents').click()
  const card = page.locator(`[data-agent-id="${agentId}"]`)
  const dialog = page.locator('dialog[open]')
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  await dialog.locator('[data-agent-permissions] summary').click()
  const advanced = [
    'Read all tasks in allowed projects',
    'Read comments on accessible tasks',
    'Read client project discussions',
    'Read internal project team chat',
    'Read attachments of accessible tasks',
    'Read attachments of allowed discussions',
  ]
  for (const label of advanced) {
    assert.equal(
      await dialog.getByRole('checkbox', { name: label, exact: false }).isChecked(),
      false,
    )
    await dialog.getByRole('checkbox', { name: label, exact: false }).check()
  }
  assert.ok((await dialog.textContent()).includes('third-party service'))
  await dialog.getByRole('button', { name: 'Save', exact: true }).click()
  await page.waitForFunction(
    () => !document.querySelector('#agent-editor-title')?.closest('dialog').open,
  )
  assert.equal((await agentCall('projects/' + fixture.project + '/tasks')).status, 403)
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  await dialog.locator('[data-agent-connection] summary').click()
  await dialog.getByRole('checkbox', { name: 'agent:projects:tasks:read', exact: true }).check()
  const expiry = await page.evaluate(() => {
    const d = new Date(Date.now() + 86400000),
      pad = (value) => String(value).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
  })
  await dialog.locator('#agent-token-expiry').fill(expiry)
  await dialog.getByRole('button', { name: 'Create API key', exact: true }).click()
  await page.waitForFunction(() =>
    document.querySelector('.agent-secret')?.value?.startsWith('pb_'),
  )
  const secret = await page.locator('.agent-secret').inputValue()
  await dialog.getByRole('button', { name: 'Close dialog', exact: true }).last().click()
  assert.equal(await page.locator('.agent-secret').inputValue(), '')
  assert.equal((await agentCall('me')).status, 200) // Key creation never silently rotates the old token.
  async function read(path) {
    return fetch(origin + '/api/v1/agent/' + path, {
      headers: { Authorization: 'Bearer ' + secret, Accept: 'application/json' },
    })
  }
  const projectRoot = '/api/admin/projects/' + fixture.project
  await call(projectRoot + '/team-chat', 'POST', {
    text: 'PRIVATE-TEAM-CHAT-962',
    submissionId: crypto.randomUUID(),
  })
  await call(projectRoot + '/discussions', 'POST', {
    text: 'Client discussion 962',
    submissionId: crypto.randomUUID(),
  })
  assert.equal((await read('projects/' + fixture.project + '/tasks')).status, 200)
  const tasks = await (await read('projects/' + fixture.project + '/tasks')).json()
  assert.ok(tasks.data.length >= 2)
  assert.ok(!JSON.stringify(tasks).includes('Original private technical notes'))
  assert.ok(!JSON.stringify(tasks).includes('owner@example.test'))
  assert.equal((await read('projects/' + fixture.reviewed + '/tasks')).status, 404)
  const comments = await (await read('tasks/' + fixture.task + '/comments')).json()
  assert.ok(comments.data.some((comment) => comment.teamOnly))
  const discussions = await (await read('projects/' + fixture.project + '/discussions')).json()
  assert.ok(discussions.data.some((comment) => comment.text === 'Client discussion 962'))
  assert.ok(!JSON.stringify(discussions).includes('PRIVATE-TEAM-CHAT-962'))
  const team = await (await read('projects/' + fixture.project + '/team-chat')).json()
  assert.ok(team.data.some((comment) => comment.text === 'PRIVATE-TEAM-CHAT-962'))
  assert.equal((await read('attachments/' + attachmentId + '/download')).status, 200)
  let agent = (await call('/api/admin/integrations/agents')).data.find(
    (agent) => agent.id === agentId,
  )
  const settings = {
    name: agent.name,
    description: agent.description,
    kind: agent.kind,
    active: true,
    projectIds: agent.projectIds,
    webhookUrl: null,
  }
  const race = await Promise.all([
    ...Array.from({ length: 4 }, () => read('projects/' + fixture.project + '/tasks')),
    call('/api/admin/integrations/agents/' + agentId, 'PATCH', {
      ...settings,
      permissions: { projectTasks: false, teamChat: false, reports: false },
    }),
  ])
  for (const response of race.slice(0, 4)) assert.ok([200, 403].includes(response.status))
  assert.equal((await read('projects/' + fixture.project + '/tasks')).status, 403)
  assert.equal((await read('projects/' + fixture.project + '/team-chat')).status, 403)
  assert.equal((await read('attachments/' + attachmentId + '/download')).status, 404)
  const assignment = (await call('/api/admin/tasks/' + fixture.task)).data.agentAssignments[0]
  const denied = await agentCall('tasks/' + fixture.task + '/reports', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': 'revoked-registration-write',
    },
    body: JSON.stringify({ assignmentId: assignment.id, state: 'submitted', summary: 'Denied' }),
  })
  assert.equal(denied.status, 403)
  await call('/api/admin/integrations/agents/' + agentId, 'PATCH', {
    ...settings,
    permissions: { projectTasks: true, teamChat: true, reports: true },
  })
  const clientContext = await page
    .context()
    .browser()
    .newContext({ viewport: { width: 390, height: 980 } })
  try {
    await clientContext.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
    const client = await clientContext.newPage()
    await client.goto(origin + '/access/' + fixture.token)
    await client.waitForURL('**/project/' + fixture.project)
    const clientTask = async () =>
      (await (await clientContext.request.get(origin + '/api/client/tasks/' + fixture.task)).json())
        .data
    const before = await clientTask()
    assert.ok(before.comments.every((comment) => comment.author !== 'agent'))
    assert.ok(!JSON.stringify(before).includes('Latest UX report'))
    assert.ok(!JSON.stringify(before).includes('PRIVATE-TEAM-CHAT-962'))
    assert.equal(
      (
        await clientContext.request.get(
          origin + '/api/client/attachments/' + attachmentId + '/download',
        )
      ).status(),
      404,
    )
    await page.goto(origin + taskUrl)
    await page
      .locator('.agent-latest-report')
      .getByRole('button', { name: 'Share with client', exact: true })
      .click()
    await dialog.locator('#agent-publication-text').fill('Reviewed client-facing outcome 962')
    assert.equal(await dialog.locator('input:checked').count(), 0)
    await dialog.getByRole('checkbox', { name: 'https://example.test/pull/1', exact: true }).check()
    await dialog.getByRole('button', { name: 'Publish to client', exact: true }).click()
    await page.waitForFunction(
      () => !document.querySelector('#agent-share-title')?.closest('dialog').open,
    )
    let published = await clientTask()
    assert.ok(
      published.comments.some(
        (comment) =>
          comment.author === 'developer' &&
          comment.text.includes('Reviewed client-facing outcome 962'),
      ),
    )
    assert.ok(!JSON.stringify(published).includes('Latest UX report'))
    assert.ok(!JSON.stringify(published).includes('https://example.test/result'))
    const fileComment = page.locator('.comment.agent').filter({ hasText: 'result.txt' })
    await fileComment.getByRole('button', { name: 'Share with client', exact: true }).click()
    await dialog.locator('#agent-publication-text').fill('Reviewed attachment for client 962')
    assert.equal(await dialog.locator('input:checked').count(), 0)
    await dialog.getByRole('checkbox', { name: 'result.txt', exact: true }).check()
    await dialog.getByRole('button', { name: 'Publish to client', exact: true }).click()
    await page.waitForFunction(
      () => !document.querySelector('#agent-share-title')?.closest('dialog').open,
    )
    published = await clientTask()
    const copy = published.comments.find(
      (comment) => comment.text === 'Reviewed attachment for client 962',
    )
    assert.equal(copy.attachments.length, 1)
    assert.notEqual(copy.attachments[0].id, attachmentId)
    const downloaded = await clientContext.request.get(origin + copy.attachments[0].downloadUrl)
    assert.equal(downloaded.status(), 200)
    assert.equal(await downloaded.text(), 'Independent executor result.')
    assert.equal(
      (
        await clientContext.request.get(
          origin + '/api/client/attachments/' + attachmentId + '/download',
        )
      ).status(),
      404,
    )
    await client.goto(origin + '/project/' + fixture.project + '/task/' + fixture.task)
    await client.getByText('Reviewed attachment for client 962', { exact: true }).waitFor()
    assert.equal(await client.locator('.comment.agent').count(), 0)
  } finally {
    await clientContext.close()
  }
  await page.goto(origin + '/admin/settings/integrations')
  await page.locator('#integration-tab-agents').click()
  await card.getByRole('button', { name: 'Configure', exact: true }).click()
  await dialog.locator('[data-agent-connection] summary').click()
  let remaining = await dialog.locator('.agent-token-list button').count()
  assert.ok(remaining >= 2)
  while (remaining) {
    await dialog.locator('.agent-token-list button').first().click()
    remaining--
    await page.waitForFunction(
      (count) =>
        document.querySelectorAll('dialog[open] .agent-token-list button').length === count,
      remaining,
    )
  }
  await page.keyboard.press('Escape')
  await page.goto(origin + taskUrl)
  await page.locator('.agent-connection-needed').waitFor()
  assert.ok(
    (await page.locator('.agent-connection-needed').textContent()).includes('Connection required'),
  )
  assert.ok(
    (await page.locator('.agent-execution').textContent()).includes('Last reported execution'),
  )
  assert.equal(
    (await call('/api/admin/tasks/' + fixture.task)).data.agentAssignments[0].state,
    'cancelled',
  )
  const restored = await call('/api/admin/integrations/agents/' + agentId + '/tokens', 'POST', {
    scopes: [
      'agent:profile',
      'agent:tasks:read',
      'agent:tasks:report',
      'agent:comments:create',
      'agent:attachments:create',
    ],
  })
  replaceAgentToken(restored.secret)
  return 'v0.9.8 UI permissions/key expiry/one-time secret; old token unchanged; parent/scopes/foreign-project denial; concurrent immediate read/write revocation; private client API/files; explicit human text/PR/file publication with separate file copies'
}
