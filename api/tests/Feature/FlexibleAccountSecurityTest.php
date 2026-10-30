<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WebAuthnDevice;
use Tests\TestCase;

class FlexibleAccountSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['fortify.passkeys.allowed_origins' => ['http://localhost'], 'fortify.passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost'], 'passkeys.relying_party_id' => 'localhost']);
    }

    private function developer(bool $totp = false): User
    {
        $user = User::factory()->memberOf(Workspace::factory()->create())->create(['password' => 'Flexible-password!']);
        if ($totp) {
            $secret = app(Google2FA::class)->generateSecretKey(32);
            $user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])), 'recovery_codes_acknowledged_at' => now()])->save();
        }

        return $user;
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Flexible-password!']);
    }

    private function otp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));
    }

    private function registerKey(User $user): WebAuthnDevice
    {
        $device = new WebAuthnDevice;
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/register', ['name' => 'Optional key', 'credential' => $device->registration($options)])->assertOk();

        return $device;
    }

    public static function combinations(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('combinations')]
    public function test_developer_login_and_step_up_matrix(bool $totp, bool $passkey): void
    {
        $user = $this->developer($totp);
        $device = null;
        if ($passkey) {
            $this->actingAs($user);
            $device = $this->registerKey($user);
            $this->postJson('/api/admin/logout')->assertOk();
        }
        $response = $this->login($user)->assertOk();
        if ($totp) {
            $response->assertJsonPath('state', 'pending_2fa');
            $this->assertGuest();
            $this->getJson('/api/admin/me')->assertForbidden();
            $this->login($user)->assertJsonPath('state', 'pending_2fa');
            $this->postJson('/api/security/challenge', ['code' => $this->otp($user)])->assertOk()->assertJsonPath('state', 'authenticated');
        } else {
            $this->getJson('/api/admin/me')->assertOk();
        }
        $this->travel(301)->seconds();
        $this->postJson('/api/security/confirm', ['current_password' => 'Flexible-password!'])->assertStatus($totp ? 422 : 200);
        if ($totp) {
            $this->postJson('/api/security/confirm', ['recovery_code' => 'recovery-one'])->assertOk()->assertJsonPath('fresh', true)->assertJsonPath('confirmationMethod', 'mfa');
            $this->postJson('/api/security/confirm', ['recovery_code' => 'recovery-one'])->assertUnprocessable();
        }
        if ($passkey) {
            $this->postJson('/api/admin/logout')->assertOk();
            $options = $this->getJson('/api/security/passkey/login/options')->assertOk()->json('options');
            $this->postJson('/api/security/passkey/login', ['credential' => $device->assertion($user, $options)])->assertOk()->assertJsonPath('state', 'authenticated')->assertJsonPath('confirmationMethod', 'passkey_uv');
        }
    }

    public function test_owner_does_not_need_a_passkey_and_cannot_disable_totp(): void
    {
        $user = User::factory()->owner(null, false)->create(['password' => 'Flexible-password!']);
        $this->login($user)->assertJsonPath('state', 'enrollment_required');
        $this->postJson('/api/security/totp/setup')->assertOk();
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->pending_two_factor_secret);
        $codes = $this->postJson('/api/security/totp/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk()->json('codes');
        $this->getJson('/api/admin/me')->assertForbidden();
        $this->postJson('/api/security/recovery-codes/acknowledge')->assertOk()->assertJsonPath('state', 'authenticated')->assertJsonPath('fresh', false);
        $this->getJson('/api/admin/me')->assertOk();
        $this->assertDatabaseCount('passkeys', 0);
        $this->postJson('/api/security/confirm', ['current_password' => 'Flexible-password!'])->assertUnprocessable();
        $this->postJson('/api/security/confirm', ['recovery_code' => $codes[0]])->assertOk()->assertJsonPath('fresh', true);
        $this->travel(61)->seconds();
        $this->deleteJson('/api/security/totp', ['current_password' => 'Flexible-password!'])->assertForbidden()->assertJsonPath('code', 'SEC_OWNER_TOTP_REQUIRED');
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_final_passkey_removal_requires_a_proven_alternative_and_registration_is_not_step_up(): void
    {
        $user = $this->developer();
        $this->actingAs($user);
        $this->registerKey($user);
        $id = $user->passkeys()->value('id');
        $this->getJson('/api/security/status')->assertJsonPath('fresh', false);
        $this->deleteJson('/api/security/passkeys/'.$id, ['current_password' => 'Flexible-password!'])->assertStatus(423);
        $this->postJson('/api/security/confirm', ['current_password' => 'Flexible-password!'])->assertOk();
        $this->deleteJson('/api/security/passkeys/'.$id, ['current_password' => 'wrong'])->assertUnprocessable()->assertJsonPath('code', 'SEC_ALTERNATIVE_REQUIRED');
        $this->deleteJson('/api/security/passkeys/'.$id, ['current_password' => 'Flexible-password!'])->assertOk();
        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_developer_can_disable_totp_only_after_fresh_factor_and_password_alternative(): void
    {
        $user = $this->developer(true);
        $this->actingAs($user);
        $secret = $user->two_factor_secret;
        $this->deleteJson('/api/security/totp')->assertUnprocessable();
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->deleteJson('/api/security/totp', ['current_password' => 'Flexible-password!'])->assertOk()->assertJsonPath('totp', false)->assertJsonPath('fresh', false);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->login($user)->assertOk();
        $this->getJson('/api/admin/me')->assertOk();
    }

    public function test_five_minute_proof_is_bound_to_session_security_version_and_membership_policy(): void
    {
        $user = $this->developer();
        $this->actingAs($user);
        $this->getJson('/api/security/status')->assertJsonPath('fresh', true);
        $member = $user->workspaceMembers()->first();
        $member->update(['can_create_projects' => true]);
        $member->update(['can_create_projects' => false]);
        $this->getJson('/api/security/status')->assertJsonPath('fresh', false);
        $this->postJson('/api/security/confirm', ['current_password' => 'Flexible-password!'])->assertOk()->assertJsonPath('fresh', true);
        $this->travel(300)->seconds();
        $this->getJson('/api/security/status')->assertJsonPath('fresh', false);
        $this->postJson('/api/security/confirm', ['current_password' => 'Flexible-password!'])->assertOk();
        session()->regenerate();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->getJson('/api/security/status')->assertJsonPath('fresh', false);
    }

    public function test_promotion_in_any_workspace_restricts_access_and_demotion_preserves_factors(): void
    {
        $user = $this->developer();
        $this->actingAs($user);
        $other = Workspace::factory()->create();
        $membership = $other->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $this->getJson('/api/admin/me')->assertForbidden();
        $this->getJson('/api/security/status')->assertJsonPath('owner', true)->assertJsonPath('state', 'enrollment_required')->assertJsonPath('fresh', false);
        $membership->update(['role' => 'admin']);
        $this->getJson('/api/admin/me')->assertOk();
        $this->assertDatabaseCount('passkeys', 0);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_developer_password_reset_preserves_totp_and_does_not_grant_authentication(): void
    {
        $user = $this->developer(true);
        $secret = $user->two_factor_secret;
        $token = Password::broker('users')->createToken($user);
        $body = ['email' => $user->email, 'token' => $token, 'password' => 'New-flexible-password!', 'password_confirmation' => 'New-flexible-password!'];
        $this->postJson('/api/admin/password/reset', $body)->assertUnprocessable();
        $this->postJson('/api/admin/password/reset', [...$body, 'recovery_code' => 'recovery-one'])->assertOk();
        $this->assertGuest();
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'New-flexible-password!'])->assertJsonPath('state', 'pending_2fa');
    }

    public function test_recommendations_are_global_once_only_and_do_not_gate_business_access(): void
    {
        $user = $this->developer();
        $this->actingAs($user);
        $this->getJson('/api/session')->assertJsonPath('authenticated', true)->assertJsonPath('securityRecommended', true);
        $this->getJson('/api/admin/me')->assertOk();
        $this->postJson('/api/security/recommendations/acknowledge')->assertOk()->assertJsonPath('recommendationsPending', false);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->login($user)->assertOk()->assertJsonPath('securityRecommended', false);
    }

    public function test_sensitive_client_links_require_both_live_acl_and_fresh_confirmation_but_comments_do_not(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->memberOf($project->workspace)->create(['password' => 'Flexible-password!']);
        $membership = $user->workspaceMembers()->first();
        $membership->update(['is_project_superadmin' => true]);
        $assignment = $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $membership->id, 'permissions' => ['manage_clients' => true]]);
        $client = $project->clients()->first();
        $task = Task::factory()->for($project)->create();
        $this->actingAs($user);
        $this->travel(301)->seconds();
        $root = '/api/admin/projects/'.$project->uuid;
        $this->postJson($root.'/clients/'.$client->uuid.'/access-links')->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Ordinary comment'])->assertOk();
        $this->getJson($root.'/team')->assertOk();
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'No owner privilege', 'scopes' => ['projects:read']])->assertForbidden();
        $this->postJson('/api/security/confirm', ['current_password' => 'Flexible-password!'])->assertOk();
        $token = $this->postJson($root.'/clients/'.$client->uuid.'/access-links')->assertCreated()->json('id');
        $assignment->update(['permissions' => ['manage_clients' => false]]);
        $membership->update(['is_project_superadmin' => false]);
        $this->deleteJson($root.'/clients/'.$client->uuid.'/access-links/'.$token)->assertForbidden();
    }

    public function test_team_factor_projection_is_owner_only_batched_and_omits_pending_invitation_details(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $root = '/api/admin/administrators';
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = $this->getJson($root)->assertOk()->assertJsonPath('members.0.security.totp', true)->assertJsonPath('members.0.security.passkey', true);
        $queries = count(DB::getQueryLog());
        $members = [];
        for ($i = 0; $i < 5; $i++) {
            $members[] = User::factory()->memberOf($project->workspace)->create();
        }
        DB::flushQueryLog();
        $this->getJson($root)->assertOk()->assertJsonCount(6, 'members');
        $this->assertSame($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $external = $this->developer(true);
        DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $external->email, 'project_ids' => [$project->id], 'locale' => 'en', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDay()]);
        $pending = $this->getJson($root)->assertOk()->json('invitations.0');
        $this->assertArrayNotHasKey('security', $pending);
        $this->assertArrayNotHasKey('user', $pending);
        $this->assertStringNotContainsString($owner->two_factor_secret, $first->getContent());
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $members[0]->workspaceMembers()->first()->id]);
        $this->actingAs($members[0]);
        $this->getJson($root)->assertForbidden();
        $this->postJson('/api/admin/logout')->assertOk();
        $this->getJson($root)->assertUnauthorized();
    }

    public function test_invitation_cannot_bypass_optional_totp_and_acceptance_does_not_refresh_step_up(): void
    {
        $user = $this->developer(true);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $token = bin2hex(random_bytes(32));
        $invitation = DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $user->email, 'project_ids' => [$project->id], 'locale' => 'en', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->actingAs($user);
        $this->withSession(['security.method' => 'password']);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertForbidden();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->login($user)->assertJsonPath('state', 'pending_2fa');
        $this->postJson('/api/security/challenge', ['code' => $this->otp($user)])->assertOk();
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
        $this->getJson('/api/security/status')->assertJsonPath('state', 'authenticated')->assertJsonPath('fresh', false);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_irreversible_deletion_confirmation_is_bound_to_exact_target_and_expires(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->workspace)->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $project->delete();
        $other->delete();
        $root = '/api/admin/projects/';
        $this->deleteJson($root.$project->uuid.'/permanent', ['confirmed' => true])->assertConflict()->assertJsonPath('code', 'security_action_confirmation_required');
        $confirmation = $this->postJson($root.$project->uuid.'/permanent/confirmation')->assertOk()->json('confirmation');
        $this->deleteJson($root.$other->uuid.'/permanent', ['confirmed' => true, 'confirmation' => $confirmation])->assertConflict();
        $this->assertDatabaseHas('projects', ['id' => $other->id]);
        $this->deleteJson($root.$project->uuid.'/permanent', ['confirmed' => true, 'confirmation' => $confirmation])->assertOk();
        $this->deleteJson($root.$other->uuid.'/permanent', ['confirmed' => true, 'confirmation' => $confirmation])->assertConflict();
        $next = $this->postJson($root.$other->uuid.'/permanent/confirmation')->assertOk()->json('confirmation');
        $this->travel(121)->seconds();
        $this->deleteJson($root.$other->uuid.'/permanent', ['confirmed' => true, 'confirmation' => $next])->assertConflict();
        $this->assertDatabaseHas('projects', ['id' => $other->id]);
    }

    public function test_existing_071_strong_owner_session_without_passkey_keeps_access_but_not_sensitive_proof(): void
    {
        $user = User::factory()->owner()->create(['password' => 'Flexible-password!']);
        $user->passkeys()->delete();
        $this->actingAs($user);
        $this->withSession(['security' => ['user' => $user->id, 'version' => (int) $user->auth_session_version, 'method' => 'mfa', 'confirmed_at' => now()->timestamp]]);
        $this->getJson('/api/admin/me')->assertOk();
        $this->getJson('/api/security/status')->assertJsonPath('state', 'authenticated')->assertJsonPath('fresh', false);
        $this->postJson('/api/admin/account/password', ['current_password' => 'Flexible-password!', 'password' => 'Changed-password!', 'password_confirmation' => 'Changed-password!'])->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
    }

    public function test_configured_factors_survive_promotion_demotion_and_workspace_switch(): void
    {
        $user = $this->developer(true);
        $this->actingAs($user);
        $this->registerKey($user);
        $secret = $user->fresh()->two_factor_secret;
        $key = $user->passkeys()->first()->getAttributes();
        $selected = $user->workspaceMembers()->first()->workspace;
        $other = Workspace::factory()->create();
        $membership = $other->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $this->postJson('/api/admin/workspaces/'.$selected->uuid.'/select')->assertOk();
        $this->getJson('/api/security/status')->assertJsonPath('owner', true)->assertJsonPath('state', 'authenticated')->assertJsonPath('fresh', false);
        $membership->update(['role' => 'admin']);
        $this->getJson('/api/security/status')->assertJsonPath('owner', false)->assertJsonPath('totp', true);
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertSame($key, $user->passkeys()->first()->getAttributes());
    }

    public function test_owner_final_passkey_removal_after_uv_also_proves_totp_password_alternative(): void
    {
        $user = User::factory()->owner()->create(['password' => 'Flexible-password!']);
        $user->passkeys()->delete();
        $this->actingAs($user);
        $device = $this->registerKey($user);
        $id = $user->passkeys()->value('id');
        $options = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/confirm', ['credential' => $device->assertion($user, $options)])->assertOk()->assertJsonPath('confirmationMethod', 'passkey_uv');
        $this->deleteJson('/api/security/passkeys/'.$id, ['current_password' => 'Flexible-password!'])->assertUnprocessable();
        $this->deleteJson('/api/security/passkeys/'.$id, ['current_password' => 'Flexible-password!', 'code' => $this->otp($user)])->assertOk()->assertJsonPath('fresh', false);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseCount('passkeys', 0);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->login($user)->assertJsonPath('state', 'pending_2fa');
        $this->postJson('/api/security/challenge', ['code' => $this->otp($user)])->assertUnprocessable();
        $this->postJson('/api/security/challenge', ['recovery_code' => 'test-recovery-code'])->assertOk()->assertJsonPath('state', 'authenticated');
    }
}
