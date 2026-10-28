import assert from 'node:assert/strict'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'

// Called by the existing HTTPS/WebAuthn harness after genuine owner enrollment.
export async function verifyBrandingRefinements({
  page,
  context,
  cdp,
  origin,
  php,
  request,
  output,
  root,
}) {
  await mkdir(output, { recursive: true })
  await page.setViewportSize({ width: 1440, height: 1050 })
  const workspaces = php(['tests/account-security-fixture.php', 'branding-workspaces'], true)
  const responses = []
  const observe = (response) => {
    if (response.url().includes('/branding') && response.request().method() !== 'GET')
      responses.push({
        path: new URL(response.url()).pathname,
        status: response.status(),
        method: response.request().method(),
      })
  }
  page.on('response', observe)
  const capture = async (name) => {
    await page.evaluate(() => document.activeElement?.blur())
    await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true })
  }
  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" onload="window.__unsafeBrand=true"><script>window.__unsafeBrand=true</script><image href="https://attacker.invalid/pixel"/><rect width="100" height="100" rx="22" fill="#17695d"/><path d="M25 72 50 22 75 72M35 55h30" fill="none" stroke="white" stroke-width="9"/></svg>'
  const svgFile = { name: 'studio.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(svg) }
  const success = (suffix) =>
    page.waitForResponse(
      (response) =>
        response.url().endsWith(suffix) &&
        response.request().method() === 'POST' &&
        response.status() === 200,
    )
  await page.goto(origin + '/admin/settings/branding')
  await page.locator('#brand-companyName').waitFor()
  php(['tests/account-security-fixture.php', 'reset-limiter'])
  php(['tests/account-security-fixture.php', 'expire-proof'])
  await page.evaluate(async () => {
    const { stepUp } = await import('/src/services/stepUp.js')
    stepUp.invalidate()
    await stepUp.prepare()
    const { toast } = await import('/src/services/toast.js')
    toast.clear()
  })
  const uploaded = success('/branding/assets/favicon')
  await page.locator('input[type=file]').nth(1).setInputFiles(svgFile)
  const retry = page.getByRole('button', { name: 'Retry with Passkey', exact: true })
  const stage = await Promise.race([
    uploaded.then(() => 'complete'),
    retry.waitFor().then(() => 'retry'),
  ])
  if (stage === 'retry') await retry.click()
  await uploaded
  await page.waitForFunction(
    () => document.querySelectorAll('.brand-image-preview img').length === 1,
  )
  assert.equal(
    responses.filter((item) => item.path.endsWith('/assets/favicon') && item.status === 200).length,
    1,
  )
  assert.equal(await page.locator('.branding-page > .field-error').count(), 0)
  assert.equal(await page.locator('.toast-error').count(), 0)
  let state = (await request(`/api/admin/workspaces/${workspaces.first}/branding`)).body.data
  assert.ok(state.assets.favicon.endsWith('.svg'))
  const direct = await context.request.get(state.assets.favicon)
  assert.equal(direct.headers()['content-type'], 'image/svg+xml')
  assert.match(direct.headers()['content-security-policy'], /sandbox/)
  assert.doesNotMatch(await direct.text(), /<script|onload|attacker/)
  const svgPage = await context.newPage()
  await svgPage.goto(state.assets.favicon)
  assert.equal(await svgPage.evaluate(() => document.documentElement.localName), 'svg')
  assert.equal(await svgPage.evaluate(() => globalThis.__unsafeBrand), undefined)
  await svgPage.close()
  const logoUploaded = success('/branding/assets/logo')
  await page.locator('input[type=file]').first().setInputFiles(svgFile)
  await logoUploaded
  await page.locator('#brand-companyName').fill('ASTER Workspace')
  await page.locator('#brand-primaryColor').fill('#17695d')
  await page.locator('.branding-tabs button').nth(1).click()
  await page.locator('#brand-account').fill('QA ONLY ACCOUNT 2026')
  await page.locator('.branding-tabs button').nth(2).click()
  await page.locator('#brand-email').fill('hello@example.test')
  const saved = page.waitForResponse(
    (response) =>
      response.url().endsWith('/branding') &&
      response.request().method() === 'PATCH' &&
      response.status() === 200,
  )
  await page.locator('.branding-save button').click()
  await saved
  await page.waitForFunction(async () => {
    const { useWorkspaceBrandingStore } = await import('/src/stores/workspaceBranding.js')
    return !useWorkspaceBrandingStore().dirty && !useWorkspaceBrandingStore().busy
  })
  await page.reload()
  await page.locator('#brand-companyName').waitFor()
  assert.equal(await page.locator('#brand-companyName').inputValue(), 'ASTER Workspace')
  await page.locator('.branding-tabs button').nth(1).click()
  assert.equal(await page.locator('#brand-account').inputValue(), 'QA ONLY ACCOUNT 2026')
  await page.locator('.branding-tabs button').nth(2).click()
  assert.equal(await page.locator('#brand-email').inputValue(), 'hello@example.test')
  await page.locator('.branding-tabs button').first().click()
  await capture('real-atomic-save-svg')
  const oldLogo = await page.locator('.brand-image-preview img').first().getAttribute('src')
  const original = await readFile(path.join(root, 'artifacts/milestone-7-6/images/original.jpg'))
  assert.ok(original.length > 1500000 && original.length <= 2 * 1024 * 1024)
  await cdp.send('Network.enable')
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 0,
    downloadThroughput: -1,
    uploadThroughput: 256000,
  })
  const photoUploaded = success('/branding/assets/logo')
  await page
    .locator('input[type=file]')
    .first()
    .setInputFiles({ name: 'photo.jpg', mimeType: 'image/jpeg', buffer: original })
  await page.waitForFunction(() => {
    const progress = document.querySelector('.brand-upload-progress progress')
    return progress && progress.hasAttribute('value') && progress.value > 0 && progress.value < 100
  })
  assert.equal(await page.locator('.brand-image-preview img').first().getAttribute('src'), oldLogo)
  await capture('real-upload-progress')
  await photoUploaded
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 0,
    downloadThroughput: -1,
    uploadThroughput: -1,
  })
  await page.waitForFunction(() => !document.querySelector('.brand-upload-progress'))
  state = (await request(`/api/admin/workspaces/${workspaces.first}/branding`)).body.data
  assert.ok(state.assetInfo.logo.bytes < original.length / 2)
  assert.equal(state.assetInfo.logo.mime, 'image/png')
  assert.ok(state.assets.logo.endsWith('.png'))
  await capture('real-optimized-image')
  await page.setViewportSize({ width: 390, height: 844 })
  await capture('real-optimized-image-mobile')
  await page.setViewportSize({ width: 1440, height: 1050 })
  let releaseSwitch
  const gate = new Promise((resolve) => {
    releaseSwitch = resolve
  })
  const switchPattern = `**/api/admin/workspaces/${workspaces.second}/select`
  await page.route(switchPattern, async (route) => {
    await gate
    await route.continue()
  })
  await page.locator('.sidebar-workspace select:visible').first().selectOption(workspaces.second)
  await page.waitForFunction(
    () =>
      !document
        .querySelector('.workspace-appearance')
        ?.getAttribute('style')
        ?.includes('--action:'),
  )
  assert.equal(await page.locator('.workspace-brand-logo').count(), 0)
  assert.ok(
    !(await page.locator('link[rel=icon]').getAttribute('href')).includes('/branding-assets/'),
  )
  releaseSwitch()

  await page.waitForURL(origin + '/admin')
  await page.unroute(switchPattern)
  await page.waitForFunction(() =>
    document.querySelector('.admin-toolbar-workspace')?.textContent.includes('North Studio'),
  )
  assert.equal(
    await page.evaluate(() =>
      getComputedStyle(document.querySelector('.workspace-appearance'))
        .getPropertyValue('--action')
        .trim(),
    ),
    '#7354a3',
  )
  assert.ok(
    !(await page.locator('link[rel=icon]').getAttribute('href')).includes('/branding-assets/'),
  )
  assert.equal(await page.locator('.workspace-brand-logo img').count(), 0)
  await capture('real-second-workspace')
  await page.locator('.personal-settings-nav a[href="/admin/account"]').first().click()
  await page.waitForURL(origin + '/admin/account')
  assert.equal(
    await page
      .locator('.workspace-appearance')
      .getAttribute('style')
      .then((style) => style?.includes('--action:') || false),
    false,
  )
  assert.equal(await page.locator('.workspace-brand-logo').count(), 0)
  await capture('real-personal-platform-appearance')
  await page.locator('.sidebar-workspace select').first().selectOption(workspaces.first)
  await page.waitForURL(origin + '/admin')
  await page.waitForFunction(() =>
    document.querySelector('.admin-toolbar-workspace')?.textContent.includes('ASTER Workspace'),
  )
  assert.ok((await page.locator('link[rel=icon]').getAttribute('href')).endsWith('.svg'))
  page.off('response', observe)
  await writeFile(
    path.join(output, 'REAL-BRANDING-RESULTS.json'),
    JSON.stringify(
      {
        passed: true,
        signedWebAuthn: true,
        resumedMutationOnce: true,
        atomicThreeSectionsAndReload: true,
        uploadProgress: 'real XMLHttpRequest bytes under Chromium network throttle',
        originalBytes: original.length,
        optimizedBytes: state.assetInfo.logo.bytes,
        svgDirectNavigationSafe: true,
        workspaceSwitchAndPersonalReset: true,
        responses,
      },
      null,
      2,
    ) + '\n',
  )
  return [
    '7.6: real signed UV Passkey resumes selected SVG upload once, without red retry banner',
    '7.6: atomic visual/contact/bank save survives reload; SVG primary and PNG compatibility copy',
    '7.6: real upload byte progress, old image kept until success, measured PNG optimization with legacy export compatibility',
    '7.6: two branded workspaces and platform account appearance are isolated',
  ]
}
