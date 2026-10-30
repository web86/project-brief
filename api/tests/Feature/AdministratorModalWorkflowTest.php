<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdministratorModalWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ROOT = '/api/admin/administrators';

    public function test_manual_link_is_one_time_rotatable_scoped_and_does_not_claim_mailbox_verification(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $created = $this->actingAs($owner)->postJson(self::ROOT.'/invitations', ['name' => 'Manual administrator', 'email' => 'manual@example.test', 'sendEmail' => false, 'generateLink' => true, 'isProjectSuperadmin' => true, 'projects' => [$project->uuid], 'projectPermissions' => [$project->uuid => ['manage_clients' => true]]])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json();
        parse_str(parse_url($created['link'], PHP_URL_FRAGMENT), $fragment);
        $old = $fragment['token'];
        $invitation = DeveloperInvitation::where('uuid', $created['id'])->firstOrFail();
        $this->assertNull($invitation->sent_at);
        $this->assertSame(hash('sha256', $old), $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertStringNotContainsString($old, $this->getJson(self::ROOT)->assertOk()->getContent());
        $next = $this->postJson(self::ROOT.'/invitations/'.$invitation->uuid.'/link')->assertOk()->json('link');
        parse_str(parse_url($next, PHP_URL_FRAGMENT), $fragment);
        $token = $fragment['token'];
        $this->postJson('/api/invitations/resolve', ['token' => $old])->assertGone();
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Manual administrator', 'password' => 'New-manual-password', 'password_confirmation' => 'New-manual-password'])->assertOk();
        $user = User::where('email', 'manual@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->getJson('/api/admin/me')->assertJsonPath('user.canCreateProjects', false)->assertJsonPath('user.isProjectSuperadmin', true);
        $this->getJson('/api/admin/projects/'.$project->uuid)->assertOk()->assertJsonPath('data.permissions.manage_clients', true);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertGone();
        $this->actingAs($owner)->postJson(self::ROOT.'/invitations/'.$invitation->uuid.'/link')->assertGone();
    }

    public function test_status_edit_retains_permissions_and_mfa_then_deletion_physically_removes_only_membership(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->owner($project->workspace)->create();
        $member = $admin->workspaceMembers()->firstOrFail();
        $member->update(['role' => 'admin', 'can_create_projects' => true]);
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id, 'permissions' => ['manage_clients' => true]]);
        $other = Project::factory()->create();
        $elsewhere = $other->workspace->members()->create(['user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $snapshot = $admin->fresh()->getAttributes();
        $keys = $admin->passkeys()->get()->toArray();
        $this->actingAs($owner)->patchJson(self::ROOT.'/members/'.$member->id, ['name' => 'Local name', 'status' => 'inactive', 'projects' => [$project->uuid]])->assertOk();
        $this->assertSame('inactive', $member->fresh()->status);
        $this->assertSame(['manage_clients' => true], $member->projectMemberships()->firstOrFail()->permissions);
        $this->actingAs($admin->fresh())->withSession(['workspace_id' => $project->workspace_id])->getJson('/api/admin/projects/'.$project->uuid)->assertForbidden();
        $this->actingAs($owner)->patchJson(self::ROOT.'/members/'.$member->id, ['status' => 'active', 'projects' => [$project->uuid]])->assertOk();
        $this->deleteJson(self::ROOT.'/members/'.$member->id)->assertOk();
        $this->assertDatabaseMissing('workspace_members', ['id' => $member->id]);
        $this->assertDatabaseMissing('project_members', ['workspace_member_id' => $member->id]);
        $this->assertSame($snapshot, $admin->fresh()->getAttributes());
        $this->assertSame($keys, $admin->passkeys()->get()->toArray());
        $this->assertNotNull($elsewhere->fresh());
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->deleteJson(self::ROOT.'/members/'.$owner->workspaceMembers()->first()->id)->assertForbidden();
    }

    public function test_platform_administrator_and_legacy_admin_role_do_not_grant_workspace_owner_operations(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id]);
        foreach ([false, true] as $platform) {
            $administrator = User::factory()->owner($project->workspace)->create(['platform_admin' => $platform, 'role' => $platform ? 'admin' : 'user']);
            $member = $administrator->workspaceMembers()->firstOrFail();
            $member->update(['role' => 'admin', 'can_create_projects' => true]);
            $this->actingAs($administrator->fresh())->getJson(self::ROOT)->assertForbidden();
            $this->postJson(self::ROOT.'/invitations/'.$invitation->uuid.'/link')->assertForbidden();
            $this->deleteJson(self::ROOT.'/members/'.$owner->workspaceMembers()->first()->id)->assertForbidden();
        }
    }

    public function test_link_and_status_actions_require_current_owner_and_fresh_step_up(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id]);
        $path = self::ROOT.'/invitations/'.$invitation->uuid.'/link';
        $this->actingAs($admin)->postJson($path)->assertForbidden();
        $foreign = Project::factory()->create();
        $this->actingAs($this->ownerFor($foreign))->postJson($path)->assertNotFound();
        $this->actingAs($owner);
        $this->travel(301)->seconds();
        $this->postJson($path)->assertStatus(423)->assertJsonPath('mutationNotStarted', true);
        $this->patchJson(self::ROOT.'/members/'.$admin->workspaceMembers()->first()->id, ['status' => 'active', 'projects' => []])->assertStatus(423);
    }
}
