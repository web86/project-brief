import assert from 'node:assert/strict'

export async function canaryManagementScenario({
  browser,
  origin,
  fixture,
  login,
  call,
  php,
  readMail,
  configure,
}) {
  const path = '/api/platform/registration-canary/'
  const operator = await browser.newContext({ ignoreHTTPSErrors: true })
  const owner = await browser.newContext({ ignoreHTTPSErrors: true })
  for (const context of [operator, owner]) {
    await context.addInitScript(() => {
      if (!localStorage.getItem('projectbrief.locale'))
        localStorage.setItem('projectbrief.locale', 'en')
    })
  }
  const setup = (operation) =>
    php([
      '-r',
      String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'brief-platform-plans-')) exit(90);
$user = App\Models\User::where('email', $argv[2])->firstOrFail();
switch ($argv[1]) {
    case 'prepare':
        $user->passkeys()->delete();
        foreach (['basic','studio'] as $code) {
            $plan = App\Models\Plans\PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
            app(App\Services\Plans\PlanCatalog::class)->publish($user, $plan->id, $plan->revision, 'fixture-'.$code);
        }
        $policy = App\Models\Plans\PlatformPolicyVersion::where('state','draft')->firstOrFail();
        $published = app(App\Services\Plans\PlatformPolicyCatalog::class)->publish($user, $policy->id, $policy->revision, 'fixture-policy');
        Illuminate\Support\Facades\DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $published->id]);
        break;
    case 'expire':
        Illuminate\Support\Facades\DB::table('account_security_challenges')->where('user_id', $user->id)->where('purpose','step_up')->update(['expires_at' => now()->subMinute()]);
        $secret = Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        echo app(PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);
        break;
    case 'conflict':
        Illuminate\Support\Facades\DB::table('plan_settings')->where('id',1)->increment('revision');
        break;
    case 'budget':
        Illuminate\Support\Facades\RateLimiter::clear(md5('platformuser:'.$user->id));
        break;
    case 'counts':
        $db = Illuminate\Support\Facades\DB::class;
        echo json_encode([
            $db::table('subscription_periods')->where('kind','trial')->count(),
            $db::table('plan_events')->where('action','canary.approve')->count(),
            $db::table('plan_events')->where('action','canary.admit')->count(),
            $db::table('plan_events')->where('action','canary.stop')->count(),
            $db::table('canary_registration_invites')->count(),
        ]);
}
`,
      operation,
      fixture.admin,
    ]).trim()
  const page = await operator.newPage()
  const signup = await owner.newPage()
  const errors = []
  for (const active of [page, signup]) active.on('pageerror', (error) => errors.push(error.name))
  const dialog = page.getByRole('dialog').filter({ has: page.locator('#canary-action-title') })
  const mfa = page.getByRole('dialog').filter({ has: page.locator('#security-step-up-title') })
  const button = (label, scope = page) => scope.getByRole('button', { name: label, exact: true })
  const email = 'browser-canary-owner@example.test'
  const password = 'Browser-registration-only!2026'
  const ownerCard = page.locator('.canary-owners > li').filter({ hasText: email })
  const snapshot = async () => (await (await call(operator, path + 'owners')).json()).data
  const mutation = async (action, trigger) => {
    const response = page.waitForResponse(
      (item) =>
        new URL(item.url()).pathname === path + action &&
        item.request().method() === 'POST' &&
        item.status() !== 423,
    )
    await trigger()
    return response
  }
  const replay = async (action, original) => {
    const request = original.request()
    const headers = await request.allHeaders()
    return operator.request.post(origin + path + action, {
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': (await (await call(operator, '/api/csrf')).json()).token,
        'Idempotency-Key': headers['idempotency-key'],
        ...(headers['if-match'] ? { 'If-Match': headers['if-match'] } : {}),
      },
      data: request.postDataJSON(),
    })
  }
  try {
    await configure(false)
    setup('prepare')
    await login(operator, fixture)
    await page.goto(origin + '/platform/plans/registration')
    await page.getByRole('heading', { name: 'Test launch / Canary' }).waitFor()
    assert.equal((await snapshot()).canary.configurationReady, true)
    await page.getByRole('button', { name: /Invite a test owner/ }).click()
    await button('Cancel', dialog).click()
    assert.deepEqual(JSON.parse(setup('counts')), [0, 0, 0, 0, 0])
    await page.getByRole('button', { name: /Invite a test owner/ }).click()
    await dialog.getByLabel('Email', { exact: true }).fill(email)
    const invite = await mutation('invite', () => button('Create invitation', dialog).click())
    assert.equal(invite.status(), 201)
    const link = dialog.getByLabel('One-time registration link')
    await link.waitFor()
    const invitation = await link.inputValue()
    const expires = Date.parse(await dialog.locator('time').getAttribute('datetime'))
    assert.ok(expires - Date.now() >= 1790_000 && expires - Date.now() <= 1801_000)
    const replayInvite = await replay('invite', invite)
    assert.equal(replayInvite.status(), 409)
    assert.equal((await replayInvite.json()).data, undefined)
    await button('Close dialog', dialog).last().click()
    assert.equal(await link.count(), 0)
    const stored = await page.evaluate(() => [
      JSON.stringify(localStorage),
      JSON.stringify(sessionStorage),
    ])
    assert.ok(stored.every((value) => !value.includes('#invite=')))

    await signup.goto(origin + '/register')
    await signup.locator('.registration-closed').waitFor()
    await signup.goto(invitation)
    await signup.locator('#registration-email').fill(email)
    const passwordFields = signup.locator('.registration-form input[type=password]')
    await passwordFields.nth(0).fill(password)
    await passwordFields.nth(1).fill(password)
    await signup.locator('.registration-form button[type=submit]').click()
    await signup.waitForURL('**/admin/verify-email')
    await signup.getByRole('heading', { name: 'Check your email' }).waitFor()
    await button('Refresh').click()
    await ownerCard.getByText('Email confirmation pending').waitFor()
    assert.equal(await button('Activate Studio Trial', ownerCard).count(), 0)
    const mail = await readMail()
    assert.equal(mail.email, email)
    await signup.goto(mail.url)
    await signup.locator('.onboarding-form input[type=email]').fill(email)
    await signup.locator('.onboarding-form input[type=password]').fill(password)
    await button('Sign in', signup).click()
    await signup.waitForURL('**/admin/security')
    await button('Set up authenticator', signup).click()
    const totpField = signup.locator('#totp-confirm')
    const uri = await signup.locator('#totp-uri').inputValue()
    await totpField.fill(
      php(['tests/browser-totp.php', new URL(uri).searchParams.get('secret')]).trim(),
    )
    await signup.locator('form').filter({ has: totpField }).locator('button[type=submit]').click()
    await signup.locator('.security-codes code').first().waitFor()
    await button('I have saved my recovery codes', signup).click()
    await signup.waitForURL('**/admin/onboarding')
    assert.equal(
      (await (await call(owner, '/api/onboarding/state')).json()).state,
      'ready_for_trial_activation',
    )
    assert.equal((await call(owner, path + 'owners')).status(), 403)
    assert.equal((await operator.request.post(origin + path + 'stop', { data: {} })).status(), 419)

    await button('Refresh').click()
    await ownerCard.waitFor()
    const claim = (await snapshot()).management.owners.find((item) => item.email === email)
    assert.equal(claim.state, 'pending')
    assert.equal(claim.eligible, true)
    assert.equal(await button('Activate Studio Trial', ownerCard).isDisabled(), true)
    assert.deepEqual(JSON.parse(setup('counts')), [0, 0, 0, 0, 1])

    setup('budget')
    await configure(true)
    await button('Refresh').click()
    await button('Activate Studio Trial', ownerCard).click()
    await dialog.getByText(/Time since verification already counts/).waitFor()
    assert.equal(await dialog.locator('input').count(), 0)
    const code = setup('expire')
    setup('conflict')
    const conflict = await mutation('admit', async () => {
      await button('Activate Studio Trial', dialog).click()
      await mfa.locator('#security-code').fill(code)
      await button('Confirm identity', mfa).click()
    })
    assert.equal(conflict.status(), 409)
    await dialog.getByText(/Settings changed/).waitFor()
    assert.deepEqual(JSON.parse(setup('counts')), [0, 0, 0, 0, 1])
    await button('Refresh', dialog).click()
    await button('Activate Studio Trial', ownerCard).click()
    const admitted = await mutation('admit', () => button('Activate Studio Trial', dialog).click())
    assert.equal(admitted.status(), 200)
    assert.deepEqual(admitted.request().postDataJSON(), { claimId: claim.claimId })
    assert.equal((await replay('admit', admitted)).status(), 200)
    await ownerCard.getByText('Trial assigned', { exact: true }).first().waitFor()
    assert.equal(await button('Activate Studio Trial', ownerCard).count(), 0)
    const admittedClaim = (await snapshot()).management.owners.find(
      (item) => item.claimId === claim.claimId,
    )
    assert.equal(admittedClaim.period.startsAt, claim.verifiedAt)
    assert.equal(Date.parse(admittedClaim.period.endsAt) - Date.parse(claim.verifiedAt), 604800000)

    await button('Stop new activations').click()
    const stopped = await mutation('stop', () => button('Stop new activations', dialog).click())
    assert.equal(stopped.status(), 200)
    await page.getByText('New activations stopped.').waitFor()
    await page.reload()
    await page.getByText('New activations are paused.').waitFor()
    const after = await snapshot()
    assert.equal(after.canary.activationStopped, true)
    assert.equal(after.management.admittedOwners, 1)
    assert.equal(after.publicRegistration, false)
    assert.deepEqual(JSON.parse(setup('counts')), [1, 0, 1, 1, 1])
    await button('Resume new activations').click()
    const resumed = await mutation('resume', () => button('Resume new activations', dialog).click())
    assert.equal(resumed.status(), 200)
    await page.getByText('New activations resumed.').waitFor()
    await configure(false)
    for (const [locale, mode, color, width, heading] of [
      ['ru', 'light', 'light', 1200, 'Тестовый запуск / Canary'],
      ['tr', 'dark', 'dark', 390, 'Test yayını / Canary'],
      ['zh-CN', 'system', 'dark', 390, '测试发布 / Canary'],
    ]) {
      await page.setViewportSize({ width, height: 800 })
      await page.emulateMedia({ colorScheme: color })
      await page.evaluate(
        ({ locale, mode }) => {
          localStorage.setItem('projectbrief.locale', locale)
          localStorage.setItem('projectbrief.theme', mode)
        },
        { locale, mode },
      )
      await page.reload()
      await page.getByRole('heading', { name: heading, exact: true }).waitFor()
      assert.equal(await page.locator('html').getAttribute('data-theme'), color)
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      )
      await page.locator('.canary-panel').first().locator('header button').click()
      await dialog.waitFor()
      assert.ok((await dialog.boundingBox()).width <= width)
      await page.keyboard.press('Escape')
      await dialog.waitFor({ state: 'hidden' })
    }
    assert.deepEqual(errors, [])
    return {
      passed: true,
      isolated: true,
      invited: 1,
      trials: 1,
      stopped: 1,
      localeThemeChecks: 3,
    }
  } finally {
    await operator.close()
    await owner.close()
  }
}
