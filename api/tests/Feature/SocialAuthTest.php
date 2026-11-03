<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\AccountMail;
use App\Services\TrialEligibility;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SocialProviderFixture;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['app.url' => 'http://localhost', 'onboarding.registration_enabled' => true, 'onboarding.subject_retention_days' => 365]);
        foreach (['google', 'yandex', 'github'] as $provider) {
            config(['onboarding.providers.'.$provider => ['client_id' => 'synthetic-'.$provider.'-client', 'client_secret' => 'synthetic-provider-secret', 'callback' => 'http://localhost/auth/'.$provider.'/callback']]);
        }
        $this->mock(AccountMail::class)->shouldReceive('localized')->andReturn(true);
        Http::preventStrayRequests();
        Cache::forget('onboarding.google.jwks.v1');
    }

    private function start(string $provider, string $intent = 'login'): array
    {
        $r = $this->postJson('/api/auth/'.$provider.($intent === 'link' ? '/link' : '/start'), [])->assertOk();
        parse_str(parse_url($r->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertArrayNotHasKey('client_secret', $query);

        return $query;
    }

    private function google(array $attempt, array $claims = [], bool $invalidSignature = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Cache::forget('onboarding.google.jwks.v1');
        $fixture = new SocialProviderFixture;
        $token = $fixture->token($attempt['nonce'], $claims);
        $keys = $invalidSignature ? (new SocialProviderFixture)->jwks() : $fixture->jwks();
        Http::fake([
            'https://oauth2.googleapis.com/token' => function ($r) use ($attempt, $token) {
                $challenge = rtrim(strtr(base64_encode(hash('sha256', $r['code_verifier'], true)), '+/', '-_'), '=');
                $this->assertSame('synthetic-google-client', $r['client_id']);
                $this->assertSame('http://localhost/auth/google/callback', $r['redirect_uri']);

                return $challenge === $attempt['code_challenge'] ? Http::response(['access_token' => 'transient-test-token', 'id_token' => $token]) : Http::response(['error' => 'invalid_grant'], 400);
            },
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($keys),
        ]);
    }

    private function github(int $id = 42, string $login = 'first-name', bool $verified = true): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'transient-github-token', 'scope' => 'user:email', 'token_type' => 'bearer']),
            'https://api.github.com/user' => Http::response(['id' => $id, 'login' => $login, 'email' => 'public@example.test']),
            'https://api.github.com/user/emails*' => Http::response([['email' => 'other@example.test', 'verified' => $verified, 'primary' => false], ['email' => 'social@example.test', 'verified' => $verified, 'primary' => true]]),
        ]);
    }

    public function test_google_signed_verified_identity_creates_prepared_owner_without_activation_or_persisted_tokens(): void
    {
        $a = $this->start('google');
        $oldSession = session()->getId();
        $this->google($a);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirect('/admin/security');
        $this->assertAuthenticated();
        $this->assertNotSame($oldSession, session()->getId());
        $user = User::firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->password_login_enabled);
        $this->assertDatabaseCount('user_external_identities', 1);
        $identity = DB::table('user_external_identities')->first();
        $this->assertSame(app(TrialEligibility::class)->hash('https://accounts.google.com', 'stable-google-subject'), $identity->subject_hash);
        $this->assertDatabaseCount('workspace_members', 1);
        $this->assertDatabaseCount('owner_trial_claims', 1);
        $this->assertDatabaseCount('subscription_periods', 0);
        $row = DB::table('social_auth_attempts')->first();
        $this->assertSame('', $row->verifier_cipher);
        $this->assertNull($row->facts_cipher);
        $this->assertStringNotContainsString('transient-test-token', json_encode((array) $row));
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirectContains('authError=social_attempt_invalid');
        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(2);
    }

    public static function invalidGoogleClaims(): array
    {
        return [['iss' => 'https://evil.example.test'], ['aud' => 'other-client'], ['nonce' => 'wrong-nonce'], ['exp' => 1], ['iat' => 9999999999], ['sub' => ''], ['azp' => 'wrong-presenter']];
    }

    #[DataProvider('invalidGoogleClaims')]
    public function test_google_rejects_invalid_signed_claims_without_a_partial_account(mixed ...$values): void
    {
        // PHPUnit data sets carry the named claim as a named argument.
        $a = $this->start('google');
        $this->google($a, $values);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirectContains('authError=');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspace_members', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
    }

    public function test_signature_and_pkce_mismatch_are_rejected(): void
    {
        $a = $this->start('google');
        $this->google($a, [], true);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirectContains('authError=');
        $this->assertDatabaseCount('users', 0);
        $a = $this->start('google');
        DB::table('social_auth_attempts')->where('state_hash', hash('sha256', $a['state']))->update(['verifier_cipher' => Crypt::encryptString(str_repeat('x', 64))]);
        $this->google($a);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirectContains('authError=');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_wrong_provider_expired_state_and_cancel_do_not_exchange_or_authenticate(): void
    {
        $a = $this->start('google');
        $this->get('/auth/google/callback?code=synthetic')->assertRedirectContains('authError=social_attempt_invalid');
        $this->get('/auth/github/callback?state='.$a['state'].'&code=synthetic')->assertRedirectContains('authError=social_attempt_invalid');
        $this->get('/auth/google/callback?state='.$a['state'].'&error=access_denied')->assertRedirectContains('authError=social_cancelled');
        $a = $this->start('google');
        $this->travel(11)->minutes();
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic')->assertRedirectContains('authError=social_attempt_invalid');
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_github_uses_numeric_id_verified_primary_email_and_only_user_email_scope(): void
    {
        $a = $this->start('github');
        $this->assertSame('user:email', $a['scope']);
        $this->github();
        $this->get('/auth/github/callback?state='.$a['state'].'&code=synthetic')->assertRedirect('/admin/security');
        $user = User::firstOrFail();
        $this->assertSame('social@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $hash = DB::table('user_external_identities')->value('subject_hash');
        $this->postJson('/api/admin/logout')->assertOk();
        $a = $this->start('github');
        $this->github(login: 'renamed-account');
        $this->get('/auth/github/callback?state='.$a['state'].'&code=again')->assertRedirect('/admin/security');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($hash, DB::table('user_external_identities')->value('subject_hash'));
        $this->assertDatabaseCount('owner_trial_claims', 1);
        $this->assertDatabaseCount('subscription_periods', 0);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/user/emails') && $r->hasHeader('Authorization', 'Bearer transient-github-token'));
    }

    public function test_yandex_default_email_is_not_verified_and_wrong_client_id_is_rejected(): void
    {
        $a = $this->start('yandex');
        Http::fake(['https://oauth.yandex.ru/token' => Http::response(['access_token' => 'transient-yandex']), 'https://login.yandex.ru/info*' => Http::response(['id' => '123', 'client_id' => 'synthetic-yandex-client', 'default_email' => 'social@example.test', 'verified' => true])]);
        $this->get('/auth/yandex/callback?state='.$a['state'].'&code=synthetic')->assertRedirect('/admin/verify-email');
        $this->assertNull(User::first()->email_verified_at);
        $this->assertDatabaseCount('workspace_members', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->postJson('/api/admin/logout');
        $a = $this->start('yandex');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://oauth.yandex.ru/token' => Http::response(['access_token' => 'transient']), 'https://login.yandex.ru/info*' => Http::response(['id' => '234', 'client_id' => 'wrong', 'default_email' => 'other@example.test'])]);
        $this->get('/auth/yandex/callback?state='.$a['state'].'&code=synthetic')->assertRedirectContains('authError=');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_provider_email_collision_never_links_or_signs_into_existing_user(): void
    {
        $existing = User::factory()->owner()->create(['email' => 'social@example.test']);
        $a = $this->start('google');
        $this->google($a);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic')->assertRedirectContains('authError=social_account_link_required');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_external_identities', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->assertSame($existing->id, User::first()->id);
    }

    public function test_explicit_link_requires_fresh_mfa_and_cannot_take_another_account_identity(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        $a = $this->start('google', 'link');
        $this->google($a);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic')->assertRedirect('/admin/account/security?connected=google');
        $this->assertDatabaseHas('user_external_identities', ['user_id' => $owner->id, 'provider_key' => 'google']);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $other = User::factory()->owner()->create();
        $this->actingAs($other);
        $a = $this->start('google', 'link');
        $this->google($a);
        $this->get('/auth/google/callback?state='.$a['state'].'&code=synthetic')->assertRedirectContains('authError=social_identity_already_linked');
        $this->assertDatabaseCount('user_external_identities', 1);
        $this->travel(301)->seconds();
        $this->postJson('/api/auth/google/link', [])->assertStatus(423);
    }

    public function test_disconnection_does_not_remove_last_working_entry_method(): void
    {
        $owner = User::factory()->owner()->create(['password_login_enabled' => false]);
        $owner->passkeys()->delete();
        DB::table('user_external_identities')->insert(['user_id' => $owner->id, 'provider_key' => 'github', 'issuer_key' => 'https://github.com', 'subject_hash' => str_repeat('a', 64), 'hash_key_version' => 1, 'verified_at' => now()]);
        $this->actingAs($owner)->deleteJson('/api/auth/github')->assertStatus(409)->assertJsonPath('code', 'social_last_login_method');
        $this->assertDatabaseCount('user_external_identities', 1);
        $owner->forceFill(['password_login_enabled' => true])->save();
        $this->deleteJson('/api/auth/github')->assertOk();
        $this->assertDatabaseCount('user_external_identities', 0);
    }

    public function test_missing_email_requests_a_real_address_and_verifies_it_locally(): void
    {
        $a = $this->start('github');
        Http::fake(['https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'transient']), 'https://api.github.com/user' => Http::response(['id' => 42, 'email' => null]), 'https://api.github.com/user/emails*' => Http::response([['email' => '42@users.noreply.github.com', 'verified' => true, 'primary' => true]])]);
        $this->get('/auth/github/callback?state='.$a['state'].'&code=synthetic')->assertRedirect('/register?providerEmail=1');
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/auth/provider-email', ['email' => 'real@example.test'])->assertOk()->assertJsonPath('path', '/admin/verify-email');
        $this->assertSame('real@example.test', User::first()->email);
        $this->assertNull(User::first()->email_verified_at);
        $this->assertDatabaseCount('workspace_members', 0);
        $this->postJson('/api/auth/provider-email', ['email' => 'second@example.test'])->assertStatus(422);
    }

    public function test_verified_social_invitee_joins_existing_workspace_with_registration_closed_and_no_trial(): void
    {
        config(['onboarding.registration_enabled' => false]);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $invitation = DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => 'social@example.test', 'project_ids' => [$project->id], 'locale' => 'en', 'token_hash' => hash('sha256', 'synthetic-invitation'), 'expires_at' => now()->addDay(), 'sent_at' => now()]);
        $this->withSession(['invitation_context' => ['id' => $invitation->id, 'hash' => $invitation->token_hash]]);
        $a = $this->start('github');
        $this->github();
        $this->get('/auth/github/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirect('/invitation');
        $invitee = User::where('email', 'social@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($invitee);
        $this->assertDatabaseCount('account_onboardings', 0);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->postJson('/api/invitations/accept', [])->assertOk();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $project->workspace_id, 'user_id' => $invitee->id, 'role' => 'admin']);
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_mismatching_social_invitation_rolls_back_new_account_and_identity(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $invitation = DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => 'different-invitee@example.test', 'project_ids' => [$project->id], 'locale' => 'en', 'token_hash' => hash('sha256', 'synthetic-mismatched-invitation'), 'expires_at' => now()->addDay(), 'sent_at' => now()]);
        $this->withSession(['invitation_context' => ['id' => $invitation->id, 'hash' => $invitation->token_hash]]);
        $a = $this->start('github');
        $this->github();
        $this->get('/auth/github/callback?state='.$a['state'].'&code=synthetic-code')->assertRedirectContains('authError=social_account_unavailable');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_external_identities', 0);
        $this->assertDatabaseCount('account_onboardings', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_provider_timeout_and_cross_session_state_fail_without_authentication_or_token_persistence(): void
    {
        $attempt = $this->start('github');
        Http::fake(fn () => throw new ConnectionException('Synthetic provider timeout'));
        $this->get('/auth/github/callback?state='.$attempt['state'].'&code=synthetic-code')->assertRedirectContains('authError=provider_unavailable');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('social_auth_attempts', ['state_hash' => hash('sha256', $attempt['state']), 'state' => 'failed', 'verifier_cipher' => '', 'facts_cipher' => null]);
        $second = $this->start('github');
        session()->invalidate();
        $this->withCookie(config('session.cookie'), session()->getId());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->get('/auth/github/callback?state='.$second['state'].'&code=synthetic-code')->assertRedirectContains('authError=social_attempt_invalid');
        Http::assertNothingSent();
        config(['onboarding.providers.github.client_secret' => null]);
        $this->postJson('/api/auth/github/start', [])->assertStatus(503)->assertJsonPath('code', 'provider_unavailable');
        $this->assertDatabaseCount('users', 0);
    }
}
