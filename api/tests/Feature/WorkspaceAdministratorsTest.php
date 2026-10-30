<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkspaceAdministratorsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ROOT = '/api/admin/administrators';

    public function test_invitation_without_projects_accepts_once_without_duplicate_normalized_accounts(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'test@example.test']);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner)->postJson(self::ROOT.'/invitations', ['name' => 'New admin', 'email' => '  NEW@Example.test  '])->assertCreated();
        $invitation = DeveloperInvitation::firstOrFail();
        $this->assertSame([], $invitation->project_ids);
        $this->assertSame('new@example.test', $invitation->email);
        $this->assertNotNull($invitation->sent_at);
        parse_str(parse_url(Mail::sent(AccountActionMail::class)->last()->actionUrl, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/invitations/accept', ['token' => $fragment['token'], 'name' => 'New admin', 'password' => 'Long-new-password', 'password_confirmation' => 'Long-new-password'])->assertOk();
        $this->getJson('/api/admin/projects')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/projects/'.$project->uuid)->assertNotFound();
        $this->getJson(self::ROOT)->assertForbidden();
        $this->postJson('/api/invitations/accept', ['token' => $fragment['token']])->assertGone();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('workspace_members', 2);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_duplicates_drafts_and_existing_accounts_do_not_create_or_send_twice(): void
    {
        Mail::fake();
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create(['email' => 'existing@example.test']);
        $member = $admin->workspaceMembers()->firstOrFail();
        $this->actingAs($owner)->postJson(self::ROOT.'/invitations', ['email' => 'EXISTING@example.test'])->assertConflict()->assertJsonPath('errors.memberId', $member->id);
        $id = $this->postJson(self::ROOT.'/invitations', ['email' => 'draft@example.test', 'sendEmail' => false])->assertCreated()->assertJsonPath('sent', false)->json('id');
        $this->postJson(self::ROOT.'/invitations', ['email' => 'DRAFT@example.test'])->assertConflict()->assertJsonPath('errors.invitationId', $id);
        $this->getJson(self::ROOT)->assertJsonPath('invitations.0.status', 'draft');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('developer_invitations', 1);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_pending_invitation_assignments_can_be_changed_without_replacing_token_or_crossing_tenants(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $foreign = Project::factory()->create();
        $this->actingAs($owner);
        $id = $this->postJson(self::ROOT.'/invitations', ['email' => 'draft@example.test', 'sendEmail' => false])->assertCreated()->json('id');
        $invitation = DeveloperInvitation::where('uuid', $id)->firstOrFail();
        $before = $invitation->token_hash;
        $this->patchJson(self::ROOT.'/invitations/'.$id, ['name' => 'Workspace name', 'projects' => [$project->uuid]])->assertOk();
        $this->assertSame($before, $invitation->fresh()->token_hash);
        $this->assertSame([$project->id], $invitation->fresh()->project_ids);
        $this->patchJson(self::ROOT.'/invitations/'.$id, ['projects' => [$foreign->uuid]])->assertUnprocessable();
        $this->actingAs($this->ownerFor($foreign))->patchJson(self::ROOT.'/invitations/'.$id, ['projects' => []])->assertNotFound();
        $this->actingAs($owner)->deleteJson(self::ROOT.'/invitations/'.$id)->assertOk();
        $this->patchJson(self::ROOT.'/invitations/'.$id, ['projects' => []])->assertGone();
    }

    public function test_project_assignment_is_atomic_defaults_are_safe_and_removal_affects_only_one_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create(['workspace_id' => $project->workspace_id]);
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $member = $admin->workspaceMembers()->firstOrFail();
        $foreign = User::factory()->memberOf(Project::factory()->create()->workspace)->create()->workspaceMembers()->firstOrFail();
        $team = '/api/admin/projects/'.$project->uuid.'/team';
        $this->actingAs($owner)->postJson($team.'/members', ['members' => [$member->id, $foreign->id]])->assertUnprocessable();
        $this->assertDatabaseCount('project_members', 0);
        $this->postJson($team.'/members', ['members' => [$member->id]])->assertOk();
        $this->postJson($team.'/members', ['members' => [$member->id]])->assertOk();
        $this->assertDatabaseCount('project_members', 1);
        $this->assertSame(ProjectPermissions::DEFAULTS, $member->projectMemberships()->firstOrFail()->permissions);
        $this->patchJson(self::ROOT.'/members/'.$member->id, ['name' => 'Local alias', 'projects' => [$project->uuid, $other->uuid], 'projectPermissions' => [$other->uuid => ['manage_clients' => true]], 'canCreateProjects' => true])->assertOk();
        $this->getJson($team)->assertJsonCount(1, 'members')->assertJsonCount(0, 'available')->assertJsonPath('members.0.user.name', 'Local alias')->assertJsonPath('owner.role', 'owner')->assertJsonMissingPath('members.0.projectPermissions');
        $before = $member->projectMemberships()->where('project_id', $other->id)->firstOrFail()->permissions;
        $this->deleteJson($team.'/members/'.$member->id)->assertOk();
        $this->assertSame('active', $member->fresh()->status);
        $this->assertFalse($member->fresh()->can_create_projects);
        $this->assertSame($before, $member->projectMemberships()->firstOrFail()->permissions);
        $this->assertSame($other->id, $member->projectMemberships()->firstOrFail()->project_id);
        $this->getJson($team)->assertJsonCount(0, 'members')->assertJsonCount(1, 'available');
        $this->actingAs($admin)->getJson('/api/admin/projects/'.$other->uuid)->assertOk();
        $this->getJson('/api/admin/projects/'.$project->uuid)->assertNotFound();
    }

    public function test_only_owner_can_manage_current_workspace_and_old_global_project_routes_are_removed(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $member = $admin->workspaceMembers()->firstOrFail();
        $foreign = User::factory()->memberOf(Project::factory()->create()->workspace)->create()->workspaceMembers()->firstOrFail();
        $this->actingAs($admin)->getJson(self::ROOT)->assertForbidden();
        $this->postJson(self::ROOT.'/invitations', ['email' => 'bypass@example.test'])->assertForbidden();
        $this->patchJson(self::ROOT.'/members/'.$member->id, ['projects' => []])->assertForbidden();
        $this->deleteJson(self::ROOT.'/members/'.$member->id)->assertForbidden();
        $this->actingAs($owner);
        foreach ([$owner->workspaceMembers()->firstOrFail()->id => 403, $foreign->id => 404] as $id => $expected) {
            $this->patchJson(self::ROOT.'/members/'.$id, ['projects' => []])->assertStatus($expected);
            $this->deleteJson(self::ROOT.'/members/'.$id)->assertStatus($expected);
        }
        $team = '/api/admin/projects/'.$project->uuid.'/team';
        $this->postJson($team.'/members', ['members' => [$member->id], 'canCreateProjects' => true])->assertUnprocessable();
        $this->postJson($team.'/members', ['members' => [$owner->workspaceMembers()->firstOrFail()->id]])->assertUnprocessable();
        $this->postJson($team.'/invitations', ['email' => 'scoped@example.test', 'generateLink' => true])->assertCreated();
        $this->patchJson($team.'/members/'.$member->id, ['projects' => []])->assertNotFound();
        $this->deleteJson($team.'/members/'.$owner->workspaceMembers()->firstOrFail()->id)->assertForbidden();
    }

    public function test_deactivation_revokes_invitations_and_access_preserves_account_and_other_workspace(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $member = $admin->workspaceMembers()->firstOrFail();
        $other = Project::factory()->create();
        $elsewhere = $other->workspace->members()->create(['user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id]);
        $other->members()->create(['workspace_id' => $other->workspace_id, 'workspace_member_id' => $elsewhere->id, 'permissions' => ['export_project' => true]]);
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $admin->email]);
        $credentials = $admin->fresh()->getAttributes();
        $this->actingAs($owner)->deleteJson(self::ROOT.'/members/'.$member->id)->assertOk();
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->assertNull($member->fresh());
        $this->assertSame($credentials, $admin->fresh()->getAttributes());
        $this->assertSame('active', $elsewhere->fresh()->status);
        $this->actingAs($admin)->withSession(['workspace_id' => $project->workspace_id])->getJson('/api/admin/projects/'.$project->uuid)->assertForbidden();
        $this->postJson('/api/admin/workspaces/'.$other->workspace->uuid.'/select')->assertOk();
        $this->getJson('/api/admin/projects/'.$other->uuid)->assertOk()->assertJsonPath('data.permissions.export_project', true);
    }

    public function test_switching_workspace_scopes_list_local_name_and_mutations(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $other = Project::factory()->create();
        $other->workspace->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $member = $admin->workspaceMembers()->firstOrFail();
        $elsewhere = $other->workspace->members()->create(['user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($owner)->patchJson(self::ROOT.'/members/'.$member->id, ['name' => 'Workspace alias', 'projects' => []])->assertOk();
        $this->assertNotSame('Workspace alias', $admin->fresh()->name);
        $this->postJson('/api/admin/workspaces/'.$other->workspace->uuid.'/select')->assertOk();
        $this->getJson(self::ROOT)->assertJsonCount(2, 'members')->assertJsonPath('members.1.user.name', $admin->name);
        $this->patchJson(self::ROOT.'/members/'.$member->id, ['projects' => []])->assertNotFound();
        $this->assertNull($elsewhere->fresh()->display_name);
    }

    public function test_client_session_cannot_read_or_mutate_global_administrators_or_sso_sources(): void
    {
        $project = Project::factory()->create();
        $client = $project->clients()->firstOrFail();
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id]);
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->postJson(self::ROOT.'/invitations', ['email' => 'denied@example.test'])->assertUnauthorized();
        $this->patchJson(self::ROOT.'/members/1', ['projects' => []])->assertUnauthorized();
        $this->deleteJson(self::ROOT.'/members/1')->assertUnauthorized();
        $this->postJson('/api/admin/integrations/sources', ['name' => 'Denied', 'origin' => 'https://portal.example.test'])->assertUnauthorized();
        $this->assertDatabaseCount('developer_invitations', 0);
        $this->assertDatabaseCount('integration_sources', 0);
    }

    public function test_every_mutation_requires_fresh_security_confirmation(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $member = $admin->workspaceMembers()->firstOrFail();
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id]);
        $this->actingAs($owner);
        $this->travel(301)->seconds();
        $this->getJson(self::ROOT)->assertOk();
        $team = '/api/admin/projects/'.$project->uuid.'/team';
        foreach ([['POST', self::ROOT.'/invitations', ['email' => 'new@example.test']], ['PATCH', self::ROOT.'/invitations/'.$invitation->uuid, ['projects' => []]], ['POST', self::ROOT.'/invitations/'.$invitation->uuid.'/resend', []], ['DELETE', self::ROOT.'/invitations/'.$invitation->uuid, []], ['PATCH', self::ROOT.'/members/'.$member->id, ['projects' => []]], ['DELETE', self::ROOT.'/members/'.$member->id, []], ['POST', $team.'/members', ['members' => [$member->id]]], ['DELETE', $team.'/members/'.$member->id, []]] as [$method, $path, $body]) {
            $this->json($method, $path, $body)->assertStatus(423)->assertJsonPath('mutationNotStarted', true);
        }
        $this->assertDatabaseCount('developer_invitations', 1);
        $this->assertNull($invitation->fresh()->revoked_at);
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_multiple_sso_sources_start_disabled_and_have_independent_keys_and_projects(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $root = '/api/admin/integrations';
        $this->actingAs($owner);
        foreach (['http://portal.example.test', 'https://user:pass@portal.example.test', 'https://portal.example.test/path', 'https://portal.example.test?x=1', 'https://portal.example.test#x'] as $origin) {
            $this->postJson($root.'/sources', ['name' => 'Invalid', 'origin' => $origin])->assertUnprocessable();
        }
        $first = $this->postJson($root.'/sources', ['name' => 'Portal A', 'origin' => 'https://PORTAL.example.test:443/'])->assertCreated()->json('id');
        $second = $this->postJson($root.'/sources', ['name' => 'Portal B', 'origin' => 'https://other.example.test'])->assertCreated()->json('id');
        $this->getJson($root)->assertJsonPath('sources.0.ssoEnabled', false)->assertJsonPath('sources.0.ssoOrigin', 'https://portal.example.test')->assertJsonCount(2, 'sources');
        $keyA = $this->postJson($root.'/tokens', ['name' => 'A', 'sourceId' => $first, 'scopes' => ['clients:sso']])->assertCreated()->json('id');
        $keyB = $this->postJson($root.'/tokens', ['name' => 'B', 'sourceId' => $second, 'scopes' => ['clients:sso']])->assertCreated()->json('id');
        $this->getJson($root)->assertJsonPath('tokens.0.sourceId', $second)->assertJsonPath('tokens.1.sourceId', $first)->assertJsonMissingPath('tokens.0.secret');
        $this->patchJson($root.'/sources/'.$first.'/sso', ['ssoEnabled' => true, 'ssoOrigin' => 'https://portal.example.test', 'projectIds' => [$project->uuid]])->assertOk();
        $this->getJson($root)->assertJsonPath('sources.0.ssoProjectIds.0', $project->uuid)->assertJsonCount(0, 'sources.1.ssoProjectIds');
        $foreign = Project::factory()->create();
        $this->patchJson($root.'/sources/'.$first.'/sso', ['ssoEnabled' => true, 'ssoOrigin' => 'https://portal.example.test', 'projectIds' => [$foreign->uuid]])->assertUnprocessable();
        $this->deleteJson($root.'/tokens/'.$keyA)->assertOk();
        $this->assertNotNull(DB::table('integration_tokens')->where('uuid', $keyA)->value('revoked_at'));
        $this->assertNull(DB::table('integration_tokens')->where('uuid', $keyB)->value('revoked_at'));
        $this->actingAs($this->ownerFor($foreign))->patchJson($root.'/sources/'.$first.'/sso', ['ssoEnabled' => false, 'ssoOrigin' => null, 'projectIds' => []])->assertNotFound();
        $this->postJson($root.'/tokens', ['name' => 'Foreign', 'sourceId' => $first, 'scopes' => ['clients:sso']])->assertUnprocessable();
        $admin = User::factory()->memberOf($project->workspace)->create();
        $this->actingAs($admin)->postJson($root.'/sources', ['name' => 'Denied', 'origin' => 'https://portal.example.test'])->assertForbidden();
    }
}
