<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\StoreAccountPasskey;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WebAuthnDevice;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['fortify.passkeys.allowed_origins' => ['http://localhost'], 'fortify.passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost'], 'passkeys.relying_party_id' => 'localhost']);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $response;
    }

    private function owner(): User
    {
        return User::factory()->owner(null, false)->create(['password' => 'Owner-password!']);
    }

    private function password(User $user): TestResponse
    {
        return $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Owner-password!']);
    }

    private function enrollTotp(User $user): array
    {
        $this->password($user)->assertOk()->assertJsonPath('state', 'enrollment_required');
        $this->postJson('/api/security/totp/setup')->assertOk()->assertJsonStructure(['svg', 'uri']);
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->pending_two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $codes = $this->postJson('/api/security/totp/confirm', ['code' => $code])->assertOk()->json('codes');
        $this->postJson('/api/security/recovery-codes/acknowledge')->assertOk();

        return [$secret, $codes, $code];
    }

    private function register(?WebAuthnDevice $device = null): WebAuthnDevice
    {
        $device ??= new WebAuthnDevice;
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->postJson('/api/security/passkey/register', ['name' => 'Device', 'credential' => $device->registration($options)])->assertOk();

        return $device;
    }

    public function test_enrollment_is_restricted_on_every_business_surface_and_survives_refresh(): void
    {
        $user = $this->owner();
        $this->password($user)->assertOk()->assertExactJson(['state' => 'enrollment_required']);
        foreach (['/api/admin/me', '/api/admin/projects', '/api/admin/projects/trash', '/api/admin/workspaces', '/api/admin/account', '/api/admin/integrations'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'account_security_required');
        }
        foreach (['/api/admin/projects', '/api/admin/projects/fake/export', '/api/admin/projects/fake/team/invitations', '/api/admin/projects/fake/clients', '/api/admin/workspaces/fake/select', '/api/admin/integrations/tokens', '/api/invitations/accept'] as $path) {
            $this->postJson($path)->assertForbidden();
        }
        $this->getJson('/api/session')->assertJsonPath('authenticated', false)->assertJsonPath('state', 'enrollment_required');
        $this->getJson('/api/security/status')->assertJsonPath('state', 'enrollment_required');
        $this->postJson('/api/admin/logout')->assertOk();
    }

    public function test_totp_requires_confirmation_and_recovery_acknowledgement_before_passkey(): void
    {
        $user = $this->owner();
        $this->password($user)->assertOk();
        $this->postJson('/api/security/totp/setup')->assertOk();
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->postJson('/api/security/totp/confirm', ['code' => 'bad'])->assertUnprocessable();
        $this->getJson('/api/security/passkey/register/options')->assertForbidden();
    }

    public function test_real_passkey_registration_unlocks_owner_and_password_requires_second_factor(): void
    {
        $user = $this->owner();
        [$secret, $codes, $enrollmentCode] = $this->enrollTotp($user);
        $this->getJson('/api/admin/projects')->assertForbidden();
        $device = $this->register();
        $this->getJson('/api/admin/me')->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->password($user)->assertJsonPath('state', 'pending_2fa');
        $this->assertGuest();
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/security/challenge', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/security/challenge', ['code' => $enrollmentCode])->assertUnprocessable()->assertJsonPath('code', 'SEC_FACTOR_INVALID');
        $this->postJson('/api/security/challenge', ['recovery_code' => $codes[0]])->assertOk()->assertJsonPath('state', 'authenticated');
        $this->getJson('/api/admin/projects')->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->password($user)->assertOk();
        $this->postJson('/api/security/challenge', ['recovery_code' => $codes[0]])->assertUnprocessable();
    }

    public function test_production_origin_enrollment_keeps_mfa_proof_without_reusing_totp(): void
    {
        config(['fortify.passkeys.allowed_origins' => ['https://brief.web86.site'], 'fortify.passkeys.relying_party_id' => 'brief.web86.site', 'passkeys.allowed_origins' => ['https://brief.web86.site'], 'passkeys.relying_party_id' => 'brief.web86.site']);
        $user = $this->owner();
        [, , $code] = $this->enrollTotp($user);
        $step = $user->fresh()->last_totp_step;
        $this->getJson('/api/security/status')->assertJsonPath('fresh', true)->assertJsonPath('recoverySaved', true);
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $this->assertSame('brief.web86.site', $options['rp']['id']);
        $device = new WebAuthnDevice;
        $this->postJson('/api/security/passkey/register', ['name' => 'Mac', 'credential' => $device->registration($options, 'https://brief.web86.site')])->assertOk()->assertJsonPath('state', 'authenticated');
        $this->assertSame($step, $user->fresh()->last_totp_step);
        $this->postJson('/api/security/confirm', ['current_password' => 'Owner-password!', 'code' => $code])->assertUnprocessable();
    }

    public static function registrationFailures(): array
    {
        return [['origin', 'PK_ORIGIN_MISMATCH'], ['rp', 'PK_RP_MISMATCH'], ['uv', 'PK_UV_REQUIRED'], ['challenge', 'PK_CHALLENGE_MISMATCH'], ['expired', 'PK_CHALLENGE_EXPIRED'], ['payload', 'PK_PAYLOAD_INVALID']];
    }

    #[DataProvider('registrationFailures')]
    public function test_registration_diagnostics_identify_the_rejecting_gate_and_require_new_challenge(string $failure, string $expected): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $device = new WebAuthnDevice;
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        if ($failure === 'challenge') {
            $options['challenge'] = $device->b64(random_bytes(32));
        }
        if ($failure === 'rp') {
            $options['rp']['id'] = 'evil.test';
        }
        if ($failure === 'expired') {
            DB::table('account_security_challenges')->update(['expires_at' => now()->subSecond()]);
        }
        $credential = $device->registration($options, origin: $failure === 'origin' ? 'https://evil.test' : 'http://localhost', uv: $failure !== 'uv');
        if ($failure === 'payload') {
            unset($credential['response']['attestationObject']);
        }
        $response = $this->postJson('/api/security/passkey/register', ['name' => 'Mac', 'credential' => $credential]);
        $response->assertStatus($failure === 'payload' ? 400 : 422)->assertJsonPath('code', $expected)->assertJsonStructure(['diagnosticId', 'stage']);
        $this->assertStringNotContainsString($options['challenge'], $response->getContent());
        $this->assertDatabaseCount('passkeys', 0);
        $this->getJson('/api/security/status')->assertJsonPath('state', 'enrollment_required')->assertJsonPath('fresh', true);
        $this->postJson('/api/security/passkey/register', ['name' => 'Replay', 'credential' => $credential])->assertUnprocessable()->assertJsonPath('code', 'PK_CHALLENGE_MISSING');
        $this->register();
        $this->getJson('/api/security/status')->assertJsonPath('state', 'authenticated');
    }

    public function test_database_failure_is_not_reported_as_totp_failure_and_retry_preserves_secrets(): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $before = $user->fresh();
        $device = new WebAuthnDevice;
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $credential = $device->registration($options);
        Log::spy();
        // Real database error containing a credential binding; it must never reach logs or JSON.
        $this->partialMock(StoreAccountPasskey::class, function ($mock) use ($credential): void {
            $mock->shouldReceive('createPasskey')->once()->andReturnUsing(function () use ($credential): never {
                request()->attributes->set('security.stage', 'persist');
                DB::table('missing_passkey_table')->insert(['credential' => json_encode($credential)]);
                throw new \LogicException('Unreachable');
            });
        });
        $this->postJson('/api/security/passkey/register', ['name' => 'Mac', 'credential' => $credential])->assertStatus(500)->assertJsonPath('code', 'PK_PERSIST_FAILED')->assertJsonPath('stage', 'persist');
        $this->assertDatabaseCount('passkeys', 0);
        $this->assertDatabaseCount('account_security_challenges', 0);
        $after = $user->fresh();
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'last_totp_step', 'auth_session_version', 'recovery_codes_acknowledged_at'] as $field) {
            $this->assertEquals($before->$field, $after->$field);
        }
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($event, $context) use ($credential, $options): bool {
            $encoded = json_encode($context);
            $this->assertSame('account_security_failure', $event);
            $this->assertSame('PK_PERSIST_FAILED', $context['code']);
            $this->assertStringNotContainsString($credential['rawId'], $encoded);
            $this->assertStringNotContainsString($options['challenge'], $encoded);
            $this->assertStringNotContainsString('missing_passkey_table', $encoded);
            $this->assertArrayHasKey('sqlstate', $context);

            return true;
        });
        Log::shouldNotHaveReceived('error');
        $this->app->forgetInstance(StoreAccountPasskey::class);
        $this->register();
    }

    public function test_diagnostics_command_never_exports_application_or_factor_secrets(): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $this->assertSame(0, Artisan::call('account:security-diagnostics'));
        $output = Artisan::output();
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($data['schema']['passkeys']);
        $this->assertFalse($data['origin_matches_expected']);
        foreach ([(string) config('app.key'), $user->fresh()->two_factor_secret, $user->fresh()->two_factor_recovery_codes] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_totp_expiry_replay_and_login_challenge_expiry(): void
    {
        $user = $this->owner();
        [$secret] = $this->enrollTotp($user);
        $this->register();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->password($user)->assertOk();
        $this->travel(90)->seconds();
        $code = app(Google2FA::class)->oathTotp($secret, (int) floor(now()->timestamp / 30));
        $this->postJson('/api/security/challenge', ['code' => $code])->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->password($user)->assertOk();
        $this->postJson('/api/security/challenge', ['code' => $code])->assertUnprocessable();
        $this->travel(301)->seconds();
        $this->postJson('/api/security/challenge', ['code' => $code])->assertUnprocessable();
        $this->assertGuest();
    }

    public static function rejectedAssertions(): array
    {
        return [['uv'], ['origin'], ['rp'], ['challenge'], ['handle'], ['counter'], ['signature']];
    }

    #[DataProvider('rejectedAssertions')]
    public function test_signed_invalid_webauthn_assertions_are_rejected(string $failure): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $device = $this->register();
        $this->postJson('/api/admin/logout')->assertOk();
        $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
        if ($failure === 'counter') {
            $this->postJson('/api/security/passkey/login', ['credential' => $device->assertion($user, $options)])->assertOk();
            $this->postJson('/api/admin/logout')->assertOk();
            $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
        }
        if ($failure === 'challenge') {
            $options['challenge'] = $device->b64(random_bytes(32));
        }
        $assertion = $device->assertion($user, $options, uv: $failure !== 'uv', origin: $failure === 'origin' ? 'https://evil.test' : 'http://localhost', rp: $failure === 'rp' ? 'evil.test' : null, handle: $failure === 'handle' ? 'wrong' : null, count: $failure === 'counter' ? 0 : null);
        if ($failure === 'signature') {
            $assertion['response']['signature'] = $device->b64(random_bytes(70));
        }
        $this->postJson('/api/security/passkey/login', ['credential' => $assertion])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_passkey_uv_login_skips_totp_and_challenge_is_single_use_and_expires(): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $device = $this->register();
        $this->postJson('/api/admin/logout')->assertOk();
        $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
        $assertion = $device->assertion($user, $options);
        $this->postJson('/api/security/passkey/login', ['credential' => $assertion])->assertOk()->assertJsonPath('state', 'authenticated');
        $this->getJson('/api/admin/me')->assertOk();
        $this->postJson('/api/security/passkey/login', ['credential' => $assertion])->assertUnprocessable();
        $this->postJson('/api/admin/logout')->assertOk();
        $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
        $this->travel(121)->seconds();
        $this->postJson('/api/security/passkey/login', ['credential' => $device->assertion($user, $options)])->assertUnprocessable();
    }

    public function test_developer_password_and_optional_passkey_without_totp(): void
    {
        $user = User::factory()->memberOf(Workspace::factory()->create())->create(['password' => 'Owner-password!']);
        $this->password($user)->assertOk()->assertJsonPath('user.membershipRole', 'admin');
        $device = $this->register();
        $this->postJson('/api/admin/logout')->assertOk();
        $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/login', ['credential' => $device->assertion($user, $options)])->assertOk();
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_fresh_step_up_is_required_and_other_users_credentials_are_isolated(): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $device = $this->register();
        $key = $user->passkeys()->first();
        $this->deleteJson('/api/security/passkeys/'.$key->id)->assertConflict();
        $this->travel(601)->seconds();
        $this->postJson('/api/admin/integrations/tokens')->assertStatus(423);
        $this->deleteJson('/api/security/passkeys/'.$key->id)->assertStatus(423);
        $options = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/confirm', ['credential' => $device->assertion($user, $options)])->assertOk();
        $this->patchJson('/api/security/passkeys/'.$key->id, ['name' => 'Renamed'])->assertOk()->assertJsonPath('passkeys.0.name', 'Renamed');
        $other = User::factory()->memberOf(Workspace::factory()->create())->create(['password' => 'Owner-password!']);
        $this->password($other)->assertOk();
        $this->deleteJson('/api/security/passkeys/'.$key->id)->assertNotFound();
        $this->patchJson('/api/security/passkeys/'.$key->id, ['name' => 'Attack'])->assertNotFound();
    }

    public function test_owner_in_another_workspace_and_late_promotion_cannot_bypass_enrollment(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace)->create(['password' => 'Owner-password!']);
        $this->password($user)->assertOk();
        $other = Workspace::factory()->create();
        $other->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $this->getJson('/api/admin/me')->assertForbidden();
        $this->postJson('/api/admin/workspaces/'.$workspace->uuid.'/select')->assertForbidden();
    }

    public function test_old_owner_session_has_only_enrollment_access_and_no_secret_in_normal_json(): void
    {
        $user = $this->owner();
        Auth::login($user);
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/security/totp/setup')->assertStatus(423);
        $this->postJson('/api/security/confirm', ['current_password' => 'Owner-password!'])->assertOk();
        $this->postJson('/api/security/totp/setup')->assertOk();
        foreach (['two_factor_secret', 'pending_two_factor_secret', 'two_factor_recovery_codes'] as $field) {
            $this->assertArrayNotHasKey($field, $user->fresh()->toArray());
        }
    }

    public function test_standard_fortify_endpoints_do_not_exist_and_attempts_are_throttled(): void
    {
        foreach (['/register', '/login', '/two-factor-challenge', '/passkeys/login', '/user/passkeys', '/user/two-factor-authentication'] as $path) {
            $this->postJson($path)->assertNotFound();
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/security/challenge', ['code' => '123456'])->assertUnprocessable();
        }
        $this->postJson('/api/security/challenge', ['code' => '123456'])->assertTooManyRequests();
    }

    public function test_owner_password_reset_needs_a_factor_and_never_grants_a_session(): void
    {
        $user = $this->owner();
        [, $codes] = $this->enrollTotp($user);
        $this->register();
        $token = Password::broker('users')->createToken($user);
        $secret = $user->fresh()->two_factor_secret;
        $this->postJson('/api/admin/logout')->assertOk();
        $body = ['email' => $user->email, 'token' => $token, 'password' => 'Changed-password!', 'password_confirmation' => 'Changed-password!'];
        $this->postJson('/api/admin/password/reset', $body)->assertUnprocessable();
        $this->postJson('/api/admin/password/reset', [...$body, 'recovery_code' => $codes[0]])->assertOk();
        $this->assertGuest();
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertNotContains($codes[0], $user->fresh()->recoveryCodes());
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Changed-password!'])->assertJsonPath('state', 'pending_2fa');
    }

    public function test_operator_recovery_requires_password_and_single_use_token_then_only_enrollment(): void
    {
        $user = $this->owner();
        $token = bin2hex(random_bytes(32));
        $user->forceFill(['security_recovery_token_hash' => hash('sha256', $token), 'security_recovery_expires_at' => now()->addMinutes(30)])->save();
        $this->password($user)->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong', 'operator_recovery_token' => $token])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Owner-password!', 'operator_recovery_token' => $token])->assertJsonPath('state', 'enrollment_required');
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/security/totp/setup')->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Owner-password!', 'operator_recovery_token' => $token])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_operator_command_requires_interactive_confirmation_and_revokes_all_factors(): void
    {
        $user = User::factory()->owner()->create();
        $this->artisan('account:recover-security', ['email' => $user->email, '--no-interaction' => true])->assertFailed();
        $this->assertTrue($user->fresh()->hasPasskeysEnabled());
        $this->artisan('account:recover-security', ['email' => $user->email])
            ->expectsQuestion('After independent identity verification, type the owner email to revoke ALL factors and sessions', $user->email)
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->hasPasskeysEnabled());
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNotNull($user->fresh()->security_recovery_token_hash);
        $this->assertSame(1, $user->fresh()->auth_session_version);
    }

    public function test_credential_changes_revoke_old_sessions_and_challenges_and_preserve_final_owner_factor(): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $this->register();
        $version = $user->fresh()->auth_session_version;
        $this->register();
        $this->assertGreaterThan($version, $user->fresh()->auth_session_version);
        $keys = $user->passkeys()->pluck('id');
        $this->deleteJson('/api/security/passkeys/'.$keys[0])->assertOk();
        $this->deleteJson('/api/security/passkeys/'.$keys[1])->assertConflict();
        $this->assertSame(1, $user->passkeys()->count());
        User::whereKey($user->id)->increment('auth_session_version');
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_registration_rejects_missing_uv_and_consumes_bad_challenge(): void
    {
        $user = $this->owner();
        $this->enrollTotp($user);
        $device = new WebAuthnDevice;
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/register', ['name' => 'Bad UV', 'credential' => $device->registration($options, uv: false)])->assertUnprocessable();
        $this->postJson('/api/security/passkey/register', ['name' => 'Replayed', 'credential' => $device->registration($options)])->assertUnprocessable();
        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_production_passkeys_refuse_http_origins(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/api/security/passkey/login/options')->assertStatus(503);
    }

    public function test_http_statuses_and_fresh_confirmation_expiry_remain_distinct(): void
    {
        $this->getJson('/api/security/passkey/register/options')->assertUnauthorized()->assertJsonPath('code', 'SEC_SESSION_EXPIRED');
        $user = $this->owner();
        $this->password($user)->assertOk();
        $this->getJson('/api/security/passkey/register/options')->assertForbidden()->assertJsonPath('code', 'SEC_FORBIDDEN');
        $this->travel(601)->seconds();
        $this->postJson('/api/security/totp/setup')->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
        $this->getJson('/api/security/passkey/register/options')->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
    }
}
