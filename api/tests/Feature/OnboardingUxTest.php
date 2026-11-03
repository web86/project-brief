<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboarding;
use App\Models\User;
use App\Services\AuthOnboarding;
use App\Services\SocialAuth;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OnboardingUxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function prepared(bool $enrolled = false): User
    {
        config(['onboarding.subject_retention_days' => 365]);
        $user = User::factory()->create(['password' => 'Synthetic-UX-password!2026']);
        app(AuthOnboarding::class)->pending($user);
        app(AuthOnboarding::class)->prepare($user);
        if ($enrolled) {
            $user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['synthetic-ux-recovery'])), 'recovery_codes_acknowledged_at' => now()])->save();
        }

        return $user->fresh();
    }

    public function test_server_progress_requires_totp_recovery_and_provisioning_without_retiming_a_claim(): void
    {
        $user = $this->prepared();
        $claim = DB::table('owner_trial_claims')->first();
        $this->actingAs($user)->getJson('/api/onboarding/state')->assertOk()->assertJsonPath('step', 'authenticator')->assertJsonPath('checks.totpConfirmed', false)->assertJsonPath('checks.recoveryAcknowledged', false)->assertJsonPath('trialActivationEnabled', false);
        $setup = $this->postJson('/api/security/totp/setup')->assertOk()->json();
        parse_str(parse_url($setup['uri'], PHP_URL_QUERY), $query);
        $code = app(Google2FA::class)->getCurrentOtp($query['secret']);
        $codes = $this->postJson('/api/security/totp/confirm', ['code' => $code])->assertOk()->json('codes');
        $this->getJson('/api/onboarding/state')->assertJsonPath('step', 'recovery')->assertJsonPath('checks.totpConfirmed', true)->assertJsonPath('checks.recoveryAcknowledged', false);
        $encryptedCodes = $user->fresh()->two_factor_recovery_codes;
        $this->getJson('/api/security/recovery-codes')->assertOk()->assertJsonPath('codes', $codes);
        $this->getJson('/api/security/recovery-codes')->assertOk()->assertJsonPath('codes', $codes);
        $this->assertSame($encryptedCodes, $user->fresh()->two_factor_recovery_codes);
        $this->postJson('/api/security/recovery-codes/acknowledge')->assertOk();
        foreach (range(1, 2) as $attempt) {
            $this->getJson('/api/onboarding/state')->assertOk()->assertJsonPath('step', 'complete')->assertJsonPath('state', 'ready_for_trial_activation')->assertJsonPath('checks.workspacePrepared', true)->assertJsonPath('checks.recoveryAcknowledged', true)->assertJsonPath('registrationEnabled', false)->assertJsonPath('trialActivationEnabled', false);
        }
        $this->assertSame((array) $claim, (array) DB::table('owner_trial_claims')->first());
        $this->assertDatabaseCount('passkeys', 0);
        $this->assertDatabaseCount('subscription_periods', 0);
        $user->workspaceMembers()->delete();
        $this->getJson('/api/onboarding/state')->assertJsonPath('checks.workspacePrepared', false)->assertJsonPath('step', 'preparing');
    }

    public function test_prepared_owner_can_manage_own_security_but_business_endpoints_remain_closed(): void
    {
        $user = $this->prepared(true);
        $claim = (array) DB::table('owner_trial_claims')->first();
        $this->actingAs($user);
        $this->getJson('/api/admin/account')->assertOk()->assertJsonPath('user.email', $user->email);
        $this->getJson('/api/security/status')->assertOk()->assertJsonPath('totp', true)->assertJsonPath('onboardingNext', '/admin/onboarding');
        $this->getJson('/api/auth/connections')->assertOk();
        $request = Request::create('/admin/account/security');
        $request->setUserResolver(fn () => $user);
        $this->assertSame(200, app(EnsureOnboarding::class)->handle($request, fn () => response('Personal security only'))->getStatusCode());
        $this->assertSame('/admin/account/security', app(SocialAuth::class)->connectionsPath($request));
        foreach (['me', 'projects', 'workspaces', 'notifications', 'administrators', 'integrations', 'resource-selection'] as $path) {
            $this->getJson('/api/admin/'.$path)->assertForbidden()->assertJsonPath('code', 'onboarding_required');
        }
        $this->postJson('/api/admin/projects', ['title' => 'Forbidden'])->assertForbidden();
        $this->travel(301)->seconds();
        $this->getJson('/api/security/passkey/register/options')->assertStatus(423);
        $this->assertSame($claim, (array) DB::table('owner_trial_claims')->first());
        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_existing_agreement_gate_is_included_in_progress_and_does_not_repeat_acceptance(): void
    {
        $user = $this->prepared(true);
        $member = $user->workspaceMembers()->firstOrFail();
        $revision = (string) Str::uuid();
        $document = ['id' => $revision, 'date' => '2026-11-03', 'texts' => ['en' => [['type' => 'paragraph', 'content' => [['text' => 'Synthetic agreement']]]]], 'enabledLocales' => ['en'], 'defaultLocale' => 'en'];
        $member->workspace->update(['administrator_agreement' => ['enabled' => true, 'published' => $document]]);
        $member->forceFill(['role' => 'admin', 'agreement_requirement' => $revision])->save();
        $this->actingAs($user)->getJson('/api/onboarding/state')->assertOk()->assertJsonPath('agreementRequired', true)->assertJsonPath('checks.agreementAccepted', false)->assertJsonPath('step', 'agreement')->assertJsonPath('nextPath', '/admin/agreement');
        $this->getJson('/api/session')->assertOk()->assertJsonPath('agreementRequired', true);
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $revision, 'locale' => 'en'])->assertOk();
        $this->getJson('/api/onboarding/state')->assertJsonPath('step', 'complete')->assertJsonPath('agreementRequired', false);
        $this->postJson('/api/admin/agreement/accept', ['revision' => $revision, 'locale' => 'en'])->assertOk();
        $this->assertDatabaseCount('workspace_agreement_acceptances', 1);
        $this->assertDatabaseCount('subscription_periods', 0);
    }
}
