<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Models\User;
use App\Services\AgentAccess;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(array $scopes = AgentAccess::SCOPES, bool $webhook = false): array
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        if ($webhook) {
            $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'agent.example.com', 'ip' => '93.184.216.34']);
        }
        $data = ['name' => 'Codex QA', 'description' => 'External executor', 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid], 'webhookUrl' => $webhook ? 'https://agent.example.com/events' : null];
        $result = $this->postJson('/api/admin/integrations/agents', $data)->assertCreated();
        $agent = ExternalAgent::where('uuid', $result->json('data.id'))->firstOrFail();
        $token = $this->postJson('/api/admin/integrations/agents/'.$agent->uuid.'/tokens', ['scopes' => $scopes])->assertCreated()->json();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $task = Task::factory()->for($project)->create(['description' => 'Allowed description', 'developer_notes' => 'PRIVATE-NOTES', 'price' => 12345]);
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent->uuid])->assertOk();
        $assignment = TaskAgentAssignment::where('task_id', $task->id)->firstOrFail();

        return [$project, $owner, $agent, $token, $task, $assignment, $data, $result->json('webhookSecret')];
    }

    private function headers(array $token, string $key = 'report-1'): array
    {
        return ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => $key];
    }

    public function test_identity_permissions_minimal_data_and_function_boundaries(): void
    {
        [$project, , $agent, $token, $task] = $this->fixture();
        $headers = $this->headers($token);
        $this->getJson('/api/v1/agent/me', $headers)->assertOk()->assertJsonPath('data.id', $agent->uuid)->assertJsonPath('data.scopes', AgentAccess::SCOPES);
        $this->getJson('/api/v1/agent/tasks', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.perPage', 25);
        $response = $this->getJson('/api/v1/agent/tasks/'.$task->uuid, $headers)->assertOk()->assertJsonPath('data.description', 'Allowed description');
        foreach (['PRIVATE-NOTES', '12345', 'clientEmail', 'developerNotes', 'attachments', 'comments', $project->client_email ?? 'NEVER-IN-RESPONSE'] as $private) {
            if ($private !== '') {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        $unassigned = Task::factory()->for($project)->create();
        $other = Task::factory()->create();
        foreach ([$unassigned, $other] as $denied) {
            $this->getJson('/api/v1/agent/tasks/'.$denied->uuid, $headers)->assertNotFound();
        }
        foreach (['/api/v1/projects', '/api/v1/projects/'.$project->uuid.'/comments', '/api/v1/attachments/unknown/download', '/api/v1/project-requests'] as $path) {
            $this->getJson($path, $headers)->assertForbidden();
        }
        $this->postJson('/api/v1/projects', ['title' => 'Denied'], $headers)->assertForbidden();
        $this->getJson('/api/v1/agent/tasks?page=0', $headers)->assertUnprocessable();
        $this->assertDatabaseHas('agent_operations', ['external_agent_id' => $agent->id, 'http_status' => 404]);
        $this->assertGreaterThan(0, $agent->fresh()->successful_requests);
        $this->assertGreaterThan(0, $agent->fresh()->failed_requests);
        $this->assertNotNull($agent->fresh()->last_success_at);
    }

    public function test_results_comments_files_and_idempotency_preserve_task_workflow_and_authorship(): void
    {
        Storage::fake('local');
        [$project, $owner, $agent, $token, $task, $assignment] = $this->fixture();
        $base = '/api/v1/agent/tasks/'.$task->uuid;
        $body = ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => '<script>untrusted</script> Test completed', 'pullRequestUrl' => 'https://github.com/example/repo/pull/1'];
        $first = $this->postJson($base.'/reports', $body, $this->headers($token))->assertCreated();
        $this->postJson($base.'/reports', $body, $this->headers($token))->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($base.'/reports', [...$body, 'summary' => 'Different'], $this->headers($token))->assertConflict();
        $this->postJson($base.'/reports', $body, ['Authorization' => 'Bearer '.$token['secret']])->assertUnprocessable();
        $this->postJson($base.'/reports', [...$body, 'state' => 'done'], $this->headers($token, 'invalid-state'))->assertUnprocessable();
        $this->postJson($base.'/reports', [...$body, 'status' => 'done'], $this->headers($token, 'escalate'))->assertUnprocessable();
        $this->postJson($base.'/reports', [...$body, 'resultUrl' => 'javascript:alert(1)'], $this->headers($token, 'unsafe-url'))->assertUnprocessable();
        $this->postJson($base.'/reports', [...$body, 'resultUrl' => 'https://secret@example.com/result'], $this->headers($token, 'url-credentials'))->assertUnprocessable();
        $this->postJson($base.'/comments', ['assignmentId' => $assignment->uuid, 'text' => 'Review please'], $this->headers($token, 'comment'))->assertCreated();
        $files = ['assignmentId' => $assignment->uuid, 'attachments' => [UploadedFile::fake()->create('result.txt', 2, 'text/plain')]];
        $file = $this->post($base.'/attachments', $files, $this->headers($token, 'files') + ['Accept' => 'application/json'])->assertCreated();
        $this->post($base.'/attachments', $files, $this->headers($token, 'files') + ['Accept' => 'application/json'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('agent_reports', 1);
        $this->assertDatabaseCount('comments', 3);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertSame($task->status, $task->fresh()->status);
        $this->assertFalse($task->fresh()->client_approved);
        $state = $this->actingAs($owner)->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.comments.0.author', 'agent')->assertJsonPath('data.comments.0.authorName', $agent->name)->assertJsonPath('data.agentAssignments.0.state', 'submitted');
        $this->get('/api/admin/attachments/'.$file->json('data.attachments.0.id').'/download')->assertOk();
        $this->assertDatabaseHas('task_history', ['actor_type' => 'agent', 'event_type' => 'agent_reports']);
        $this->deleteJson('/api/admin/integrations/agents/'.$agent->uuid)->assertOk();
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.comments.0.authorName', $agent->name)->assertJsonPath('data.comments.0.agentReport.pullRequestUrl', $body['pullRequestUrl']);
        $audit = json_decode(DB::table('task_history')->where('event_type', 'agent_reports')->value('meta'), true);
        $this->assertSame($agent->uuid, $audit['agentId']);
        $this->assertSame($token['id'], $audit['credentialId']);
        $this->assertStringNotContainsString($token['secret'], json_encode(DB::table('agent_operations')->get()));
        $this->assertStringNotContainsString($token['secret'], $this->getJson('/api/admin/integrations/agents')->getContent());
    }

    public function test_scopes_are_independent_and_cannot_be_extended_to_human_integration_permissions(): void
    {
        [, , $agent, $token, $task, $assignment] = $this->fixture(['agent:profile']);
        $this->getJson('/api/v1/agent/me', $this->headers($token))->assertOk();
        $this->getJson('/api/v1/agent/tasks', $this->headers($token))->assertForbidden();
        $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'done', 'summary' => 'Denied'], $this->headers($token))->assertForbidden();
        $this->postJson('/api/admin/integrations/agents/'.$agent->uuid.'/tokens', ['scopes' => ['projects:write']])->assertUnprocessable();
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'Bypass', 'scopes' => ['agent:profile']])->assertUnprocessable();
    }

    public function test_project_removal_task_removal_and_reassignment_invalidate_access_and_replays(): void
    {
        [$project, $owner, $agent, $token, $task, $assignment] = $this->fixture();
        $base = '/api/v1/agent/tasks/'.$task->uuid;
        $body = ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'Done'];
        $this->postJson($base.'/reports', $body, $this->headers($token))->assertCreated();
        $this->deleteJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent->uuid])->assertOk();
        $this->getJson($base, $this->headers($token))->assertNotFound();
        $this->postJson($base.'/reports', $body, $this->headers($token))->assertNotFound();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent->uuid])->assertOk();
        $this->postJson($base.'/reports', $body, $this->headers($token))->assertConflict();
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $this->getJson($base, $this->headers($token))->assertNotFound();
        $this->getJson('/api/v1/agent/me', $this->headers($token))->assertOk()->assertJsonPath('data.projectIds', []);
        $this->getJson('/api/admin/integrations/agents')->assertOk()->assertJsonPath('data.0.projectCount', 0);
    }

    public function test_owner_grants_disable_rotation_and_delete_preserve_audit_without_users(): void
    {
        [$project, $owner, $agent, $token, $task, , $data] = $this->fixture();
        $count = User::count();
        $path = '/api/admin/integrations/agents/'.$agent->uuid;
        $rotated = $this->postJson($path.'/tokens', ['scopes' => AgentAccess::SCOPES, 'replace' => true])->assertCreated()->json();
        $this->getJson('/api/v1/agent/me', $this->headers($token))->assertUnauthorized();
        $this->deleteJson($path.'/tokens/'.$rotated['id'])->assertOk();
        $this->getJson('/api/v1/agent/me', $this->headers($rotated))->assertUnauthorized();
        $valid = $this->postJson($path.'/tokens', ['scopes' => AgentAccess::SCOPES])->assertCreated()->json();
        $this->patchJson($path, [...$data, 'projectIds' => []])->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid, $this->headers($valid))->assertNotFound();
        $this->patchJson($path, [...$data, 'active' => false])->assertOk();
        $this->getJson('/api/v1/agent/me', $this->headers($valid))->assertUnauthorized();
        $this->deleteJson($path)->assertOk();
        $this->getJson('/api/admin/integrations/agents')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull(ExternalAgent::withTrashed()->find($agent->id)->deleted_at);
        $this->assertSame($count, User::count());
    }

    public function test_superadministrator_can_assign_only_owner_approved_projects_without_credentials_or_settings(): void
    {
        [$project, $owner, $agent, $token, $task] = $this->fixture();
        $user = User::factory()->create();
        $member = $project->workspace->members()->create(['user_id' => $user->id, 'role' => 'admin', 'status' => 'active', 'is_project_superadmin' => true]);
        ProjectMember::create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'workspace_member_id' => $member->id]);
        $this->actingAs($user)->getJson('/api/admin/integrations/agents')->assertForbidden();
        $this->postJson('/api/admin/integrations/agents/'.$agent->uuid.'/tokens', ['scopes' => AgentAccess::SCOPES])->assertForbidden();
        $this->getJson('/api/admin/projects/'.$project->uuid.'/team')->assertOk()->assertJsonPath('agents.0.id', $agent->uuid)->assertDontSee('secret_hash');
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $other = Project::factory()->for($project->workspace)->create();
        ProjectMember::create(['workspace_id' => $project->workspace_id, 'project_id' => $other->id, 'workspace_member_id' => $member->id]);
        $this->postJson('/api/admin/projects/'.$other->uuid.'/team/agents/'.$agent->uuid)->assertUnprocessable();
        $foreign = ExternalAgent::factory()->create();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$foreign->uuid)->assertNotFound();
        $member->update(['is_project_superadmin' => false]);
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertForbidden();
        $this->actingAs($owner)->getJson('/api/admin/integrations/agents')->assertOk();
    }

    public function test_agent_webhooks_are_minimal_signed_retryable_and_cancelled_when_access_is_revoked(): void
    {
        Http::preventStrayRequests();
        [$project, $owner, $agent, $token, $task, $assignment, , $secret] = $this->fixture(webhook: true);
        $body = DB::table('webhook_events')->first()->body;
        $this->assertSame('pending', json_decode($body, true)['state']);
        foreach (['description', 'Allowed description', 'PRIVATE-NOTES', 'price', $token['secret']] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
        Http::fakeSequence()->push('', 500)->push('', 204);
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        $this->travel(31)->seconds();
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        $requests = Http::recorded();
        $first = $requests[0][0];
        $this->assertSame($body, $first->body());
        $this->assertSame('sha256='.hash_hmac('sha256', $first->header('X-ProjectBrief-Timestamp')[0].'.'.$body, $secret), $first->header('X-ProjectBrief-Signature')[0]);
        $this->assertSame($first->header('X-ProjectBrief-Delivery'), $requests[1][0]->header('X-ProjectBrief-Delivery'));
        app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'Private project context']);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'Changed'])->assertOk();
        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'cancelled', 'error_code' => 'agent_access_revoked']);
        Http::assertSentCount(2);
    }

    public function test_unsafe_webhook_destinations_and_unknown_fields_are_rejected(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $data = ['name' => 'Bot', 'kind' => 'ai', 'active' => true, 'projectIds' => []];
        foreach (['http://127.0.0.1/events', 'https://127.0.0.1/events', 'https://localhost/events', 'https://user:secret@example.com/events', 'file:///etc/passwd'] as $url) {
            $this->postJson('/api/admin/integrations/agents', [...$data, 'webhookUrl' => $url])->assertUnprocessable();
        }
        $this->postJson('/api/admin/integrations/agents', [...$data, 'role' => 'owner'])->assertUnprocessable();
        $this->assertDatabaseCount('external_agents', 0);
    }
}
