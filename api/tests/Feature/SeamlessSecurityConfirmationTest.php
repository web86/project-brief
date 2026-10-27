<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WebAuthnDevice;
use Tests\TestCase;

class SeamlessSecurityConfirmationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fortify.passkeys.allowed_origins' => ['http://localhost'], 'fortify.passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['http://localhost'], 'passkeys.relying_party_id' => 'localhost']);
    }

    public function test_unknown_locked_responses_do_not_promise_that_a_mutation_has_not_started(): void
    {
        Route::post('/api/security/test-locked', fn () => abort(423));
        $this->postJson('/api/security/test-locked')->assertStatus(423)->assertJsonPath('code', 'security_step_up_required')->assertJsonMissingPath('mutationNotStarted');
    }

    public function test_uv_confirmation_preserves_the_account_workspace_and_version_then_saves_exact_permissions(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $owner->passkeys()->delete();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $member = $developer->workspaceMembers()->first();
        $assignment = $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id]);
        $this->actingAs($owner);
        $device = new WebAuthnDevice;
        $options = $this->getJson('/api/security/passkey/register/options')->assertOk()->json('options');
        $this->postJson('/api/security/passkey/register', ['name' => 'Optional device', 'credential' => $device->registration($options)])->assertOk()->assertJsonPath('fresh', false);
        $root = '/api/admin/projects/'.$project->uuid.'/team';
        $this->getJson($root)->assertOk();
        $before = $this->getJson('/api/security/status')->assertOk()->assertJsonPath('freshFor', 0)->json();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $before['confirmationContext']);
        $version = (int) $owner->fresh()->auth_session_version;
        $session = session()->getId();
        $body = ['projects' => [$project->uuid], 'projectPermissions' => [$project->uuid => ['manage_clients' => true, 'manage_project_settings' => true, 'manage_project_notifications' => true, 'manage_trash' => true, 'export_project' => true]]];
        $this->patchJson($root.'/members/'.$member->id, $body)->assertStatus(423)->assertJsonPath('code', 'security_step_up_required')->assertJsonPath('mutationNotStarted', true);
        $this->assertNull($assignment->fresh()->permissions);
        $options = $this->getJson('/api/security/passkey/confirm/options')->assertOk()->json('options');
        $this->assertSame('required', $options['userVerification']);
        $this->postJson('/api/security/passkey/confirm', ['credential' => $device->assertion($owner, $options)])->assertOk()->assertJsonPath('fresh', true)->assertJsonPath('freshFor', 300)->assertJsonPath('confirmationContext', $before['confirmationContext']);
        $this->assertAuthenticatedAs($owner);
        $this->assertSame($project->workspace_id, session('workspace_id'));
        $this->assertSame($version, (int) $owner->fresh()->auth_session_version);
        $this->assertNotSame($session, session()->getId());
        $this->patchJson($root.'/members/'.$member->id, $body)->assertOk();
        foreach ($body['projectPermissions'][$project->uuid] as $permission => $enabled) {
            $this->assertSame($enabled, $assignment->fresh()->permissions[$permission]);
        }
        $this->postJson('/api/security/passkey/confirm', ['credential' => $device->assertion($owner, $options)])->assertUnprocessable();
    }

    public function test_fresh_duration_expires_and_context_changes_with_role_workspace_and_version(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        $this->getJson('/api/admin/me')->assertOk();
        $first = $this->getJson('/api/security/status')->assertJsonPath('freshFor', 300)->json('confirmationContext');
        $this->travel(299)->seconds();
        $this->getJson('/api/security/status')->assertJsonPath('freshFor', 1);
        $this->travel(1)->seconds();
        $this->getJson('/api/security/status')->assertJsonPath('fresh', false)->assertJsonPath('freshFor', 0)->assertJsonPath('confirmationContext', $first);
        $owner->workspaceMembers()->first()->update(['can_create_projects' => true]);
        $policy = $this->getJson('/api/security/status')->json('confirmationContext');
        $this->assertNotSame($first, $policy);
        $other = Workspace::factory()->create();
        $other->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $beforeSwitch = $this->getJson('/api/security/status')->json('confirmationContext');
        $this->postJson('/api/admin/workspaces/'.$other->uuid.'/select')->assertOk();
        $this->assertNotSame($beforeSwitch, $this->getJson('/api/security/status')->json('confirmationContext'));
        $owner->forceFill(['auth_session_version' => (int) $owner->auth_session_version + 1])->save();
        $this->getJson('/api/security/status')->assertUnauthorized();
    }

    public function test_totp_alternative_never_accepts_password_only_and_preserves_one_use_codes(): void
    {
        $owner = User::factory()->owner()->create(['password' => 'Seamless-password!']);
        $this->actingAs($owner);
        $this->travel(301)->seconds();
        $this->postJson('/api/security/confirm', ['current_password' => 'Seamless-password!'])->assertUnprocessable();
        $code = app(Google2FA::class)->oathTotp(Fortify::currentEncrypter()->decrypt($owner->two_factor_secret), (int) floor(now()->timestamp / 30));
        $this->postJson('/api/security/confirm', ['code' => $code])->assertOk()->assertJsonPath('freshFor', 300)->assertJsonPath('confirmationMethod', 'mfa');
        $this->postJson('/api/security/confirm', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/security/confirm', ['recovery_code' => 'test-recovery-code'])->assertOk();
        $this->postJson('/api/security/confirm', ['recovery_code' => 'test-recovery-code'])->assertUnprocessable();
    }

    public function test_current_password_is_an_allowed_alternative_only_for_non_owner_without_totp(): void
    {
        $developer = User::factory()->memberOf(Workspace::factory()->create())->create(['password' => 'Seamless-password!']);
        $this->actingAs($developer);
        $this->travel(301)->seconds();
        $this->postJson('/api/security/confirm', ['current_password' => 'Seamless-password!'])->assertOk()->assertJsonPath('fresh', true)->assertJsonPath('confirmationMethod', 'password');
    }

    public function test_completed_confirmation_never_restores_revoked_team_authority_or_bypasses_resource_confirmation(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $this->postJson('/api/security/confirm', ['recovery_code' => 'test-recovery-code'])->assertOk();
        $project->delete();
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/permanent', ['confirmed' => true])->assertConflict()->assertJsonPath('code', 'security_action_confirmation_required');
        $project->restore();
        $membership = $owner->workspaceMembers()->first();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $membership->id]);
        $membership->update(['role' => 'admin']);
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/invitations', ['email' => 'new@example.test', 'projects' => [$project->uuid]])->assertForbidden();
        $this->assertDatabaseCount('developer_invitations', 0);
    }
}
