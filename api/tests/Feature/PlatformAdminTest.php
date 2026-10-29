<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\PlatformAudit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Support\WebAuthnDevice;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        $admin = User::factory()->owner()->create(['platform_admin' => true]);
        $admin->workspaceMembers()->delete();

        return $admin;
    }

    public function test_guests_and_workspace_owners_cannot_read_platform_or_audit_even_directly(): void
    {
        $this->getJson('/api/platform/overview')->assertUnauthorized();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        foreach (['/platform', '/api/platform/overview', '/api/platform/workspaces', '/api/platform/workspaces/'.Str::uuid(), '/api/platform/usage', '/api/platform/audit'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->assertDatabaseHas('platform_audit', ['administrator_id' => $owner->id, 'action' => 'access_denied', 'result' => 'forbidden']);
        $this->assertDatabaseHas('platform_audit', ['administrator_id' => null, 'result' => 'unauthenticated']);
        $this->postJson('/api/platform/audit', ['action' => 'forged'])->assertNotFound();
        $this->assertDatabaseMissing('platform_audit', ['action' => 'forged']);
    }

    public function test_platform_requires_enrollment_strong_session_and_fresh_step_up(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->getJson('/api/platform/overview')->assertOk();
        $this->travel(301)->seconds();
        $this->getJson('/api/platform/overview')->assertStatus(423)->assertJsonPath('mutationNotStarted', true);
        $this->assertDatabaseHas('platform_audit', ['result' => 'step_up_required']);
        $this->withSession(['security.method' => 'password']);
        $this->getJson('/api/platform/overview')->assertForbidden()->assertJsonPath('code', 'account_security_required');
        $admin->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->actingAs($admin)->getJson('/api/platform/overview')->assertForbidden()->assertJsonPath('state', 'enrollment_required');
        $this->postJson('/api/security/confirm', ['current_password' => 'password'])->assertOk()->assertJsonPath('fresh', false);
    }

    public function test_platform_only_account_logs_in_through_existing_password_mfa_and_cannot_disable_mfa(): void
    {
        $admin = $this->administrator();
        $this->withCredentials()->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->assertJsonPath('state', 'pending_2fa');
        $this->postJson('/api/security/challenge', ['recovery_code' => 'test-recovery-code'])->assertOk();
        $this->getJson('/api/session')->assertJsonPath('type', 'platform');
        $this->getJson('/api/platform/overview')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->deleteJson('/api/security/totp', ['current_password' => 'password'])->assertForbidden();
        $this->assertDatabaseHas('platform_audit', ['administrator_id' => $admin->id, 'action' => 'password_mfa_login']);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->getJson('/api/platform/overview')->assertUnauthorized();
    }

    public function test_diagnostics_reveal_counts_and_owners_but_never_project_content_or_secrets(): void
    {
        $first = Project::factory()->create(['title' => 'PRIVATE PROJECT CONTENT']);
        $owner = $this->ownerFor($first, ['email' => 'owner-a@example.test']);
        $second = Project::factory()->create(['title' => 'OTHER PRIVATE CONTENT']);
        $this->ownerFor($second);
        DB::table('integration_tokens')->insert(['uuid' => Str::uuid(), 'workspace_id' => $first->workspace_id, 'name' => 'PRIVATE TOKEN LABEL', 'secret_hash' => hash('sha256', 'secret'), 'scopes' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->administrator();
        $this->actingAs($admin);
        $this->getJson('/api/platform/workspaces?q=owner-a%40example.test')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.owners.0.email', $owner->email);
        $response = $this->getJson('/api/platform/workspaces/'.$first->workspace->uuid)->assertOk()->assertJsonPath('counts.projects', 1)->assertJsonPath('tokens.active', 1)->assertJsonPath('limits.apiPerMinute', 60);
        foreach (['PRIVATE PROJECT CONTENT', 'OTHER PRIVATE CONTENT', 'PRIVATE TOKEN LABEL', hash('sha256', 'secret'), 'branding', 'secret_hash', 'two_factor'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('workspace_members', 2);
        $this->getJson('/api/admin/projects/'.$first->uuid)->assertForbidden();
        $this->getJson('/api/client/project/'.$first->uuid)->assertUnauthorized();
        $this->assertDatabaseHas('platform_audit', ['action' => 'platform.workspace', 'object_id' => $first->workspace->uuid, 'result' => 'success']);
        $this->getJson('/api/platform/workspaces/'.Str::uuid())->assertNotFound();
        $this->assertDatabaseHas('platform_audit', ['action' => 'platform.workspace', 'result' => 'error']);
    }

    public function test_grant_and_revoke_are_interactive_audited_and_revoke_sessions(): void
    {
        $user = User::factory()->owner()->create();
        $this->artisan('platform:admin', ['user' => $user->id, '--no-interaction' => true])->assertFailed();
        $this->artisan('platform:admin', ['user' => $user->id])->expectsQuestion('After independent identity verification, type: GRANT PLATFORM ADMIN '.$user->id, 'wrong')->assertFailed();
        $this->assertFalse($user->fresh()->platform_admin);
        $this->actingAs($user);
        $version = $user->auth_session_version;
        $this->artisan('platform:admin', ['user' => $user->id])->expectsQuestion('After independent identity verification, type: GRANT PLATFORM ADMIN '.$user->id, 'GRANT PLATFORM ADMIN '.$user->id)->assertSuccessful();
        $this->assertTrue($user->fresh()->platform_admin);
        $this->assertGreaterThan($version, $user->fresh()->auth_session_version);
        $this->getJson('/api/platform/overview')->assertUnauthorized();
        $this->assertDatabaseHas('platform_audit', ['action' => 'privilege_granted', 'object_id' => (string) $user->id, 'administrator_id' => null]);
        $this->artisan('platform:admin', ['user' => $user->id, '--revoke' => true])->expectsQuestion('After independent identity verification, type: REVOKE PLATFORM ADMIN '.$user->id, 'REVOKE PLATFORM ADMIN '.$user->id)->assertSuccessful();
        $this->assertFalse($user->fresh()->platform_admin);
        $this->assertDatabaseHas('platform_audit', ['action' => 'privilege_revoked']);
        $user->fill(['platform_admin' => true]);
        $this->assertFalse($user->refresh()->platform_admin);
    }

    public function test_missing_mfa_cannot_receive_grant_and_revoke_invalidates_existing_proof(): void
    {
        $user = User::factory()->create();
        $this->artisan('platform:admin', ['user' => $user->id])->assertFailed();
        $admin = $this->administrator();
        $this->actingAs($admin);
        $this->getJson('/api/platform/overview')->assertOk();
        $admin->forceFill(['platform_admin' => false])->save();
        $this->getJson('/api/platform/overview')->assertForbidden();
        $this->assertFalse(app(AccountSecurity::class)->fresh(request()));
    }

    public function test_periods_pagination_validation_and_activity_are_explicit(): void
    {
        $old = Workspace::factory()->create(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)]);
        $this->actingAs($this->administrator());
        foreach (['24h', '7d', '30d'] as $period) {
            $this->getJson('/api/platform/overview?period='.$period)->assertOk()->assertJsonPath('workspaces.inactive', 1);
        }
        $this->getJson('/api/platform/overview?period=all')->assertUnprocessable();
        $this->getJson('/api/platform/workspaces?page=0')->assertUnprocessable();
        $this->getJson('/api/platform/workspaces?q='.str_repeat('x', 101))->assertUnprocessable();
        $this->getJson('/api/platform/usage?workspace=invalid')->assertUnprocessable();
        $this->getJson('/api/platform/audit')->assertOk();
        $this->assertNotNull($old);
    }

    public function test_platform_only_account_uses_existing_passkeys_and_password_recovery(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin);
        $admin->passkeys()->delete();
        $device = new WebAuthnDevice;
        config(['fortify.passkeys.allowed_origins' => ['http://localhost'], 'fortify.passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost'], 'passkeys.relying_party_id' => 'localhost']);
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/register', ['name' => 'Platform device', 'credential' => $device->registration($options)])->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/login', ['credential' => $device->assertion($admin, $options)])->assertOk()->assertJsonPath('platformAdmin', true);
        $this->getJson('/api/platform/overview')->assertOk();
        $this->assertDatabaseHas('platform_audit', ['action' => 'passkey_login', 'administrator_id' => $admin->id]);
        $token = Password::broker('users')->createToken($admin);
        $this->postJson('/api/admin/password/inspect', ['email' => $admin->email, 'token' => $token])->assertOk()->assertJsonPath('totp', true);
        $this->postJson('/api/admin/password/reset', ['email' => $admin->email, 'token' => $token, 'password' => 'Replacement-password!90', 'password_confirmation' => 'Replacement-password!90', 'recovery_code' => 'test-recovery-code'])->assertOk();
        $this->assertTrue($admin->fresh()->platform_admin);
        $this->getJson('/api/platform/overview')->assertUnauthorized();
    }

    public function test_audit_failure_fails_closed_and_cannot_return_platform_data(): void
    {
        $this->actingAs($this->administrator());
        $this->mock(PlatformAudit::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->getJson('/api/platform/overview')->assertStatus(500)->assertJsonMissingPath('workspaces');
    }

    public function test_legacy_clients_and_existing_webhook_history_are_counted_with_workspace_isolation(): void
    {
        $project = Project::factory()->create();
        $project->clients()->create(['name' => 'Private client', 'email' => 'private@example.test']);
        $other = Project::factory()->create();
        $other->clients()->create(['name' => 'Other client', 'email' => 'other@example.test']);
        $endpoint = DB::table('webhook_endpoints')->insertGetId(['uuid' => Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Private endpoint', 'url' => 'https://private.example.test', 'events' => '[]', 'secret' => 'PRIVATE SECRET', 'created_at' => now(), 'updated_at' => now()]);
        $event = DB::table('webhook_events')->insertGetId(['uuid' => Str::uuid(), 'workspace_id' => $project->workspace_id, 'type' => 'task.approved', 'body' => 'PRIVATE BODY', 'created_at' => now()->subDays(40)]);
        $delivery = DB::table('webhook_deliveries')->insertGetId(['uuid' => Str::uuid(), 'webhook_endpoint_id' => $endpoint, 'webhook_event_id' => $event, 'secret_version' => 1, 'status' => 'pending', 'attempts' => 2, 'available_at' => now(), 'created_at' => now()->subDays(40), 'updated_at' => now()]);
        foreach ([1, 2] as $attempt) {
            DB::table('webhook_attempts')->insert(['webhook_delivery_id' => $delivery, 'attempt' => $attempt, 'status' => 'pending', 'error_code' => 'remote_transient', 'duration_ms' => 100, 'created_at' => now()]);
        }
        $this->actingAs($this->administrator());
        $response = $this->getJson('/api/platform/workspaces/'.$project->workspace->uuid)->assertOk()->assertJsonPath('counts.clients', 2)->assertJsonPath('webhooks.events', 0)->assertJsonPath('webhooks.backlog', 1)->assertJsonPath('webhooks.attempts.total', 2);
        $this->assertEquals(1, $response->json('webhooks.attempts.retries'));
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->getJson('/api/platform/workspaces/'.$other->workspace->uuid)->assertOk()->assertJsonPath('webhooks.backlog', 0)->assertJsonPath('counts.clients', 2);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_attempts', 2);
    }

    public function test_platform_rate_limit_is_applied_to_direct_api_calls(): void
    {
        $this->actingAs($this->administrator());
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/platform/overview')->assertOk();
        }
        $this->getJson('/api/platform/overview')->assertTooManyRequests();
    }
}
