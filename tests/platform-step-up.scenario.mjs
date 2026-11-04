import assert from 'node:assert/strict'

export async function platformStepUpScenario({
  browser,
  engine,
  origin,
  call,
  login,
  fixture,
  php,
  packaged,
}) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'en-US' })
  await context.addInitScript(() => localStorage.setItem('projectbrief.locale', 'en'))
  const errors = []
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  const dialog = page
    .locator('dialog[open]')
    .filter({ has: page.locator('#security-step-up-title') })
  const stale = () =>
    php([
      '-r',
      String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'brief-platform-plans-')) exit(90);
$user = App\Models\User::where('email', $argv[1])->firstOrFail();
Illuminate\Support\Facades\DB::table('account_security_challenges')->where('user_id', $user->id)->where('purpose', 'step_up')->update(['expires_at' => now()->subMinute()]);
$user->forceFill(['last_totp_step' => null])->save();
$secret = Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
echo app(PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);
`,
      fixture.admin,
    ]).trim()
  const noFallback = () => page.locator('#bootstrap-fallback').waitFor({ state: 'hidden' })
  const waitForCatalog = () =>
    page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/api/platform/plans' && response.status() === 200,
    )
  const markers = [
    ['/platform/', '.platform-controls'],
    ['/platform/plans/', '.plans-page'],
    ['/platform/plans/registration', '.registration-diagnostics dl'],
  ]
  try {
    php([
      '-r',
      String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'brief-platform-plans-')) exit(90);
App\Models\User::where('email', $argv[1])->firstOrFail()->passkeys()->delete();
`,
      fixture.admin,
    ])
    await login(context, fixture)
    stale()
    assert.equal((await call(context, '/api/security/status')).status(), 200)
    for (const endpoint of ['overview', 'plan-access']) {
      const denied = await call(context, '/api/platform/' + endpoint)
      assert.equal(denied.status(), 423)
      const result = await denied.json()
      assert.equal(result.code, 'security_step_up_required')
      assert.equal(result.mutationNotStarted, true)
    }
    for (const [route, marker] of [...markers, markers.at(-1)]) {
      const code = stale()
      const reads = []
      const securityReads = []
      const record = (response) => {
        if (new URL(response.url()).pathname === '/api/platform/plan-access')
          reads.push(response.status())
        if (new URL(response.url()).pathname === '/api/security/status')
          securityReads.push(response.status())
      }
      page.on('response', record)
      const url = origin + route
      const catalog = marker === '.plans-page' ? waitForCatalog() : null
      if (page.url() === url) await page.reload({ waitUntil: 'domcontentloaded' })
      else await page.goto(url, { waitUntil: 'domcontentloaded' })
      await dialog
        .locator('#security-code')
        .fill(code, { timeout: 12000 })
        .catch(async (error) => {
          console.log(
            JSON.stringify({
              route,
              reads,
              securityReads,
              ui: await page
                .locator('body')
                .innerText()
                .then((text) => text.slice(0, 400)),
              flow: packaged
                ? null
                : await page.evaluate(async () => {
                    const flow = (await import('/src/services/stepUp.js')).stepUp
                    return {
                      waiting: !!flow.request.value,
                      mode: flow.state.value?.mode,
                      busy: flow.state.value?.busy,
                      totp: flow.state.value?.status?.totp,
                      passkeys: flow.state.value?.status?.passkeys?.length,
                      code: flow.state.value?.error?.code,
                      signedIn: flow.state.value?.status?.signedIn,
                    }
                  }),
              path: new URL(page.url()).pathname,
              dialogs: await page.locator('dialog[open] h2').allTextContents(),
              errors,
            }),
          )
          throw error
        })
      await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
      await noFallback()
      await dialog.getByRole('button', { name: 'Confirm identity', exact: true }).click()
      await page.locator(marker).first().waitFor()
      if (catalog) assert.equal(await (await catalog).finished(), null)
      assert.equal(page.url(), url)
      assert.deepEqual(reads, [423, 200])
      page.off('response', record)
    }

    // Verify that a new guard can pause a SPA navigation after the same proof expires.
    const code = stale()
    const plans = page.locator('.platform-desktop-sidebar a[href="/platform/plans"]').first()
    const catalog = waitForCatalog()
    await plans.click({ noWaitAfter: true })
    await dialog.locator('#security-code').fill(code)
    await dialog.getByRole('button', { name: 'Confirm identity', exact: true }).click()
    await page.locator('.plans-page').waitFor()
    assert.equal(await (await catalog).finished(), null)
    await noFallback()

    for (const [status, diagnostic] of [
      [401, '/admin/login'],
      [403, '/admin/unavailable?reason=permission'],
      [423, 'platform_locked'],
      [429, 'platform_rate_limited'],
      [500, 'platform_server_error'],
    ]) {
      let attempts = 0
      const mock = '**/api/platform/plan-access'
      await page.route(mock, async (route) => {
        attempts++
        await route.fulfill({ status, json: { code: 'synthetic_failure' } })
      })
      await page.goto(origin + '/platform/plans/registration', { waitUntil: 'domcontentloaded' })
      if (diagnostic.startsWith('/')) await page.waitForURL(origin + diagnostic)
      else await page.locator('main code').getByText(diagnostic, { exact: true }).waitFor()
      await page.locator('#bootstrap-splash').waitFor({ state: 'detached' })
      assert.equal(await dialog.count(), 0)
      assert.equal(attempts, 1)
      await page.unroute(mock)
    }

    if (engine === 'chromium') {
      await page.goto(origin + '/admin/account/security')
      const cdp = await context.newCDPSession(page)
      await cdp.send('WebAuthn.enable')
      await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
          protocol: 'ctap2',
          transport: 'internal',
          hasResidentKey: true,
          hasUserVerification: true,
          isUserVerified: true,
          automaticPresenceSimulation: true,
        },
      })
      await page.getByRole('button', { name: 'Add Passkey', exact: true }).click()
      await page.locator('#passkey-name').fill('Synthetic platform key')
      await page
        .locator('.passkeys-section form')
        .filter({ has: page.locator('#passkey-name') })
        .locator('button[type=submit]')
        .click()
      await page.getByText('Synthetic platform key', { exact: true }).waitFor()

      await context.addInitScript(() => {
        const get = navigator.credentials.get.bind(navigator.credentials)
        window.qaPasskeyGestures = []
        navigator.credentials.get = (options) => {
          window.qaPasskeyGestures.push(navigator.userActivation.isActive)
          if (window.qaPasskeyGestures.length === 1)
            return Promise.reject(new DOMException('Synthetic cancellation', 'NotAllowedError'))
          return get(options)
        }
      })
      stale()
      await page.goto(origin + '/platform/plans/registration', { waitUntil: 'domcontentloaded' })
      const retry = dialog.getByRole('button', { name: 'Retry with Passkey', exact: true })
      const passkey = dialog.getByRole('button', { name: 'Confirm with Passkey', exact: true })
      const alternative = dialog.getByRole('button', { name: 'Another confirmation method' })
      await passkey.waitFor()
      for (const width of [1200, 360]) {
        await page.setViewportSize({ width, height: 800 })
        const [a, b] = await Promise.all([passkey.boundingBox(), alternative.boundingBox()])
        assert.ok(a && b)
        assert.ok(b.x - (a.x + a.width) >= 8 || b.y - (a.y + a.height) >= 8)
        assert.ok(a.x >= 0 && b.x >= 0 && a.x + a.width <= width && b.x + b.width <= width)
      }
      await passkey.click()
      await page.waitForFunction(() => window.qaPasskeyGestures.length === 1)
      await retry.click({ noWaitAfter: true })
      await page.locator('.registration-diagnostics dl').first().waitFor()
      assert.deepEqual(await page.evaluate(() => window.qaPasskeyGestures), [true, true])
      const status = await (await call(context, '/api/security/status')).json()
      assert.equal(status.confirmationMethod, 'passkey_uv')
      await noFallback()
    }

    const guest = await browser.newContext({ ignoreHTTPSErrors: true })
    try {
      assert.equal((await call(guest, '/api/platform/plan-access')).status(), 401)
      const guestPage = await guest.newPage()
      const response = await guestPage.goto(origin + '/platform/plans/registration')
      if (packaged) assert.equal(response.status(), 401)
      else await guestPage.waitForURL('**/admin/login')
      await login(guest, { ...fixture, owner: fixture.legacy }, true)
      assert.equal((await call(guest, '/api/platform/plan-access')).status(), 403)
      const forbidden = await guestPage.goto(origin + '/platform/plans/registration')
      if (packaged) assert.equal(forbidden.status(), 403)
      else await guestPage.waitForURL('**/admin/unavailable?reason=permission')
    } finally {
      await guest.close()
    }
    assert.deepEqual(errors, [])
    return { engine, packaged, totp: true, signedPasskeyRetry: engine === 'chromium' }
  } finally {
    await context.close()
  }
}
