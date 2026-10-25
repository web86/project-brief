<?php

namespace Tests\Feature;

use App\Mail\ProjectActivityMail;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationEvents;
use App\Services\ProjectNotificationService;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function tenants(): array
    {
        Storage::fake('local');
        Mail::fake();
        $workspaceA = Workspace::factory()->create(['name' => 'Tenant A']);
        $workspaceB = Workspace::factory()->create(['name' => 'Tenant B']);
        $ownerA = User::factory()->owner($workspaceA)->create(['email' => 'owner-a@example.test']);
        $ownerB = User::factory()->owner($workspaceB)->create(['email' => 'owner-b@example.test']);
        $adminA = User::factory()->memberOf($workspaceA)->create(['email' => 'admin-a@example.test']);
        $adminB = User::factory()->memberOf($workspaceB)->create(['email' => 'admin-b@example.test']);
        $projectA = Project::factory()->for($workspaceA)->create();
        $projectB = Project::factory()->for($workspaceB)->create(['title' => 'PRIVATE-TENANT-B']);
        $extraA = Project::factory()->for($workspaceA)->create(['title' => 'UNASSIGNED-PROJECT']);
        $projectA->members()->create(['workspace_id' => $workspaceA->id, 'workspace_member_id' => $adminA->workspaceMembers()->first()->id]);
        $projectB->members()->create(['workspace_id' => $workspaceB->id, 'workspace_member_id' => $adminB->workspaceMembers()->first()->id]);

        return compact('workspaceA', 'workspaceB', 'ownerA', 'ownerB', 'adminA', 'adminB', 'projectA', 'projectB', 'extraA');
    }

    private function protectedData(Project $project): array
    {
        $task = Task::factory()->for($project)->create(['description' => 'PRIVATE-DESCRIPTION']);
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'private-link')]);
        Storage::disk('local')->put('private/test.png', 'private image');
        $file = $task->attachments()->create(['uploaded_by_type' => 'admin', 'original_name' => 'private.png', 'stored_name' => 'private.png', 'mime_type' => 'image/png', 'size' => 13, 'disk' => 'local', 'path' => 'private/test.png']);

        return compact('task', 'client', 'token', 'file');
    }

    private function assertBlocked(Project $project): void
    {
        ['task' => $task, 'client' => $client, 'token' => $token, 'file' => $file] = $this->protectedData($project);
        $p = '/api/admin/projects/'.$project->uuid;
        $t = '/api/admin/tasks/'.$task->uuid;
        $section = $project->briefSections()->first();
        foreach ([
            ['GET', $p, []], ['PATCH', $p, ['title' => 'Hacked']],
            ['GET', $p.'/clients', []], ['POST', $p.'/clients', ['name' => 'Hacked']],
            ['PATCH', $p.'/clients/'.$client->uuid, ['active' => false]],
            ['POST', $p.'/clients/'.$client->uuid.'/access-links', []],
            ['DELETE', $p.'/clients/'.$client->uuid.'/access-links/'.$token->id, []],
            ['GET', $p.'/sections', []], ['POST', $p.'/sections', ['name' => 'Hacked']],
            ['PATCH', $p.'/sections/'.$section->uuid, ['name' => 'Hacked']],
            ['DELETE', $p.'/sections/'.$section->uuid, []],
            ['GET', $p.'/notifications', []],
            ['PATCH', $p.'/notifications', ['events' => NotificationEvents::defaults(), 'notificationEmail' => 'hacked@example.test']],
            ['POST', $p.'/notifications/test', ['channel' => 'email']],
            ['POST', $p.'/notifications/test', ['channel' => 'push', 'endpoint' => 'https://fcm.googleapis.com/send/private']],
            ['GET', $p.'/tasks', []],
            ['POST', $p.'/tasks', ['title' => 'Hacked', 'location' => 'Main', 'description' => 'Hacked', 'priority' => 'normal']],
            ['GET', $t, []], ['PATCH', $t, ['developerNotes' => 'Hacked']],
            ['PATCH', $t, ['status' => 'done']],
            ['POST', $t.'/move', ['sectionId' => $section->uuid]],
            ['POST', $t.'/reorder', ['direction' => 'up']],
            ['POST', $t.'/comments', ['text' => 'Hacked']],
            ['GET', '/api/admin/attachments/'.$file->uuid.'/download', []],
            ['GET', '/api/admin/attachments/'.$file->uuid.'/preview', []],
        ] as [$method, $url, $data]) {
            $response = $this->json($method, $url, $data)->assertNotFound();
            foreach (['PRIVATE-TENANT-B', 'PRIVATE-DESCRIPTION', $token->token_hash, $file->path, $client->email] as $private) {
                if ($private) {
                    $this->assertStringNotContainsString($private, $response->getContent(), $url);
                }
            }
        }
        $this->post($t.'/attachments', ['attachments' => [UploadedFile::fake()->image('attempt.png')]], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertNotSame('Hacked', $project->fresh()->title);
        $this->assertSame('new', $task->fresh()->status);
        $this->assertTrue($client->fresh()->active);
        $this->assertNull($token->fresh()->revoked_at);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertSame(0, $task->history()->count());
        $this->assertSame(0, $task->comments()->count());
        Mail::assertNothingSent();
    }

    public static function roles(): array
    {
        return [['ownerA'], ['adminA']];
    }

    #[DataProvider('roles')]
    public function test_all_project_task_client_link_structure_attachment_and_notification_surfaces_reject_foreign_tenant(string $actor): void
    {
        $data = $this->tenants();
        $this->actingAs($data[$actor]);
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount($actor === 'ownerA' ? 2 : 1, 'data');
        $this->assertBlocked($data['projectB']);
    }

    public function test_unassigned_admin_cannot_use_any_protected_project_surface_in_the_same_workspace(): void
    {
        $data = $this->tenants();
        $this->actingAs($data['adminA']);
        $this->assertBlocked($data['extraA']);
    }

    public function test_owner_implicit_access_and_two_distinct_admin_assignments_are_reflected_in_lists_and_direct_access(): void
    {
        $data = $this->tenants();
        $other = User::factory()->memberOf($data['workspaceA'])->create();
        $data['extraA']->members()->create(['workspace_id' => $data['workspaceA']->id, 'workspace_member_id' => $other->workspaceMembers()->first()->id]);
        foreach ([[$data['ownerA'], [$data['projectA'], $data['extraA']]], [$data['adminA'], [$data['projectA']]], [$other, [$data['extraA']]]] as [$actor, $visible]) {
            $this->actingAs($actor)->getJson('/api/admin/projects')->assertJsonCount(count($visible), 'data');
            foreach ([$data['projectA'], $data['extraA']] as $project) {
                $this->getJson('/api/admin/projects/'.$project->uuid)->assertStatus(in_array($project, $visible, true) ? 200 : 404);
            }
        }
        $this->assertSame(0, $data['ownerA']->workspaceMembers()->first()->projectMemberships()->count());
    }

    #[DataProvider('roles')]
    public function test_owner_and_assigned_admin_can_edit_tasks_comment_upload_and_read_attachments(string $actor): void
    {
        $data = $this->tenants();
        $project = $data['projectA'];
        $task = Task::factory()->for($project)->create();
        $this->actingAs($data[$actor]);
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk();
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'review', 'developerNotes' => 'Private', 'estimateHours' => 5])->assertOk();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Public'])->assertOk();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/move', ['sectionId' => $project->briefSections()->first()->uuid])->assertOk();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/reorder', ['direction' => 'up'])->assertOk();
        $this->post('/api/admin/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->image('safe.png')]], ['Accept' => 'application/json'])->assertOk();
        $file = $task->attachments()->first();
        $this->getJson('/api/admin/attachments/'.$file->uuid.'/preview')->assertOk();
        $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertOk();
        $this->assertSame(1, $task->history()->where('event_type', 'developer_notes')->count());
    }

    public function test_invited_admin_cannot_create_projects_even_when_supplying_a_workspace_or_permissions(): void
    {
        $data = $this->tenants();
        $this->actingAs($data['adminA']);
        $this->postJson('/api/admin/projects', ['title' => 'Forged', 'workspace_id' => $data['workspaceB']->id])->assertForbidden();
        $this->postJson('/api/admin/projects', ['title' => 'Created by admin', 'permissions' => ['manage_project_settings' => true]])->assertForbidden();
        $this->assertDatabaseCount('projects', 3);
        $this->assertDatabaseCount('project_members', 2);
    }

    public function test_switching_workspace_changes_project_task_and_attachment_scope_even_for_a_multi_workspace_user(): void
    {
        $data = $this->tenants();
        $data['workspaceB']->members()->create(['user_id' => $data['ownerA']->id, 'role' => 'owner', 'status' => 'active']);
        ['task' => $task, 'file' => $file] = $this->protectedData($data['projectB']);
        $this->actingAs($data['ownerA'])->getJson('/api/admin/tasks/'.$task->uuid)->assertNotFound();
        $this->postJson('/api/admin/workspaces/'.$data['workspaceB']->uuid.'/select')->assertOk();
        $this->getJson('/api/admin/projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $data['projectB']->uuid);
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk();
        $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertOk();
        $this->getJson('/api/admin/projects/'.$data['projectA']->uuid)->assertNotFound();
    }

    public function test_client_links_ignore_admin_workspace_selection_and_continue_project_scoped_authorization(): void
    {
        $data = $this->tenants();
        $project = $data['projectB'];
        $plain = bin2hex(random_bytes(32));
        $project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $plain)]);
        $this->actingAs($data['ownerA'])->getJson('/api/admin/me')->assertOk();
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid)->assertSessionMissing('workspace_id');
        $this->assertGuest();
        $this->getJson('/api/session')->assertExactJson(['authenticated' => true, 'type' => 'client', 'project' => ['id' => $project->uuid]]);
        $this->withSession(['workspace_id' => $data['workspaceA']->id])->getJson('/api/client/project/'.$project->uuid)->assertOk();
        $this->getJson('/api/client/project/'.$data['projectA']->uuid)->assertNotFound();
        $this->getJson('/api/admin/projects/'.$project->uuid)->assertUnauthorized();
    }

    public function test_client_activity_notifies_only_project_authorized_active_users_and_their_devices(): void
    {
        $data = $this->tenants();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'brief@example.test']);
        $other = User::factory()->memberOf($data['workspaceA'])->create();
        $inactive = User::factory()->owner($data['workspaceA'])->create();
        $inactive->workspaceMembers()->update(['status' => 'inactive']);
        foreach ([$data['ownerA'], $data['adminA'], $data['ownerB'], $data['adminB'], $other, $inactive] as $user) {
            PushSubscription::factory()->create(['user_id' => $user->id]);
        }
        PushSubscription::factory()->create(['user_id' => $data['ownerA']->id]);
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldReceive('available')->andReturn(true);
        $transport->shouldReceive('send')->times(3)->withArgs(function ($subscription, $payload) use ($data) {
            $this->assertContains($subscription->user_id, [$data['ownerA']->id, $data['adminA']->id]);
            $this->assertSame($data['projectA']->uuid, $payload['project_uuid']);

            return true;
        })->andReturn(['status' => 'sent', 'error_code' => null]);
        $task = Task::factory()->for($data['projectA'])->create();
        $project = $data['projectA'];
        app(ProjectNotificationService::class)->settings($project)->update(['notification_email' => strtoupper($data['ownerB']->email)]);
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'client')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Original public comment'])->assertOk();
        Mail::assertSentCount(2);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($data['ownerA']->email));
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($data['adminA']->email));
        Mail::assertNotSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($data['ownerB']->email) || $mail->hasTo(strtoupper($data['ownerB']->email)));
        $this->assertDatabaseCount('notification_deliveries', 5);
    }

    public function test_external_legacy_mailbox_override_applies_once_to_primary_owner_and_test_email_targets_current_authorized_user(): void
    {
        $data = $this->tenants();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'brief@example.test']);
        app(ProjectNotificationService::class)->settings($data['projectA'])->update(['notification_email' => 'legacy-mailbox@example.test']);
        $this->actingAs($data['ownerA'])->postJson('/api/admin/projects/'.$data['projectA']->uuid.'/notifications/test', ['channel' => 'email'])->assertOk();
        $data['projectA']->members()->first()->update(['permissions' => ['manage_project_notifications' => true]]);
        $this->actingAs($data['adminA'])->postJson('/api/admin/projects/'.$data['projectA']->uuid.'/notifications/test', ['channel' => 'email'])->assertOk();
        Mail::assertSentCount(2);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo('legacy-mailbox@example.test'));
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($data['adminA']->email));
    }
}
