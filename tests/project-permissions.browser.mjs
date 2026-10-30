import assert from 'node:assert/strict'
import { defaultProjectPermissions } from '../src/services/projectPermissions.js'
import { projectMenu } from '../src/services/adminMenu.js'
const keys = Object.keys(defaultProjectPermissions)
const none = Object.fromEntries(keys.map((key) => [key, false]))

export async function exercisePermissions({
  owner,
  dev,
  firstAssigned,
  secondAssigned,
  teamRoot,
  go,
  call,
  results,
}) {
  const root = `/api/admin/projects/${firstAssigned}`
  const pageRoot = `/admin/projects/${firstAssigned}`
  const team = (await call(owner.page, teamRoot)).json
  const member = team.members.find((member) => member.user.email === 'invited@example.test')
  assert.ok(member)
  const save = async (permissions, projects = [firstAssigned, secondAssigned]) => {
    assert.equal(
      (
        await call(
          owner.page,
          teamRoot + '/members/' + member.id,
          { projects, projectPermissions: { [firstAssigned]: permissions } },
          'PATCH',
        )
      ).status,
      200,
    )
  }
  assert.deepEqual((await call(dev.page, root)).json.data.permissions, defaultProjectPermissions)
  assert.equal(
    (await call(dev.page, '/api/admin/projects', { title: 'Forbidden developer creation' })).status,
    403,
  )
  await go(dev.page, pageRoot)
  await dev.page.waitForURL('**/brief')
  await dev.page.locator('.admin-sidebar .project-panel-nav').waitFor()
  assert.equal(await dev.page.locator('.admin-sidebar .project-panel-nav a').count(), 3)
  assert.equal(await dev.page.locator('.admin-sidebar .project-trash-link').count(), 0)
  for (const section of ['clients', 'settings', 'notifications', 'export', 'team']) {
    await go(dev.page, pageRoot + '/' + section)
    await dev.page.waitForURL('**/forbidden')
    await dev.page.locator('.page-empty a[href$="/brief"]').waitFor()
  }
  for (const key of keys) {
    await save({ ...none, [key]: true })
    const item = projectMenu.find((item) => item.permission === key)
    await go(dev.page, pageRoot + '/' + (item?.section || 'brief'))
    await dev.page.locator('.admin-sidebar .project-panel-nav').waitFor()
    assert.equal(new URL(dev.page.url()).pathname, pageRoot + '/' + (item?.section || 'brief'))
    assert.equal(
      await dev.page.locator('.admin-sidebar .project-panel-nav a').count(),
      item ? 3 : 2,
    )
    assert.equal(
      await dev.page.locator('.admin-sidebar .project-trash-link').count(),
      key === 'manage_trash' ? 1 : 0,
    )
    const other = (await call(dev.page, `/api/admin/projects/${secondAssigned}`)).json.data
    assert.deepEqual(other.permissions, defaultProjectPermissions)
    if (key === 'manage_project_settings') {
      const original = (await call(dev.page, root)).json.data.name
      await dev.page.locator('#project-title').fill('Revoked changes must not persist')
      await save(none)
      const response = dev.page.waitForResponse(
        (response) => response.request().method() === 'PATCH' && response.url().includes(root),
      )
      await dev.page.locator('form button[type=submit]').click()
      assert.equal((await response).status(), 403)
      await dev.page
        .getByText(
          'Попросите владельца пространства открыть доступ к этому разделу. Вы можете продолжить работу с идеями.',
          { exact: true },
        )
        .waitFor()
      assert.equal((await call(dev.page, root)).json.data.name, original)
    }
  }
  await save(none)
  for (const path of ['/clients', '/notifications', '/trash'])
    assert.equal((await call(dev.page, root + path)).status, 403)
  await go(dev.page, pageRoot + '/brief/task/new')
  await dev.page.locator('#idea-location option').last().waitFor({ state: 'attached' })
  await dev.page.locator('#idea-title').fill('Ordinary idea without administration rights')
  await dev.page.locator('#idea-location').selectOption({ index: 1 })
  await dev.page.locator('#idea-description').fill('Developer can still create and discuss ideas.')
  await dev.page.locator('form button[type=submit]').click()
  await dev.page.waitForURL('**/brief')
  await dev.page
    .getByRole('heading', { name: 'Ordinary idea without administration rights', exact: true })
    .waitFor()
  assert.equal((await call(dev.page, root + '/tasks')).status, 200)
  assert.equal(
    (
      await call(
        owner.page,
        teamRoot + '/members/' + member.id,
        { projects: [firstAssigned, secondAssigned], canCreateProjects: true },
        'PATCH',
      )
    ).status,
    200,
  )
  await go(dev.page, '/admin/projects/new')
  await dev.page.locator('#project-title').fill('Developer own project')
  await dev.page.locator('form button[type=submit]').click()
  await dev.page.waitForURL('**/brief')
  const createdId = new URL(dev.page.url()).pathname.split('/')[3]
  assert.ok(![firstAssigned, secondAssigned].includes(createdId))
  assert.deepEqual(
    (await call(dev.page, `/api/admin/projects/${createdId}`)).json.data.permissions,
    defaultProjectPermissions,
  )
  assert.equal((await call(dev.page, `/api/admin/projects/${createdId}/team`)).status, 403)
  await go(dev.page, '/admin/projects/new')
  await dev.page.locator('#project-title').waitFor()
  assert.equal(
    (
      await call(
        owner.page,
        teamRoot + '/members/' + member.id,
        { projects: [firstAssigned, secondAssigned, createdId], canCreateProjects: false },
        'PATCH',
      )
    ).status,
    200,
  )
  await dev.page.locator('#project-title').fill('Revoked creation')
  const deniedCreation = dev.page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' && response.url().endsWith('/api/admin/projects'),
  )
  await dev.page.locator('form button[type=submit]').click()
  assert.equal((await deniedCreation).status(), 403)
  results.push(
    'Workspace creation capability: off by default; owner can grant; developer creates an assigned project with safe defaults; no team management; revocation blocks an already-open form',
  )
  await save(defaultProjectPermissions, [firstAssigned])
  assert.equal((await call(dev.page, `/api/admin/projects/${secondAssigned}`)).status, 404)
  assert.equal((await call(dev.page, '/api/admin/projects')).json.data.length, 1)
  await go(dev.page, '/admin/account')
  await dev.page.locator('#account-name').waitFor()
  results.push(
    'Project permissions: safe invitation defaults; every ability independent; direct URL/API denial; revoked settings rejected on already-open page; different rights in two projects; membership revocation; ordinary ideas and personal account retained',
  )
}

export async function permissionVisual({
  page,
  fixture,
  teamPage,
  teamRoot,
  call,
  go,
  fits,
  shot,
  label,
  results,
  lang,
}) {
  const state = (await call(page, teamRoot)).json
  const member = state.members.find((member) => member.user.email === 'maria@example.test')
  assert.equal(
    (
      await call(
        page,
        teamRoot + '/members/' + member.id,
        {
          projects: [fixture.first, fixture.second],
          projectPermissions: {
            [fixture.first]: defaultProjectPermissions,
            [fixture.second]: defaultProjectPermissions,
          },
        },
        'PATCH',
      )
    ).status,
    200,
  )
  await go(page, teamPage)
  const row = page.locator('.team-member').filter({ hasText: 'maria@example.test' })
  await row.locator('.administrator-manage').click()
  const form = row.locator('.team-edit'),
    selector = form.locator('select')
  await selector.selectOption(fixture.first)
  await form.locator('.member-creation-permission').getByRole('switch').check()
  assert.equal(await form.locator('.member-permissions').getByRole('switch').count(), 6)
  await form.locator('.member-permissions').getByRole('switch').nth(1).check()
  await selector.selectOption(fixture.second)
  await form.locator('.member-permissions').getByRole('switch').nth(2).check()
  await selector.selectOption(fixture.first)
  assert.equal(
    await form.locator('.member-permissions').getByRole('switch').nth(1).isChecked(),
    true,
  )
  assert.equal(
    await form.locator('.member-permissions').getByRole('switch').nth(2).isChecked(),
    false,
  )
  await fits(page)
  await page.evaluate(() => {
    document.activeElement?.blur()
    window.scrollTo(0, 0)
  })
  await page.evaluate(
    () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
  )
  await shot(page, 'permissions-' + label)
  await form.locator('button[type=submit]').click()
  await page
    .getByRole('status')
    .filter({ hasText: lang === 'ru' ? 'Доступ обновлён' : 'Access updated' })
    .waitFor()
  const saved = (await call(page, teamRoot)).json.members.find((item) => item.id === member.id)
  assert.equal(saved.canCreateProjects, true)
  assert.equal(saved.projectPermissions[fixture.first].manage_clients, true)
  assert.equal(saved.projectPermissions[fixture.first].manage_project_settings, false)
  assert.equal(saved.projectPermissions[fixture.second].manage_clients, false)
  assert.equal(saved.projectPermissions[fixture.second].manage_project_settings, true)
  await row.locator('.administrator-manage').click()
  await selector.selectOption(fixture.first)
  await form.locator('.member-permissions > button').click()
  assert.deepEqual(
    await form
      .locator('.member-permissions')
      .getByRole('switch')
      .evaluateAll((nodes) => nodes.map((node) => node.checked)),
    Object.values(defaultProjectPermissions),
  )
  const resetResponse = page.waitForResponse(
    (response) =>
      response.request().method() === 'PATCH' &&
      response.url().includes('/administrators/members/'),
  )
  await form.locator('button[type=submit]').click()
  assert.equal((await resetResponse).status(), 200)
  const reset = (await call(page, teamRoot)).json.members.find((item) => item.id === member.id)
  assert.deepEqual(reset.projectPermissions[fixture.first], defaultProjectPermissions)
  assert.equal(reset.projectPermissions[fixture.second].manage_project_settings, true)
  results.push(
    `Permissions visual ${label}: per-project selector, six accessible switches, independent save/reset, no overflow`,
  )
}
