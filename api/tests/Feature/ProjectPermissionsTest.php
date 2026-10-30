<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationEvents;
use App\Services\ProjectPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectPermissionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Project $project;

    private User $owner;

    private User $developer;

    private ProjectMember $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create();
        $this->project->clients()->first()->update(['email' => 'private-client@example.test']);
        $this->owner = $this->ownerFor($this->project);
        $this->developer = User::factory()->memberOf($this->project->workspace)->create();
        $this->assignment = $this->project->members()->create(['workspace_id' => $this->project->workspace_id, 'workspace_member_id' => $this->developer->workspaceMembers()->first()->id]);
    }

    private function root(?Project $project = null): string
    {
        return '/api/admin/projects/'.($project ?? $this->project)->uuid;
    }

    private function savePermissions(array $permissions, ?Project $other = null): void
    {
        $projects = [$this->project->uuid];
        if ($other) {
            $projects[] = $other->uuid;
        }
        $this->actingAs($this->owner)->patchJson('/api/admin/administrators/members/'.$this->assignment->workspace_member_id, [
            'projects' => $projects, 'projectPermissions' => [$this->project->uuid => $permissions],
        ])->assertOk();
        $this->actingAs($this->developer);
    }

    public function test_null_legacy_and_new_memberships_use_safe_defaults_and_owner_has_all_permissions(): void
    {
        $this->assertNull($this->assignment->fresh()->permissions);
        $this->actingAs($this->developer)->getJson($this->root())->assertJsonPath('data.permissions', ProjectPermissions::DEFAULTS);
        $this->getJson('/api/admin/projects')->assertJsonPath('data.0.permissions', ProjectPermissions::DEFAULTS);
        $this->actingAs($this->owner)->getJson($this->root())->assertJsonPath('data.permissions', array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), true));
        $this->getJson('/api/admin/administrators')->assertJsonPath('members.1.projectPermissions.'.$this->project->uuid, ProjectPermissions::DEFAULTS);
        $this->assertDatabaseCount('project_members', 1);
    }

    public function test_every_protected_endpoint_denies_before_returning_personal_data_or_mutating_state(): void
    {
        $client = $this->project->clients()->first();
        $token = $this->project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'test')]);
        $task = Task::factory()->for($this->project)->create();
        $task->delete();
        $section = $this->project->briefSections()->first();
        $this->savePermissions(array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false));
        foreach ([
            ['GET', '/clients', []], ['POST', '/clients', ['name' => 'Denied']],
            ['PATCH', '/clients/'.$client->uuid, ['active' => false]],
            ['POST', '/clients/'.$client->uuid.'/access-links', []],
            ['DELETE', '/clients/'.$client->uuid.'/access-links/'.$token->id, []],
            ['PATCH', '', ['title' => 'Denied']],
            ['GET', '/notifications', []], ['PATCH', '/notifications', ['events' => NotificationEvents::defaults()]],
            ['POST', '/notifications/test', ['channel' => 'email']],
            ['POST', '/export', ['format' => 'pdf', 'scope' => 'all', 'locale' => 'en', 'fields' => ['developerNotes' => true]]],
            ['POST', '/export', ['format' => 'docx', 'scope' => 'all', 'locale' => 'en']],
            ['POST', '/sections', ['name' => 'Denied']],
            ['PATCH', '/sections/'.$section->uuid, ['direction' => 'down']],
            ['DELETE', '/sections/'.$section->uuid, []], ['GET', '/trash', []],
        ] as [$method, $path, $body]) {
            $response = $this->json($method, $this->root().$path, $body)->assertForbidden()->assertJsonPath('code', 'project_permission_denied');
            $this->assertStringNotContainsString($client->email, $response->getContent());
        }
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/restore')->assertForbidden()->assertJsonPath('code', 'project_permission_denied');
        $this->assertTrue($task->fresh()->trashed());
        $this->assertNull($token->fresh()->revoked_at);
        $this->assertTrue($client->fresh()->active);
        $this->assertNotSame('Denied', $this->project->fresh()->title);
        $this->getJson($this->root().'/sections')->assertOk();
    }

    public static function abilities(): array
    {
        return array_map(fn ($key) => [$key], ['manage_structure', 'export_project']);
    }

    #[DataProvider('abilities')]
    public function test_owner_can_enable_each_ability_independently_and_revoke_it_during_the_same_session(string $ability): void
    {
        $permissions = array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false);
        $permissions[$ability] = true;
        $this->savePermissions($permissions);
        $this->getJson($this->root())->assertJsonPath('data.permissions', $permissions);
        $section = $this->project->briefSections()->first();
        Task::factory()->for($this->project)->create();
        [$method, $path, $body] = match ($ability) {
            'manage_clients' => ['POST', $this->root().'/clients', ['name' => 'Allowed']],
            'manage_structure' => ['PATCH', $this->root().'/sections/'.$section->uuid, ['name' => 'Allowed']],
            'manage_project_settings' => ['PATCH', $this->root(), ['title' => 'Allowed']],
            'manage_project_notifications' => ['PATCH', $this->root().'/notifications', ['events' => NotificationEvents::defaults()]],
            'export_project' => ['POST', $this->root().'/export', ['format' => 'docx', 'scope' => 'all', 'locale' => 'en', 'fields' => ['developerNotes' => true]]],
            'manage_trash' => ['GET', $this->root().'/trash', []],
        };
        $this->json($method, $path, $body)->assertSuccessful();
        $this->savePermissions(array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false));
        $this->json($method, $path, $body)->assertForbidden();
        $this->getJson($this->root().'/team')->assertForbidden();
        $this->postJson('/api/admin/projects', ['title' => 'Denied'])->assertForbidden();
    }

    public function test_two_projects_have_independent_permissions_and_removed_assignment_is_not_resurrected(): void
    {
        $other = Project::factory()->for($this->project->workspace)->create();
        $this->savePermissions(['manage_clients' => true], $other);
        $this->getJson($this->root().'/clients')->assertForbidden();
        $this->getJson($this->root($other).'/clients')->assertForbidden();
        $this->getJson($this->root($other))->assertJsonPath('data.permissions', ProjectPermissions::DEFAULTS);
        $this->actingAs($this->owner)->patchJson('/api/admin/administrators/members/'.$this->assignment->workspace_member_id, ['projects' => [$other->uuid]])->assertOk();
        $this->actingAs($this->developer)->getJson($this->root())->assertNotFound();
        $this->getJson('/api/admin/projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->uuid);
        $this->getJson($this->root($other).'/tasks')->assertOk();
        $foreign = Project::factory()->create();
        $foreign->workspace->members()->create(['user_id' => $this->developer->id, 'role' => 'admin', 'status' => 'active']);
        $this->postJson('/api/admin/workspaces/'.$foreign->workspace->uuid.'/select')->assertOk();
        $this->getJson($this->root($other))->assertNotFound();
        $this->getJson($this->root($foreign))->assertNotFound();
    }

    public function test_owner_update_rejects_unknown_permissions_roles_non_boolean_and_unassigned_projects(): void
    {
        $path = '/api/admin/administrators/members/'.$this->assignment->workspace_member_id;
        $this->actingAs($this->owner);
        foreach ([['platform_admin' => true], ['manage_clients' => 'true'], ['manage_clients' => 1], ['create_workspace' => true]] as $permissions) {
            $this->patchJson($path, ['projects' => [$this->project->uuid], 'projectPermissions' => [$this->project->uuid => $permissions]])->assertUnprocessable();
        }
        $foreign = Project::factory()->create();
        $this->patchJson($path, ['projects' => [$this->project->uuid], 'projectPermissions' => [$foreign->uuid => ['manage_clients' => true]]])->assertUnprocessable();
        $this->patchJson($path, ['projects' => [$foreign->uuid]])->assertUnprocessable();
        $this->patchJson($path, ['projects' => [$this->project->uuid], 'role' => 'owner'])->assertUnprocessable();
        $this->patchJson('/api/admin/administrators/members/'.$this->owner->workspaceMembers()->first()->id, ['projects' => []])->assertForbidden();
        $this->actingAs($this->developer)->patchJson($path, ['projects' => [$this->project->uuid], 'projectPermissions' => [$this->project->uuid => ['manage_clients' => true]]])->assertForbidden();
        $this->getJson($this->root())->assertJsonPath('data.permissions', ProjectPermissions::DEFAULTS);
        $this->assignment->update(['permissions' => ['manage_team' => true, 'manage_clients' => 'true']]);
        $this->getJson($this->root())->assertJsonPath('data.permissions', ProjectPermissions::DEFAULTS);
    }

    public function test_ordinary_task_comments_attachments_and_personal_settings_remain_available_without_any_permissions(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        Storage::fake('local');
        $this->savePermissions(array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false));
        $name = $this->project->briefSections()->first()->name;
        $payload = ['title' => 'Idea', 'location' => $name, 'section' => $name, 'description' => 'Work', 'priority' => 'normal'];
        $uuid = $this->postJson($this->root().'/tasks', $payload)->assertCreated()->json('data.id');
        $taskPath = '/api/admin/tasks/'.$uuid;
        $this->patchJson($taskPath, ['status' => 'review', 'developerNotes' => 'Work notes'])->assertOk();
        $this->postJson($taskPath.'/comments', ['text' => 'Comment preserved'])->assertOk();
        $this->post($taskPath.'/attachments', ['attachments' => [UploadedFile::fake()->image('safe.png')]], ['Accept' => 'application/json'])->assertOk();
        Event::assertDispatched(ProjectNotificationRequested::class);
        $this->postJson($this->root().'/tasks', [...$payload, 'section' => 'Unauthorized new section'])->assertForbidden();
        $this->postJson($this->root().'/tasks', [...$payload, 'section' => mb_strtolower($name)])->assertForbidden();
        $this->patchJson($taskPath, ['section' => 'Unauthorized new section'])->assertForbidden();
        $this->getJson($this->root().'/tasks')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/account')->assertOk();
        $this->getJson('/api/admin/notifications')->assertOk();
        $this->patchJson($taskPath, ['status' => 'new'])->assertOk();
        $this->deleteJson($taskPath)->assertOk();
        $this->assignment->workspaceMember->update(['is_project_superadmin' => true]);
        $this->postJson($taskPath.'/restore')->assertOk()->assertJsonPath('data.comments.0.text', 'Comment preserved')->assertJsonCount(1, 'data.attachments');
        $this->assignment->workspaceMember->update(['status' => 'inactive']);
        $this->getJson($this->root().'/trash')->assertForbidden();
    }

    public function test_legacy_creation_grant_and_new_superadministrator_never_allow_project_creation(): void
    {
        $path = '/api/admin/administrators/members/'.$this->assignment->workspace_member_id;
        $this->actingAs($this->owner)->patchJson($path, ['projects' => [$this->project->uuid], 'canCreateProjects' => true, 'isProjectSuperadmin' => true])->assertOk();
        $this->actingAs($this->developer)->getJson('/api/admin/me')->assertJsonPath('user.canCreateProjects', false)->assertJsonPath('user.isProjectSuperadmin', true);
        $this->postJson('/api/admin/projects', ['title' => 'Denied'])->assertForbidden();
        $this->patchJson($path, ['projects' => [], 'isProjectSuperadmin' => true])->assertForbidden();
        $this->actingAs($this->owner)->patchJson($path, ['projects' => [], 'isProjectSuperadmin' => true])->assertOk();
        $this->actingAs($this->developer)->getJson('/api/admin/projects')->assertJsonCount(0, 'data');
        $this->postJson('/api/admin/projects', ['title' => 'Still denied'])->assertForbidden();
        $this->getJson($this->root())->assertNotFound();
    }

    public function test_empty_project_can_receive_ideas_in_the_system_general_section_without_structure_rights(): void
    {
        $this->project->briefSections()->delete();
        $this->savePermissions(array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false));
        $data = ['title' => 'First idea', 'location' => 'Общее', 'section' => 'Общее', 'description' => 'Normal work', 'priority' => 'normal'];
        $this->postJson($this->root().'/tasks', [...$data, 'section' => 'Arbitrary section'])->assertForbidden();
        $this->postJson($this->root().'/tasks', $data)->assertCreated();
        $this->getJson($this->root().'/sections')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Общее');
        $this->postJson($this->root().'/sections', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_repeat_invitation_preserves_active_grants_but_removed_developer_returns_with_safe_defaults(): void
    {
        $this->assignment->workspaceMember->update(['can_create_projects' => true]);
        $this->assignment->update(['permissions' => ['manage_clients' => true]]);
        $accept = function (): void {
            $token = bin2hex(random_bytes(32));
            DeveloperInvitation::factory()->create([
                'workspace_id' => $this->project->workspace_id, 'invited_by' => $this->owner->id,
                'email' => $this->developer->email, 'project_ids' => [$this->project->id],
                'token_hash' => hash('sha256', $token),
            ]);
            $this->actingAs($this->developer)->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
        };
        $accept();
        $this->getJson('/api/admin/me')->assertJsonPath('user.canCreateProjects', false);
        $this->getJson($this->root())->assertJsonPath('data.permissions.manage_clients', false);
        $this->actingAs($this->owner)->deleteJson('/api/admin/administrators/members/'.$this->assignment->workspace_member_id)->assertOk();
        $this->assertDatabaseMissing('workspace_members', ['id' => $this->assignment->workspace_member_id]);
        $accept();
        $this->getJson('/api/admin/me')->assertJsonPath('user.canCreateProjects', false);
        $this->getJson($this->root())->assertJsonPath('data.permissions', ProjectPermissions::DEFAULTS);
    }
}
