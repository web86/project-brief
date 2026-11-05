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
  const stale = (resetPlatformBudget = false) =>
    php([
      '-r',
      String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'brief-platform-plans-')) exit(90);
$user = App\Models\User::where('email', $argv[1])->firstOrFail();
if ($argv[2] === 'fresh-budget') {
    Illuminate\Support\Facades\RateLimiter::clear(md5('platformuser:'.$user->id));
}
Illuminate\Support\Facades\DB::table('account_security_challenges')->where('user_id', $user->id)->where('purpose', 'step_up')->update(['expires_at' => now()->subMinute()]);
$user->forceFill(['last_totp_step' => null])->save();
$secret = Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
echo app(PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);
`,
      fixture.admin,
      resetPlatformBudget ? 'fresh-budget' : '',
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
$actor = App\Models\User::where('email', $argv[1])->firstOrFail();
$actor->passkeys()->delete();

`,
      fixture.admin,
    ])
    const manual = JSON.parse(php(['tests/platform-plans-fixture.php', 'manual-ux']))
    await login(context, fixture)
    stale()
    assert.equal((await call(context, '/api/security/status')).status(), 200)
    for (const endpoint of ['overview', 'plan-access'])
      assert.equal((await call(context, '/api/platform/' + endpoint)).status(), 200)
    const denied = await call(context, '/api/platform/plans', 'POST', {})
    assert.equal(denied.status(), 423)
    assert.deepEqual(
      (({ code, mutationNotStarted }) => ({ code, mutationNotStarted }))(await denied.json()),
      { code: 'security_step_up_required', mutationNotStarted: true },
    )

    for (const [route, marker] of [...markers, markers.at(-1)]) {
      const url = origin + route
      if (page.url() === url) await page.reload({ waitUntil: 'domcontentloaded' })
      else await page.goto(url, { waitUntil: 'domcontentloaded' })
      await page.locator(marker).first().waitFor()
      await noFallback()
      assert.equal(page.url(), url)
      assert.equal(await dialog.count(), 0)
    }
    const plans = page.locator('.platform-desktop-sidebar a[href="/platform/plans"]').first()
    const catalog = waitForCatalog()
    await plans.click()
    assert.equal(await (await catalog).finished(), null)
    assert.equal(await dialog.count(), 0)

    const draftAction = async (code) => {
      const card = page
        .locator('.plans-card')
        .filter({ has: page.locator('code').getByText(code, { exact: true }) })
      await card.locator('summary').click()
      await card.getByRole('button', { name: 'Create a new draft' }).click({ noWaitAfter: true })
    }
    const otp = stale(true)
    await draftAction('studio')
    await dialog.locator('#security-code').fill(otp)
    await dialog.getByRole('button', { name: 'Confirm identity', exact: true }).click()
    await page.locator('.plans-editor').first().waitFor()
    await noFallback()

    const ownerContext = await browser.newContext({
      ignoreHTTPSErrors: true,
      viewport: { width: 390, height: 844 },
      colorScheme: 'dark',
    })
    try {
      await ownerContext.addInitScript(() => {
        if (!localStorage.getItem('projectbrief.locale'))
          localStorage.setItem('projectbrief.locale', 'en')
        if (!localStorage.getItem('projectbrief.theme'))
          localStorage.setItem('projectbrief.theme', 'system')
      })
      await login(ownerContext, { ...fixture, owner: manual.owner }, true)
      const ownerPage = await ownerContext.newPage()
      ownerPage.on('pageerror', (error) => errors.push(error.message))
      const ownerPath = '/admin/settings/subscription'
      const ownerCalls = []
      ownerPage.on('request', (request) => {
        if (request.url().includes('/api/admin/resource-selection')) ownerCalls.push(request.url())
      })
      await ownerPage.goto(origin + ownerPath)
      const current = ownerPage.locator('.subscription-current')
      await current.getByRole('heading', { name: 'Studio', exact: true }).waitFor()
      await current
        .getByText('Active members: 2 · Reserved by invitations: 8', { exact: true })
        .waitFor()
      assert.equal(
        (await (await call(ownerContext, '/api/security/status')).json()).stepUpMinutes,
        5,
      )
      assert.equal(ownerCalls.length, 1)
      assert.equal(await ownerPage.locator('dialog[open]').count(), 0)
      assert.ok(
        await ownerPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      )

      await ownerPage.route(
        '**/api/admin/resource-selection/summary',
        (route) =>
          route.fulfill({
            status: 429,
            headers: { 'Retry-After': '2' },
            json: { code: 'rate_limited' },
          }),
        { times: 1 },
      )
      await ownerPage.getByRole('button', { name: 'Refresh', exact: true }).click()
      await ownerPage.getByText(/Try again in [12] s/).waitFor()
      assert.ok(await current.getByRole('heading', { name: 'Studio', exact: true }).isVisible())
      assert.ok(
        await ownerPage.getByRole('button', { name: 'Try again', exact: true }).isDisabled(),
      )
      await ownerPage.getByRole('button', { name: 'Try again', exact: true }).click()
      await ownerPage.waitForFunction(() => !document.querySelector('.subscription-load-error'))
      assert.equal(ownerCalls.length, 3)
      assert.ok(ownerCalls.every((url) => url.endsWith('/summary')))
      for (const [locale, theme] of [
        ['ru', 'dark'],
        ['tr', 'light'],
        ['zh-CN', 'system'],
      ]) {
        await ownerPage.evaluate(
          ([locale, theme]) => {
            localStorage.setItem('projectbrief.locale', locale)
            localStorage.setItem('projectbrief.theme', theme)
          },
          [locale, theme],
        )
        await ownerPage.reload({ waitUntil: 'domcontentloaded' })
        await current.getByRole('heading', { name: 'Studio', exact: true }).waitFor()
        assert.equal(await ownerPage.locator('html').getAttribute('lang'), locale)
        assert.equal(
          await ownerPage.locator('html').getAttribute('data-theme'),
          theme === 'system' ? 'dark' : theme,
        )
        const overflow = await ownerPage.evaluate(() => {
          if (document.documentElement.scrollWidth <= innerWidth + 1) return null
          const elements = Array.from(document.querySelectorAll('body *'))
            .map((element) => {
              const box = element.getBoundingClientRect()
              return {
                element: element.tagName,
                classes: element.getAttribute('class'),
                right: box.right,
                left: box.left,
                width: box.width,
                scrollWidth: element.scrollWidth,
                clientWidth: element.clientWidth,
                overflowX: getComputedStyle(element).overflowX,
                text:
                  element.childElementCount === 0 ? element.textContent?.slice(0, 90) : undefined,
              }
            })
            .filter(
              (row) =>
                row.width &&
                (row.right > innerWidth + 1 ||
                  (row.overflowX === 'visible' &&
                    row.left + row.scrollWidth > innerWidth + 1 &&
                    row.scrollWidth > row.clientWidth)),
            )
          return {
            viewport: innerWidth,
            document: document.documentElement.scrollWidth,
            elements: elements.slice(-16),
          }
        })
        assert.equal(overflow, null, locale + '/' + theme + ' ' + JSON.stringify(overflow))
      }
    } finally {
      await ownerContext.close()
    }

    await page.goto(origin + '/platform?section=workspaces', { waitUntil: 'domcontentloaded' })
    await page.getByRole('link', { name: 'Open workspace ' + manual.name, exact: true }).click()
    await page.locator('#workspace-details').getByText(manual.name).waitFor()
    await page.locator('.workspace-tabs').getByRole('link', { name: 'Subscription' }).click()
    const deepLink =
      origin + '/platform?section=workspaces&workspace=' + manual.workspace + '&view=subscription'
    assert.equal(page.url(), deepLink)
    await page.reload({ waitUntil: 'domcontentloaded' })
    await page
      .locator('.subscription-current')
      .getByRole('heading', { name: 'Studio', exact: true })
      .waitFor()
    await page.route(
      '**/api/platform/workspaces/*?view=resources',
      (route) =>
        route.fulfill({
          status: 429,
          headers: { 'Retry-After': '2' },
          json: { code: 'rate_limited' },
        }),
      { times: 1 },
    )
    await page.locator('.workspace-tabs').getByRole('link', { name: 'Resources' }).click()
    await page.getByText(/Try again in [12] s/).waitFor()
    await page.locator('.workspace-tabs').getByRole('link', { name: 'Diagnostics' }).click()
    await page.getByRole('button', { name: 'Try again', exact: true }).click()
    await page.locator('.workspace-diagnostics').waitFor()
    await page.locator('.workspace-tabs').getByRole('link', { name: 'Subscription' }).click()
    await page.getByRole('button', { name: 'Change plan', exact: true }).click()
    const wizard = page.locator('dialog[aria-labelledby="subscription-change-title"]')
    for (const plan of ['Basic', 'Freelancer', 'Studio'])
      assert.ok(
        await wizard.getByRole('button', { name: 'Choose ' + plan, exact: true }).isEnabled(),
      )
    await wizard.getByRole('button', { name: 'Choose Studio', exact: true }).click()
    await wizard.getByText('The current resources fit the plan limits.').waitFor()
    assert.equal(await wizard.locator('.wizard-resources').count(), 0)
    await wizard.getByRole('button', { name: 'Back', exact: true }).click()
    await wizard.getByRole('button', { name: 'Choose Freelancer', exact: true }).click()
    await wizard.getByText(/12 \/ 10/).waitFor()
    assert.ok(await wizard.getByRole('checkbox', { name: 'Design project 01' }).isChecked())
    assert.ok(await wizard.getByRole('checkbox', { name: 'Design project 12' }).isDisabled())
    await wizard.getByRole('checkbox', { name: 'Design project 10' }).uncheck()
    await wizard.getByRole('checkbox', { name: 'Design project 12' }).check()

    const previewOtp = stale(true)
    await wizard.getByRole('button', { name: 'Review assignment', exact: true }).click()
    await dialog.getByText('Confirm your identity. Confirmation lasts for 10 minutes.').waitFor()
    await dialog.locator('#security-code').fill(previewOtp)
    await dialog.getByRole('button', { name: 'Confirm identity', exact: true }).click()
    await wizard
      .getByText('This is a preview. The assignment takes effect when you confirm it.')
      .waitFor()
    const platformSummary = '/api/platform/workspaces/' + manual.workspace + '?view=subscription'
    assert.equal((await (await call(context, platformSummary)).json()).subscription.phase, 'trial')

    await wizard
      .getByLabel('Last service day (UTC)')
      .fill(new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10))
    assert.ok(await wizard.getByRole('button', { name: 'Assign plan', exact: true }).isDisabled())
    await wizard.getByLabel(/I explicitly agree/).check()
    await page.route(
      '**/assign-manual',
      (route) =>
        route.fulfill({
          status: 429,
          headers: { 'Retry-After': '2' },
          json: { code: 'rate_limited' },
        }),
      { times: 1 },
    )
    await wizard.getByRole('button', { name: 'Assign plan', exact: true }).click()
    await wizard.getByText(/Try again in [12] s/).waitFor()
    assert.equal((await (await call(context, platformSummary)).json()).subscription.phase, 'trial')
    await wizard.getByRole('button', { name: 'Try again', exact: true }).click()
    await page
      .locator('.subscription-current')
      .getByRole('heading', { name: 'Freelancer', exact: true })
      .waitFor()
    assert.equal(await wizard.count(), 0)
    const resulting = await (await call(context, platformSummary)).json()
    assert.equal(resulting.usage['projects.active.max'].count, 10)
    assert.equal(resulting.usage['agents.active.max'].count, 1)

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
      const confirmation = []
      page.on('response', async (response) => {
        const path = new URL(response.url()).pathname
        if (
          ![
            '/api/platform/plan-access',
            '/api/security/passkey/confirm',
            '/api/security/passkey/confirm/options',
          ].includes(path)
        )
          return
        const status = response.status()
        const payload = status >= 400 ? await response.json().catch(() => ({})) : {}
        confirmation.push({ path, status, code: payload.code })
      })
      // The rapid TOTP/reload scenarios can consume the operator's minute budget.
      stale(true)
      await page.goto(origin + '/platform/plans', { waitUntil: 'domcontentloaded' })
      await draftAction('basic')
      const retry = dialog.getByRole('button', { name: 'Retry with Passkey', exact: true })
      const passkey = dialog.getByRole('button', { name: /^(Confirm with|Retry with) Passkey$/ })
      const alternative = dialog.getByRole('button', { name: 'Another confirmation method' })
      await passkey.waitFor()
      for (const width of [1200, 360]) {
        await page.setViewportSize({ width, height: 800 })
        const [a, b] = await Promise.all([passkey.boundingBox(), alternative.boundingBox()])
        assert.ok(a && b)
        assert.ok(b.x - (a.x + a.width) >= 8 || b.y - (a.y + a.height) >= 8)
        assert.ok(a.x >= 0 && b.x >= 0 && a.x + a.width <= width && b.x + b.width <= width)
      }
      if ((await page.evaluate(() => window.qaPasskeyGestures.length)) === 0) await passkey.click()
      await page.waitForFunction(() => window.qaPasskeyGestures.length === 1)
      await retry.click({ noWaitAfter: true })
      await page
        .locator('.plans-editor')
        .first()
        .waitFor()
        .catch(async (error) => {
          console.log(
            JSON.stringify({
              confirmation,
              gestures: await page.evaluate(() => window.qaPasskeyGestures),
              dialog: await dialog.allTextContents(),
              diagnostics: await page.locator('main code').allTextContents(),
              errors,
            }),
          )
          throw error
        })
      assert.deepEqual(await page.evaluate(() => window.qaPasskeyGestures), [true, true])
      const status = await (await call(context, '/api/security/status')).json()
      assert.equal(status.confirmationMethod, 'passkey_uv')
      await noFallback()
    }

    php([
      '-r',
      String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'brief-platform-plans-')) exit(90);
$user = App\Models\User::where('email', $argv[1])->firstOrFail();
$db = Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id);
foreach ($db->get() as $row) {
    $data = json_decode(base64_decode($row->payload), true, 512, JSON_THROW_ON_ERROR);
    if (isset($data['security'])) {
        $data['security']['started_at'] = now()->subHours(6)->timestamp;
        Illuminate\Support\Facades\DB::table('sessions')->where('id', $row->id)->update(['payload' => base64_encode(json_encode($data, JSON_THROW_ON_ERROR))]);
    }
}
`,
      fixture.admin,
    ])
    await page.goto(origin + '/platform', { waitUntil: 'domcontentloaded' })
    await page.waitForURL('**/admin/login')
    assert.equal((await call(context, '/api/platform/overview')).status(), 401)
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
    return {
      engine,
      packaged,
      totp: true,
      subscription: true,
      retryAfter: true,
      signedPasskeyRetry: engine === 'chromium',
    }
  } finally {
    await context.close()
  }
}
