<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Services\AgentAccess;
use App\Services\AgentPermissions;
use App\Services\AgentRuns;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentCollaborationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SCOPES = ['agent:profile', 'agent:tasks:read', 'agent:tasks:report', 'agent:runs:read', 'agent:runs:write', 'agent:messages:read', 'agent:messages:create'];

    private function fixture(): array
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $input = ['name' => 'Analyst', 'instructions' => 'AGENT-INSTRUCTION', 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid]];
        $id = $this->postJson('/api/admin/integrations/agents', $input)->assertCreated()->json('data.id');
        $agent = ExternalAgent::where('uuid', $id)->firstOrFail();
        $token = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $id, 'name' => 'Analyst token', 'scopes' => self::SCOPES])->assertCreated()->json();
        $task = Task::factory()->for($project)->create(['description' => 'Public task text']);
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $id, 'instruction' => 'PRIVATE-FIRST-INSTRUCTION'])->assertOk();
        $assignment = TaskAgentAssignment::where('task_id', $task->id)->firstOrFail();
        $run = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->first();

        return [$project, $owner, $agent, $token, $task, $assignment, $run, $input];
    }

    private function headers(array $token, string $key = 'operation'): array
    {
        return ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => $key];
    }

    public function test_new_grants_add_membership_but_unchanged_save_does_not_restore_removed_members(): void
    {
        [$project, , $agent, , , $assignment, $run, $input] = $this->fixture();
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'external_agent_id' => $agent->id]);
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $this->assertNotNull($assignment->fresh()->revoked_at);
        $this->assertDatabaseHas('agent_runs', ['id' => $run->id, 'state' => 'cancelled']);
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, $input)->assertOk();
        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id, 'external_agent_id' => $agent->id]);
        $this->postJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$agent->uuid)->assertOk();
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'external_agent_id' => $agent->id]);
    }

    public function test_revocation_also_cancels_queued_runs_on_trashed_tasks(): void
    {
        [, , $agent, , $task, $assignment, $run, $input] = $this->fixture();
        $task->delete();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$input, 'projectIds' => []])->assertOk();
        $this->assertDatabaseHas('agent_runs', ['id' => $run->id, 'state' => 'cancelled']);
        $this->assertNotNull($assignment->fresh()->revoked_at);
    }

    public function test_cancelled_run_does_not_deliver_an_old_execution_request(): void
    {
        [$project, , $agent, , $task, $assignment, $run] = $this->fixture();
        $endpointId = DB::table('webhook_endpoints')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id,
            'name' => 'Run hook', 'url' => 'https://executor.example.test/hook', 'events' => json_encode(['agent.run.requested', 'agent.run.cancelled']), 'enabled' => true,
            'secret' => Crypt::encryptString('qa-webhook-secret'), 'secret_version' => 1, 'project_filter' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        AgentRuns::event($assignment, $run, 'agent.run.requested');
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'pending']);
        $payload = json_decode(DB::table('webhook_events')->orderByDesc('id')->first()->body, true);
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agent-runs/'.$run->uuid.'/cancel', [])->assertOk();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'cancelled', 'error_code' => 'agent_run_cancelled']);
        $this->assertFalse(AgentAccess::deliveryAllowed(DB::table('webhook_endpoints')->find($endpointId), $payload));
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'pending']);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertOk()->assertJsonPath('data.0.runs.0.state', 'cancelled');
    }

    public function test_unified_credentials_are_bound_and_namespaces_and_permission_ceilings_remain_separate(): void
    {
        [, , $agent, $token] = $this->fixture();
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonPath('tokens.0.type', 'agent')->assertJsonPath('tokens.0.agentId', $agent->uuid);
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'Bad', 'scopes' => ['agent:tasks:read']])->assertUnprocessable();
        $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $agent->uuid, 'name' => 'Bad', 'scopes' => ['projects:read']])->assertUnprocessable();
        $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $agent->uuid, 'name' => 'Bad', 'scopes' => ['agent:team-chat:read']])->assertUnprocessable();
        $general = $this->postJson('/api/admin/integrations/tokens', ['name' => 'General', 'scopes' => ['projects:read']])->assertCreated()->json();
        $this->getJson('/api/v1/agent/me', $this->headers($general))->assertForbidden();
        $this->getJson('/api/v1/projects', $this->headers($token))->assertForbidden();
        $this->deleteJson('/api/admin/integrations/tokens/'.$token['id'])->assertOk();
        $this->getJson('/api/v1/agent/me', $this->headers($token))->assertUnauthorized();
    }

    public function test_follow_up_runs_are_idempotent_versioned_and_cannot_accept_late_replies(): void
    {
        [$project, , $agent, $token, $task, $assignment, $run] = $this->fixture();
        $root = '/api/v1/agent/tasks/'.$task->uuid;
        $this->getJson($root.'/runs/'.$run->uuid, $this->headers($token))->assertOk()->assertJsonPath('data.context.agentInstruction', 'AGENT-INSTRUCTION');
        $body = ['assignmentId' => $assignment->uuid, 'runId' => $run->uuid];
        $this->postJson($root.'/runs/'.$run->uuid.'/start', $body, $this->headers($token, 'start'))->assertOk()->assertJsonPath('data.state', 'running');
        $reply = [...$body, 'state' => 'submitted', 'summary' => 'PRIVATE-FIRST-REPORT'];
        $this->postJson($root.'/reports', $reply, $this->headers($token, 'result-1'))->assertCreated();
        $this->postJson($root.'/reports', $reply, $this->headers($token, 'result-1'))->assertCreated();
        $this->assertDatabaseCount('agent_reports', 1);
        $this->patchJson('/api/admin/projects/'.$project->uuid.'/agent-instructions', ['instructions' => 'PROJECT-RULES'])->assertOk();
        $url = '/api/admin/tasks/'.$task->uuid.'/agent-assignments/'.$assignment->uuid.'/runs';
        $request = ['instruction' => 'PRIVATE-FOLLOW-UP', 'submissionId' => (string) Str::uuid()];
        $next = $this->postJson($url, $request)->assertCreated()->json('data.id');
        $this->postJson($url, $request)->assertCreated()->assertJsonPath('data.id', $next);
        $this->postJson($url, [...$request, 'instruction' => 'Changed'])->assertConflict();
        $this->postJson($url, ['instruction' => 'Another', 'submissionId' => (string) Str::uuid()])->assertConflict();
        $this->getJson($root.'/runs/'.$next, $this->headers($token))->assertOk()->assertJsonPath('data.context.projectInstruction', 'PROJECT-RULES');
        $this->getJson($root.'/runs/'.$run->uuid, $this->headers($token))->assertOk()->assertJsonPath('data.context.projectInstruction', '');
        $this->postJson($root.'/reports', $reply, $this->headers($token, 'late-old'))->assertConflict();
        $this->postJson($root.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'Ambiguous old executor'], $this->headers($token, 'ambiguous'))->assertConflict();
        $this->postJson($root.'/messages', ['assignmentId' => $assignment->uuid, 'runId' => $next, 'text' => 'PRIVATE-REPLY'], $this->headers($token, 'message'))->assertCreated();
        $this->postJson($root.'/reports', ['assignmentId' => $assignment->uuid, 'runId' => $next, 'state' => 'submitted', 'summary' => 'PRIVATE-SECOND-REPORT'], $this->headers($token, 'result-2'))->assertCreated();
        $this->assertDatabaseCount('agent_runs', 2);
        $this->assertDatabaseCount('agent_reports', 2);
        $this->assertSame('Public task text', $task->fresh()->description);
        $this->assertSame('new', $task->fresh()->status);
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertOk()->assertJsonCount(2, 'data.0.runs');
    }

    public function test_history_pagination_is_assignment_bound_and_frozen_history_requires_dialogue_scope(): void
    {
        [, , $agent, $token, $task, $assignment, $run] = $this->fixture();
        $messages = [];
        for ($i = 0; $i < 205; $i++) {
            $messages[] = ['uuid' => (string) Str::uuid(), 'agent_run_id' => $run->id, 'external_agent_id' => $agent->id, 'text' => 'History '.$i, 'created_at' => now()];
        }
        DB::table('agent_messages')->insert($messages);
        $root = '/api/v1/agent/tasks/'.$task->uuid;
        $latest = $this->getJson($root.'/messages', $this->headers($token))->assertOk()->assertJsonCount(200, 'data');
        $cursor = $latest->json('nextCursor');
        $this->getJson($root.'/messages?before='.$cursor, $this->headers($token))->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('nextCursor', null);
        $this->getJson($root.'/messages?before='.(string) Str::uuid(), $this->headers($token))->assertNotFound();
        $restricted = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $agent->uuid, 'name' => 'Run metadata only', 'scopes' => ['agent:runs:read']])->assertCreated()->json();
        $this->getJson($root.'/runs', $this->headers($restricted))->assertOk();
        $this->getJson($root.'/runs/'.$run->uuid, $this->headers($restricted))->assertForbidden();
        $this->getJson($root.'/messages', $this->headers($restricted))->assertForbidden();
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-assignments/'.$assignment->uuid.'/messages?before='.$cursor)->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_private_run_files_require_file_and_dialogue_scopes_and_current_assignment(): void
    {
        Storage::fake('local');
        [$project, , $agent, , $task, $assignment, $run, $input] = $this->fixture();
        $this->patchJson('/api/admin/integrations/agents/'.$agent->uuid, [...$input, 'permissions' => array_replace(AgentPermissions::DEFAULTS, ['taskAttachments' => true])])->assertOk();
        $token = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $agent->uuid, 'name' => 'Files', 'scopes' => [...self::SCOPES, 'agent:attachments:create', 'agent:attachments:read']])->assertCreated()->json();
        $upload = $this->post('/api/v1/agent/tasks/'.$task->uuid.'/attachments', ['assignmentId' => $assignment->uuid, 'runId' => $run->uuid, 'text' => 'Private run file',
            'attachments' => [UploadedFile::fake()->createWithContent('private-result.txt', 'PRIVATE-RUN-FILE')]], $this->headers($token, 'file'));
        $id = $upload->assertCreated()->json('data.attachments.0.id');
        $url = '/api/v1/agent/tasks/'.$task->uuid.'/runs/'.$run->uuid.'/attachments/'.$id.'/download';
        $this->get($url, $this->headers($token))->assertOk();
        $withoutDialogue = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $agent->uuid, 'name' => 'No messages', 'scopes' => ['agent:tasks:read', 'agent:attachments:read']])->assertCreated()->json();
        $this->get($url, $this->headers($withoutDialogue))->assertForbidden();
        $this->deleteJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent->uuid])->assertOk();
        $this->get($url, $this->headers($token))->assertNotFound();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent->uuid])->assertOk();
        $this->get($url, $this->headers($token))->assertNotFound();
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertOk()->assertSee('Private run file');
    }

    public function test_private_context_and_dialogue_are_not_exposed_to_clients_or_another_assignment(): void
    {
        [$project, , $agent, $token, $task, $assignment, $run] = $this->fixture();
        $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'runId' => $run->uuid, 'state' => 'submitted', 'summary' => 'SECRET-REPORT'], $this->headers($token))->assertCreated();
        $other = $this->postJson('/api/admin/integrations/agents', ['name' => 'Other', 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid], 'permissions' => array_replace(AgentPermissions::DEFAULTS, ['taskComments' => true, 'teamChat' => true])])->assertCreated()->json('data.id');
        $otherToken = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $other, 'name' => 'Other', 'scopes' => [...self::SCOPES, 'agent:comments:read', 'agent:team-chat:read']])->assertCreated()->json();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $other])->assertOk();
        $root = '/api/v1/agent/tasks/'.$task->uuid;
        $this->getJson($root.'/runs/'.$run->uuid, $this->headers($otherToken))->assertNotFound();
        $this->getJson($root.'/messages', $this->headers($otherToken))->assertOk()->assertDontSee('PRIVATE-FIRST-INSTRUCTION')->assertDontSee('SECRET-REPORT');
        $this->getJson($root.'/comments', $this->headers($otherToken))->assertOk()->assertDontSee('SECRET-REPORT');
        $this->app['auth']->forgetGuards();
        $client = $project->clients()->first();
        $access = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'client-test')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $access->id]);
        $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertDontSee('SECRET-REPORT')->assertDontSee('PRIVATE-FIRST-INSTRUCTION')->assertDontSee('AGENT-INSTRUCTION');
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertUnauthorized();
    }
}
