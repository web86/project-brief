<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\Project;
use App\Models\User;
use App\Services\AuthOnboarding;
use App\Services\RegistrationReadiness;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'Synthetic-onboarding-only!2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['onboarding.registration_enabled' => true, 'onboarding.subject_retention_days' => 365, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'example.test', 'mail.from.address' => 'noreply@example.test']);
        Mail::fake();
    }

    private function registration(string $email = 'owner@example.test'): array
    {
        return ['email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'locale' => 'en'];
    }

    private function createPending(): array
    {
        $this->postJson('/api/onboarding/register', $this->registration())->assertStatus(202);
        $mail = Mail::sent(AccountActionMail::class)->last();
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $fragment);

        return [User::where('email', 'owner@example.test')->firstOrFail(), $fragment['token']];
    }

    public function test_registration_is_generic_normalized_and_does_not_authenticate_or_provision(): void
    {
        $one = $this->postJson('/api/onboarding/register', $this->registration('Owner@Example.test'))->assertStatus(202)->json();
        $two = $this->postJson('/api/onboarding/register', $this->registration())->assertStatus(202)->json();
        $this->assertSame($one, $two);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_onboardings', 1);
        $this->assertDatabaseCount('workspace_members', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        Mail::assertSentCount(1);
        $this->assertNull(User::first()->email_verified_at);
        $row = DB::table('account_onboardings')->first();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row->verification_hash);
        $this->assertStringNotContainsString($row->verification_hash, Mail::sent(AccountActionMail::class)->first()->actionUrl);
    }

    public function test_matching_authenticated_mailbox_proof_atomically_creates_one_prepared_owner_and_no_trial_clock(): void
    {
        [$user, $token] = $this->createPending();
        $this->postJson('/api/onboarding/verify', ['token' => $token])->assertUnauthorized();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk()->assertJsonPath('state', 'email_verification_pending');
        $this->postJson('/api/onboarding/verify', ['token' => $token])->assertOk()->assertJsonPath('replayed', false);
        $this->getJson('/api/security/status')->assertOk()->assertJsonPath('setupFresh', true);
        $verifiedAt = $user->fresh()->email_verified_at;
        $this->travel(10)->minutes();
        $this->postJson('/api/onboarding/verify', ['token' => $token])->assertOk()->assertJsonPath('replayed', true);
        $this->assertTrue($user->fresh()->email_verified_at->equalTo($verifiedAt));
        $this->assertDatabaseCount('workspace_members', 1);
        $this->assertDatabaseHas('workspace_members', ['user_id' => $user->id, 'role' => 'owner']);
        $this->assertFalse($user->fresh()->platform_admin);
        $this->assertDatabaseCount('platform_plan_authority', 0);
        $this->assertDatabaseCount('owner_trial_claims', 1);
        $this->assertDatabaseHas('owner_trial_claims', ['state' => 'pending', 'starts_at' => null, 'ends_at' => null, 'period_id' => null]);
        $this->assertDatabaseCount('workspace_subscriptions', 0);
        $this->assertDatabaseCount('subscription_periods', 0);
        $this->assertDatabaseCount('integration_tokens', 0);
        $this->getJson('/api/session')->assertOk()->assertJsonPath('nextPath', '/admin/security');
        $this->getJson('/api/admin/projects')->assertForbidden()->assertJsonPath('code', 'onboarding_required');
    }

    public function test_pending_account_cannot_read_write_export_upload_or_reach_another_tenant(): void
    {
        [$user] = $this->createPending();
        $foreign = Project::factory()->create();
        $this->actingAs($user);
        foreach (['/api/admin/me', '/api/admin/projects', '/api/admin/workspaces', '/api/admin/account', '/api/admin/projects/'.$foreign->uuid, '/api/security/totp/setup', '/api/admin/integrations', '/api/admin/resource-selection'] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'onboarding_required');
        }
        $this->postJson('/api/admin/projects', ['title' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/admin/integrations/tokens', ['scopes' => ['projects:read']])->assertForbidden();
        $this->get('/admin')->assertRedirect('/admin/verify-email');
        $this->getJson('/api/onboarding/state')->assertOk()->assertJsonPath('emailVerified', false);
        $this->assertDatabaseCount('workspace_members', 0);
    }

    public function test_expiry_resend_and_email_correction_invalidate_older_proof_without_extending_trial(): void
    {
        [$user, $first] = $this->createPending();
        $this->actingAs($user);
        $this->postJson('/api/onboarding/resend', ['email' => $user->email])->assertStatus(202);
        Mail::assertSentCount(1);
        $this->travel(61)->minutes();
        $this->postJson('/api/onboarding/verify', ['token' => $first])->assertStatus(410);
        $this->postJson('/api/onboarding/email', ['email' => 'correct@example.test', 'current_password' => self::PASSWORD])->assertStatus(202);
        $this->postJson('/api/onboarding/verify', ['token' => $first])->assertStatus(410);
        $mail = Mail::sent(AccountActionMail::class)->last();
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/onboarding/verify', ['token' => $fragment['token']])->assertOk();
        $this->assertSame('correct@example.test', $user->fresh()->email);
        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_production_registration_and_activation_cannot_be_enabled_by_configuration(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');
        config(['onboarding.registration_enabled' => true, 'onboarding.trial_activation_requested' => true]);
        $this->assertFalse(app(RegistrationReadiness::class)->registrationEnabled());
        $this->assertFalse(app(RegistrationReadiness::class)->activationEnabled());
        $this->postJson('/api/onboarding/register', $this->registration())->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_existing_unverified_legacy_owner_is_not_onboarding_gated(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project, ['email_verified_at' => null]);
        $this->actingAs($owner)->getJson('/api/admin/me')->assertOk();
        $this->getJson('/api/onboarding/state')->assertJsonPath('onboarding', false);
        $this->assertDatabaseCount('owner_trial_claims', 0);
    }

    public function test_pending_password_recovery_never_verifies_email_or_grants_workspace_access(): void
    {
        [$user] = $this->createPending();
        $this->postJson('/api/admin/password/forgot', ['email' => $user->email])->assertOk();
        $mail = Mail::sent(AccountActionMail::class)->last();
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $fragment);
        $new = 'Recovered-synthetic-only!2026';
        $this->postJson('/api/admin/password/reset', ['email' => $user->email, 'token' => $fragment['token'], 'password' => $new, 'password_confirmation' => $new])->assertOk();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => $new])->assertOk()->assertJsonPath('state', 'email_verification_pending');
        $this->getJson('/api/admin/me')->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('owner_trial_claims', 0);
    }

    public function test_primary_only_readiness_diagnostics_are_aggregate_and_never_expose_subjects(): void
    {
        $this->getJson('/api/platform/registration-diagnostics')->assertUnauthorized();
        $primary = User::factory()->owner()->create(['platform_admin' => true]);
        $secondary = User::factory()->owner()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
        [$pending] = $this->createPending();
        $this->actingAs($primary);
        $response = $this->getJson('/api/platform/registration-diagnostics')->assertOk()->assertJsonPath('data.trialActivation', false)->assertJsonPath('data.pendingVerifications', 1);
        foreach ([$primary->email, $pending->email, 'verification_hash', 'subject_hash', 'client_secret', 'access_token'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->actingAs($secondary)->getJson('/api/platform/registration-diagnostics')->assertForbidden();
        $this->actingAs($primary);
        $this->travel(301)->seconds();
        $this->getJson('/api/platform/registration-diagnostics')->assertStatus(423);
    }

    public function test_pending_registrant_accepting_an_invitation_does_not_create_an_owner_or_trial(): void
    {
        [$pending] = $this->createPending();
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner)->postJson('/api/admin/administrators/invitations', ['email' => $pending->email, 'name' => 'Invited developer', 'projects' => [$project->uuid]])->assertCreated();
        parse_str(parse_url(Mail::sent(AccountActionMail::class)->last()->actionUrl, PHP_URL_FRAGMENT), $fragment);
        $this->actingAs($pending)->postJson('/api/invitations/accept', ['token' => $fragment['token']])->assertOk();
        $this->assertDatabaseHas('account_onboardings', ['user_id' => $pending->id, 'state' => 'invited_developer', 'workspace_id' => null, 'verification_hash' => null]);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->getJson('/api/onboarding/state')->assertJsonPath('onboarding', false);
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_expired_attempt_cleanup_is_bounded_and_does_not_delete_durable_trial_subjects(): void
    {
        [$user, $token] = $this->createPending();
        $this->actingAs($user)->postJson('/api/onboarding/verify', ['token' => $token])->assertOk();
        $aliases = DB::table('owner_trial_claim_subjects')->get()->toJson();
        foreach (range(1, 3) as $number) {
            DB::table('social_auth_attempts')->insert(['state_hash' => hash('sha256', (string) $number), 'provider' => 'github', 'intent' => 'login', 'session_hash' => str_repeat('a', 64), 'verifier_cipher' => 'encrypted-synthetic', 'nonce_hash' => str_repeat('b', 64), 'state' => 'failed', 'created_at' => now()->subHour(), 'expires_at' => $number === 3 ? now()->addMinute() : now()->subMinute()]);
        }
        $this->artisan('onboarding:prune-attempts', ['--limit' => 1, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('social_auth_attempts', 3);
        $this->artisan('onboarding:prune-attempts', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('social_auth_attempts', 2);
        $this->artisan('onboarding:prune-attempts', ['--limit' => 500])->assertSuccessful();
        $this->assertDatabaseCount('social_auth_attempts', 1);
        $this->assertSame($aliases, DB::table('owner_trial_claim_subjects')->get()->toJson());
        $this->assertDatabaseCount('owner_trial_claims', 1);
        $this->artisan('onboarding:prune-attempts', ['--limit' => 501])->assertFailed();
    }

    public function test_mandatory_audit_failure_rolls_back_mail_verification_workspace_and_claim_together(): void
    {
        [$user, $token] = $this->createPending();
        $failAudit = true;
        DB::listen(function (QueryExecuted $query) use (&$failAudit): void {
            if ($failAudit && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'plan_events')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(AuthOnboarding::class)->verify($user, $token);
            $this->fail('Verification committed without required audit');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            $failAudit = false;
        }
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->assertDatabaseHas('account_onboardings', ['user_id' => $user->id, 'verification_hash' => hash('sha256', $token), 'consumed_hash' => null]);
    }
}
