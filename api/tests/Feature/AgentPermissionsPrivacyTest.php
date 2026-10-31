<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\Comment;
use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Models\User;
use App\Services\AgentAccess;
use App\Services\AgentPermissions;
use App\Services\IntegrationTokens;
use App\Services\LiveNotifications;
use App\Services\ProjectExportData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentPermissionsPrivacyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(array $permissions = [], array $scopes = AgentAccess::LEGACY_SCOPES): array
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $data = ['name' => 'Executor', 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid], 'permissions' => array_replace(AgentPermissions::DEFAULTS, $permissions)];
        $id = $this->postJson('/api/admin/integrations/agents', $data)->assertCreated()->json('data.id');
        $agent = ExternalAgent::where('uuid', $id)->firstOrFail();
        $token = $this->postJson('/api/admin/integrations/agents/'.$id.'/tokens', ['scopes' => $scopes])->assertCreated()->json();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$id)->assertOk();
        $task = Task::factory()->for($project)->create(['developer_notes' => 'PRIVATE_NOTES', 'price' => 54321]);
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $id])->assertOk();
        $assignment = TaskAgentAssignment::where('task_id', $task->id)->firstOrFail();

        return [$project, $owner, $agent, $token, $task, $assignment, $data];
    }

    private function headers(array $token, string $key = 'first'): array
    {
        return ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => $key, 'Accept' => 'application/json'];
    }

    private function client(Project $project): void
    {
        $this->app['auth']->forgetGuards();
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', Str::random(40))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    public function test_legacy_comments_inherit_access_from_their_task_without_accepting_conflicting_project_links(): void
    {
        [, , , $token, $task] = $this->fixture(['taskComments' => true, 'taskAttachments' => true], ['agent:tasks:read', 'agent:comments:read', 'agent:attachments:read']);
        $legacy = Comment::create(['task_id' => $task->id, 'author_type' => 'client', 'text' => 'Legacy task conversation']);
        $file = $legacy->attachments()->create(['task_id' => $task->id, 'disk' => 'local', 'path' => 'legacy.txt', 'stored_name' => 'legacy.txt', 'original_name' => 'legacy.txt', 'mime_type' => 'text/plain', 'size' => 4, 'uploaded_by_type' => 'client']);
        Storage::disk('local')->put('legacy.txt', 'file');
        Comment::create(['task_id' => $task->id, 'project_id' => Project::factory()->create()->id, 'author_type' => 'admin', 'text' => 'Conflicting foreign project']);
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/comments', $this->headers($token))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'Legacy task conversation');
        $this->get('/api/v1/agent/attachments/'.$file->uuid.'/download', $this->headers($token))->assertOk();
    }

    public function test_discussion_files_need_both_discussion_and_file_permissions(): void
    {
        [$project, , $agent, $token, $task, , $data] = $this->fixture(['clientDiscussions' => true, 'discussionAttachments' => true], ['agent:discussions:read', 'agent:attachments:read']);
        $comment = Comment::create(['project_id' => $project->id, 'author_type' => 'client', 'text' => 'Discussion']);
        $file = $comment->attachments()->create(['disk' => 'local', 'path' => 'discussion.txt', 'stored_name' => 'discussion.txt', 'original_name' => 'discussion.txt', 'mime_type' => 'text/plain', 'size' => 4, 'uploaded_by_type' => 'client']);
        Storage::disk('local')->put('discussion.txt', 'file');
        $this->getJson('/api/v1/agent/discussions/'.$comment->uuid.'/attachments', $this->headers($token))->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/agent/attachments/'.$file->uuid.'/download', $this->headers($token))->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/attachments', $this->headers($token))->assertNotFound();
        $foreign = Comment::create(['project_id' => Project::factory()->create()->id, 'author_type' => 'admin', 'text' => 'Foreign']);
        $this->getJson('/api/v1/agent/discussions/'.$foreign->uuid.'/attachments', $this->headers($token))->assertNotFound();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => ['clientDiscussions' => false]])->assertOk();
        $this->getJson('/api/v1/agent/attachments/'.$file->uuid.'/download', $this->headers($token))->assertForbidden();
        $this->getJson('/api/v1/agent/discussions/'.$comment->uuid.'/attachments', $this->headers($token))->assertForbidden();
    }

    public function test_existing_assigned_endpoint_stays_assigned_only_with_expanded_project_permission(): void
    {
        [$project, , $agent, $token, , , $data] = $this->fixture(['projectTasks' => true], ['agent:tasks:read', 'agent:projects:tasks:read']);
        $other = Task::factory()->for($project)->create();
        $this->getJson('/api/v1/agent/tasks/'.$other->uuid, $this->headers($token))->assertNotFound();
        $this->getJson('/api/v1/agent/tasks', $this->headers($token))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks', $this->headers($token))->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => ['projectTasks' => false]])->assertOk();
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks', $this->headers($token))->assertForbidden();
        $this->getJson('/api/v1/agent/tasks', $this->headers($token))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_only_owner_can_change_permissions_and_structure_authority_is_required_for_publication(): void
    {
        [$project, , $agent, $token, $task, $assignment, $data] = $this->fixture();
        $id = $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/comments', ['assignmentId' => $assignment->uuid, 'text' => 'Internal'], $this->headers($token))->assertCreated()->json('data.commentId');
        $member = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->workspaceMembers()->first()->id, 'permissions' => ['manage_structure' => false]]);
        $this->actingAs($member);
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.canShareAgentResults', false);
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => ['teamChat' => true]])->assertForbidden();
        $this->postJson('/api/admin/integrations/agents/'.$agent->uuid.'/tokens', ['scopes' => ['agent:profile']])->assertForbidden();
        $body = ['text' => 'Human publication', 'submissionId' => (string) Str::uuid(), 'links' => [], 'attachmentIds' => []];
        $url = '/api/admin/tasks/'.$task->uuid.'/agent-results/'.$id.'/share';
        $this->postJson($url, $body)->assertForbidden();
        $member->workspaceMembers()->update(['is_project_superadmin' => true]);
        $this->postJson($url, $body)->assertCreated();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, $data)->assertForbidden();
        $foreign = User::factory()->owner()->create();
        $this->actingAs($foreign)->postJson($url, [...$body, 'submissionId' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_publication_rejects_foreign_files_and_cannot_change_task_workflow(): void
    {
        [$project, , , $token, $task, $assignment] = $this->fixture();
        $id = $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/comments', ['assignmentId' => $assignment->uuid, 'text' => 'Internal'], $this->headers($token))->assertCreated()->json('data.commentId');
        $other = Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'author_type' => 'admin', 'team_only' => true, 'text' => 'Other private source']);
        $file = $other->attachments()->create(['task_id' => $task->id, 'disk' => 'local', 'path' => 'closed.txt', 'stored_name' => 'closed.txt', 'original_name' => 'closed.txt', 'mime_type' => 'text/plain', 'size' => 4, 'uploaded_by_type' => 'admin']);
        $url = '/api/admin/tasks/'.$task->uuid.'/agent-results/'.$id.'/share';
        $data = ['text' => 'Client draft', 'submissionId' => (string) Str::uuid(), 'links' => [], 'attachmentIds' => [$file->uuid]];
        $this->postJson($url, $data)->assertNotFound();
        $this->postJson($url, [...$data, 'attachmentIds' => [], 'links' => ['resultUrl']])->assertUnprocessable();
        $this->postJson($url, [...$data, 'attachmentIds' => [], 'status' => 'done'])->assertUnprocessable();
        $this->assertSame($task->status, $task->fresh()->status);
        $this->assertFalse($task->fresh()->client_approved);
        $this->assertSame(0, Comment::where('task_id', $task->id)->count());
    }

    public function test_private_results_stay_out_of_client_exports_and_non_agent_read_integrations(): void
    {
        [$project, , , $token, $task, $assignment] = $this->fixture();
        DB::table('webhook_endpoints')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Client integration', 'url' => 'https://example.test/webhook', 'events' => json_encode(['task.client_comment_created', 'project.client_comment_created', 'task.updated']), 'enabled' => true, 'secret' => Crypt::encryptString('qa-signing-secret'), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'PRIVATE_REPORT', 'resultUrl' => 'https://example.test/private'], $this->headers($token))->assertCreated();
        $file = $this->post('/api/v1/agent/tasks/'.$task->uuid.'/attachments', ['assignmentId' => $assignment->uuid, 'attachments' => [UploadedFile::fake()->createWithContent('private-report.txt', 'PRIVATE')]], $this->headers($token, 'file'))->assertCreated()->json('data.attachments.0.id');
        $this->assertDatabaseCount('webhook_deliveries', 0);
        $integration = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Human integration', 'scopes' => ['projects:read', 'comments:read', 'attachments:read']]);
        $headers = ['Authorization' => 'Bearer '.$integration['secret']];
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/attachments/'.$file.'/download', $headers)->assertNotFound();
        $export = app(ProjectExportData::class)->build($project, 'all', 'en', ['attachments' => true], client: true);
        foreach (['PRIVATE_REPORT', 'private-report.txt', 'https://example.test/private'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($export, JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_defaults_preserve_old_scopes_without_adding_new_access_to_old_tokens(): void
    {
        [$project, , $agent, $token, $task, , $data] = $this->fixture();
        $this->assertSame(AgentPermissions::DEFAULTS, $agent->permissions);
        $this->postJson('/api/admin/integrations/agents/'.$agent->uuid.'/tokens', ['scopes' => AgentAccess::SCOPES])->assertUnprocessable();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => array_fill_keys(array_keys(AgentPermissions::DEFAULTS), true)])->assertOk();
        foreach (['projects/'.$project->uuid.'/tasks', 'tasks/'.$task->uuid.'/comments', 'projects/'.$project->uuid.'/discussions', 'projects/'.$project->uuid.'/team-chat', 'tasks/'.$task->uuid.'/attachments'] as $path) {
            $this->getJson('/api/v1/agent/'.$path, $this->headers($token))->assertForbidden();
        }
        $this->assertSame(AgentAccess::LEGACY_SCOPES, json_decode(DB::table('integration_tokens')->where('uuid', $token['id'])->value('scopes'), true));
    }

    public function test_extended_tasks_read_is_separate_from_assigned_read_and_never_authorizes_writes(): void
    {
        [$project, , , $token, $task, $assignment] = $this->fixture(['projectTasks' => true], ['agent:projects:tasks:read']);
        $other = Task::factory()->for($project)->create();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid, $this->headers($token))->assertForbidden();
        $list = $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks', $this->headers($token))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.perPage', 25);
        foreach (['PRIVATE_NOTES', '54321', 'email', 'phone', 'developerNotes', 'price', 'clientName'] as $secret) {
            $this->assertStringNotContainsString($secret, $list->getContent());
        }
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks/'.$other->uuid, $this->headers($token))->assertOk();
        $this->postJson('/api/v1/agent/tasks/'.$other->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'Denied'], $this->headers($token))->assertForbidden();
        $this->assertDatabaseHas('agent_operations', ['project_uuid' => $project->uuid, 'task_uuid' => $other->uuid, 'http_status' => 200]);
        $foreign = Task::factory()->create();
        $this->getJson('/api/v1/agent/projects/'.$foreign->project->uuid.'/tasks', $this->headers($token))->assertNotFound();
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks/'.$foreign->uuid, $this->headers($token))->assertNotFound();
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks?page=0', $this->headers($token))->assertUnprocessable();
    }

    public function test_comments_team_chat_and_file_permissions_require_parent_access_and_separate_scopes(): void
    {
        [$project, , $agent, $token, $task, , $data] = $this->fixture(array_fill_keys(array_keys(AgentPermissions::DEFAULTS), true), AgentAccess::SCOPES);
        $public = Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'author_type' => 'admin', 'text' => 'Task public']);
        $internal = Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'team_only' => true, 'author_type' => 'agent', 'external_agent_id' => $agent->id, 'text' => 'Internal task result']);
        $discussion = Comment::create(['project_id' => $project->id, 'author_type' => 'client', 'text' => 'Client discussion']);
        $team = Comment::create(['project_id' => $project->id, 'team_only' => true, 'author_type' => 'admin', 'text' => 'Sensitive team chat']);
        $file = $internal->attachments()->create(['task_id' => $task->id, 'disk' => 'local', 'path' => 'internal.txt', 'original_name' => 'internal.txt', 'stored_name' => 'internal.txt', 'mime_type' => 'text/plain', 'size' => 5, 'uploaded_by_type' => 'agent']);
        Storage::disk('local')->put('internal.txt', 'secret');
        $headers = $this->headers($token);
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/comments', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/discussions', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'Client discussion');
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/team-chat', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'Sensitive team chat');
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/attachments', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/agent/attachments/'.$file->uuid.'/download', $headers)->assertOk();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => ['teamChat' => false]])->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/comments', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'Task public');
        $this->getJson('/api/v1/agent/attachments/'.$file->uuid.'/download', $headers)->assertNotFound();
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/team-chat', $headers)->assertForbidden();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => ['taskComments' => false]])->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/comments', $headers)->assertForbidden();
        $this->assertFalse($agent->fresh()->permissions['teamChat']);
        $this->assertTrue($agent->fresh()->permissions['projectTasks']);
    }

    public function test_grants_membership_and_registration_gate_extended_read_even_with_all_scopes(): void
    {
        [$project, , $agent, $token, , , $data] = $this->fixture(['projectTasks' => true], ['agent:projects:tasks:read']);
        $url = '/api/v1/agent/projects/'.$project->uuid.'/tasks';
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $this->getJson($url, $this->headers($token))->assertNotFound();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $this->getJson($url, $this->headers($token))->assertOk();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'projectIds' => []])->assertOk();
        $this->getJson($url, $this->headers($token))->assertNotFound();
    }

    public function test_registration_write_revocation_blocks_existing_token_and_idempotent_replay(): void
    {
        [, , $agent, $token, $task, $assignment, $data] = $this->fixture();
        $body = ['assignmentId' => $assignment->uuid, 'summary' => 'Internal', 'state' => 'submitted'];
        $url = '/api/v1/agent/tasks/'.$task->uuid.'/reports';
        $this->postJson($url, $body, $this->headers($token))->assertCreated();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$data, 'permissions' => ['reports' => false]])->assertOk();
        $this->postJson($url, $body, $this->headers($token))->assertForbidden();
        $this->postJson($url, $body, $this->headers($token, 'new-key'))->assertForbidden();
        $this->assertDatabaseCount('agent_reports', 1);
        $this->postJson('/api/admin/integrations/agents/'.$agent->uuid.'/tokens', ['scopes' => ['agent:tasks:report']])->assertUnprocessable();
    }

    public function test_new_agent_results_are_internal_but_history_stays_public_and_unmodified(): void
    {
        [$project, $owner, $agent, $token, $task, $assignment] = $this->fixture();
        $legacy = Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'author_type' => 'agent', 'external_agent_id' => $agent->id, 'text' => 'Historical public agent comment', 'team_only' => false]);
        Event::fake([ProjectNotificationRequested::class]);
        $seed = app(LiveNotifications::class)->seed();
        $report = $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'INTERNAL_SUMMARY', 'resultUrl' => 'https://example.test/internal'], $this->headers($token))->assertCreated();
        $file = $this->post('/api/v1/agent/tasks/'.$task->uuid.'/attachments', ['assignmentId' => $assignment->uuid, 'attachments' => [UploadedFile::fake()->createWithContent('internal.txt', 'PRIVATE_FILE')]], $this->headers($token, 'file'))->assertCreated()->json('data.attachments.0.id');
        Event::assertNotDispatched(ProjectNotificationRequested::class);
        $admin = $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonCount(3, 'data.comments')->assertJsonPath('data.comments.1.teamOnly', true)->assertJsonPath('data.comments.2.attachments.0.id', $file);
        $this->assertStringContainsString('INTERNAL_SUMMARY', $admin->getContent());
        $this->get('/api/admin/attachments/'.$file.'/download')->assertOk();
        $this->getJson('/api/admin/projects/'.$project->uuid.'/discussions?kind=tasks')->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/admin/projects/'.$project->uuid.'/discussions/read', ['ids' => [$report->json('data.commentId')]])->assertOk();
        $this->assertSame(0, DB::table('webhook_deliveries')->count());
        DB::table('project_notification_settings')->where('project_id', $project->id)->delete();
        $client = $project->clients()->first();
        $client->forceFill(['notification_preferences' => ['push' => true]])->save();
        $request = Request::create('/api/client/live');
        $request->attributes->set('client_project', $project);
        $request->attributes->set('project_client', $client);
        $this->assertSame([], app(LiveNotifications::class)->changes($request, $seed, null)['items']);
        $this->client($project);
        $response = $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.text', 'Historical public agent comment');
        foreach (['INTERNAL_SUMMARY', 'internal.txt', 'https://example.test/internal', 'agent_reports', 'agent_attachments'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson('/api/client/project/'.$project->uuid.'/discussions?kind=tasks')->assertOk()->assertJsonCount(1, 'data');
        foreach (['download', 'preview', 'original'] as $mode) {
            $this->getJson('/api/client/attachments/'.$file.'/'.$mode)->assertNotFound();
        }
        $this->assertFalse($legacy->fresh()->team_only);
    }

    public function test_share_creates_a_human_copy_and_only_explicit_links_and_files_are_published(): void
    {
        [$project, $owner, , $token, $task, $assignment] = $this->fixture();
        $id = $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'Secret technical detail', 'resultUrl' => 'https://example.test/private-result', 'pullRequestUrl' => 'https://example.test/pull/1'], $this->headers($token))->assertCreated()->json('data.commentId');
        $source = Comment::withoutGlobalScope('public_channel')->where('uuid', $id)->firstOrFail();
        $file = $source->attachments()->create(['task_id' => $task->id, 'disk' => 'local', 'path' => 'result.txt', 'stored_name' => 'result.txt', 'original_name' => 'result.txt', 'mime_type' => 'text/plain', 'size' => 4, 'uploaded_by_type' => 'agent']);
        Storage::disk('local')->put('result.txt', 'file');
        $url = '/api/admin/tasks/'.$task->uuid.'/agent-results/'.$id.'/share';
        $data = ['text' => 'Reviewed client result', 'links' => [], 'attachmentIds' => [], 'submissionId' => (string) Str::uuid()];
        $copy = $this->postJson($url, $data)->assertCreated()->json('data.id');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.id', $copy);
        $this->postJson($url, [...$data, 'text' => 'Changed'])->assertConflict();
        $selected = [...$data, 'links' => ['pullRequestUrl'], 'attachmentIds' => [$file->uuid], 'submissionId' => (string) Str::uuid()];
        $second = $this->postJson($url, $selected)->assertCreated()->json('data.id');
        $published = Comment::where('uuid', $second)->firstOrFail();
        $this->assertNotSame($file->path, $published->attachments->first()->path);
        $this->assertTrue($source->fresh()->team_only);
        $this->assertSame('Secret technical detail', $source->fresh()->text);
        $this->assertSame($owner->id, $published->author_user_id);
        $this->client($project);
        $response = $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertJsonCount(2, 'data.comments');
        $this->assertStringNotContainsString('Secret technical detail', $response->getContent());
        $this->assertStringNotContainsString('private-result', $response->getContent());
        $this->assertStringContainsString('https://example.test/pull/1', json_encode($response->json(), JSON_UNESCAPED_SLASHES));
        $this->get('/api/client/attachments/'.$published->attachments->first()->uuid.'/download')->assertOk();
        $this->getJson('/api/client/attachments/'.$file->uuid.'/download')->assertNotFound();
        $this->postJson($url, $data)->assertUnauthorized();
    }
}
