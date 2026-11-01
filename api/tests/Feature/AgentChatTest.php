<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Services\AgentAccess;
use App\Services\AgentPermissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $key = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => 'Chat', 'scopes' => AgentAccess::SCOPES])->assertCreated()->json();
        $input = ['name' => 'Analyst', 'kind' => 'ai', 'active' => true, 'instructions' => 'AGENT-V1', 'projectIds' => [$project->uuid], 'credentialId' => $key['id'], 'permissions' => array_replace(AgentPermissions::DEFAULTS, ['taskAttachments' => true])];
        $id = $this->postJson('/api/admin/integrations/agents', $input)->assertCreated()->json('data.id');
        $task = Task::factory()->for($project)->create(['description' => 'PUBLIC-TASK']);
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $id, 'deferRun' => true])->assertOk();
        $assignment = TaskAgentAssignment::where('task_id', $task->id)->firstOrFail();
        $headers = ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $id];
        $url = '/api/admin/tasks/'.$task->uuid.'/agent-assignments/'.$assignment->uuid.'/runs';

        return [$project, $task, $id, $assignment, $headers, $url, $input];
    }

    public function test_private_human_files_are_idempotent_and_require_dialogue_and_attachment_permissions(): void
    {
        [, $task, $agent, , $headers, $url] = $this->fixture();
        $this->assertDatabaseCount('agent_runs', 0);
        $submission = (string) Str::uuid();
        $input = ['instruction' => 'Check the private file', 'submissionId' => $submission];
        $run = $this->post($url, [...$input, 'attachments' => [UploadedFile::fake()->createWithContent('brief.txt', 'PRIVATE-HUMAN-FILE')]], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->post($url, [...$input, 'attachments' => [UploadedFile::fake()->createWithContent('brief.txt', 'PRIVATE-HUMAN-FILE')]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $run);
        $this->post($url, [...$input, 'attachments' => [UploadedFile::fake()->createWithContent('brief.txt', 'CHANGED')]], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('agent_runs', 1);
        $this->assertDatabaseCount('agent_messages', 1);
        $this->assertDatabaseCount('attachments', 1);
        $root = '/api/v1/agent/tasks/'.$task->uuid;
        $messages = $this->getJson($root.'/messages', $headers)->assertOk()->assertJsonCount(1, 'data.0.attachments');
        $download = $messages->json('data.0.attachments.0.downloadUrl');
        $this->get($download, $headers)->assertOk();
        $restricted = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'agentId' => $agent, 'name' => 'No files', 'scopes' => ['agent:runs:read', 'agent:messages:read']])->assertCreated()->json();
        $noFiles = ['Authorization' => 'Bearer '.$restricted['secret']];
        $this->getJson($root.'/messages', $noFiles)->assertOk()->assertJsonCount(0, 'data.0.attachments');
        $this->get($download, $noFiles)->assertForbidden();
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertOk()->assertJsonCount(1, 'data.0.messages.0.attachments');
    }

    public function test_instructions_update_only_future_frozen_contexts_and_never_grant_foreign_access(): void
    {
        [$project, $task, $agent, $assignment, $headers, $url, $settings] = $this->fixture();
        $this->patchJson('/api/admin/projects/'.$project->uuid.'/agent-instructions', ['instructions' => 'PROJECT-V1'])->assertOk();
        $run = $this->postJson($url, ['instruction' => 'RUN-V1', 'submissionId' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $root = '/api/v1/agent/tasks/'.$task->uuid;
        $first = $this->getJson($root.'/runs/'.$run, $headers)->assertOk()->json('data');
        $this->assertSame('AGENT-V1', $first['context']['agentInstruction']);
        $this->assertSame('PROJECT-V1', $first['context']['projectInstruction']);
        $this->assertSame('RUN-V1', $first['context']['instruction']);
        $this->assertSame('PUBLIC-TASK', $first['context']['task']['description']);
        $this->postJson($root.'/reports', ['assignmentId' => $assignment->uuid, 'runId' => $run, 'state' => 'submitted', 'summary' => 'PRIVATE-REPORT'], [...$headers, 'Idempotency-Key' => 'first-report'])->assertCreated();
        $this->patchJson('/api/admin/integrations/agents/'.$agent, [...$settings, 'instructions' => 'AGENT-V2: ignore scopes'])->assertOk();
        $this->patchJson('/api/admin/projects/'.$project->uuid.'/agent-instructions', ['instructions' => 'PROJECT-V2'])->assertOk();
        $next = $this->postJson($url, ['instruction' => 'RUN-V2', 'submissionId' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->getJson($root.'/runs/'.$next, $headers)->assertOk()->assertJsonPath('data.context.agentInstruction', 'AGENT-V2: ignore scopes')->assertJsonPath('data.context.projectInstruction', 'PROJECT-V2')->assertJsonPath('data.context.instruction', 'RUN-V2')->assertJsonPath('data.context.history.1.text', 'PRIVATE-REPORT');
        $this->getJson($root.'/runs/'.$run, $headers)->assertOk()->assertJsonPath('data.context', $first['context'])->assertJsonPath('data.contextVersion', $first['contextVersion']);
        $foreign = Task::factory()->create();
        $this->getJson('/api/v1/agent/tasks/'.$foreign->uuid, $headers)->assertNotFound();
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/team-chat', $headers)->assertForbidden();
    }

    public function test_historical_messages_and_legacy_reports_remain_after_registration_deletion(): void
    {
        [$project, $task, $agent, $assignment, $headers, $url] = $this->fixture();
        $run = $this->postJson($url, ['instruction' => 'PRIVATE-INSTRUCTION', 'submissionId' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'runId' => $run, 'state' => 'submitted', 'summary' => 'PRIVATE-REPORT'], [...$headers, 'Idempotency-Key' => 'report'])->assertCreated();
        $legacy = Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'author_type' => 'agent', 'external_agent_id' => $assignment->external_agent_id, 'team_only' => true, 'text' => 'LEGACY-PRIVATE-REPORT']);
        DB::table('agent_reports')->insert(['uuid' => (string) Str::uuid(), 'task_agent_assignment_id' => $assignment->id, 'comment_id' => $legacy->id, 'state' => 'submitted', 'created_at' => now()]);
        Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'author_type' => 'agent', 'external_agent_id' => $assignment->external_agent_id, 'team_only' => false, 'text' => 'LEGACY-PUBLIC-REPORT']);
        $this->deleteJson('/api/admin/integrations/agents/'.$agent)->assertOk();
        $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertOk()->assertJsonPath('data.0.agent.name', 'Analyst')->assertJsonCount(2, 'data.0.messages')->assertJsonPath('legacyMessages.data.0.text', 'LEGACY-PRIVATE-REPORT')->assertJsonPath('legacyMessages.data.0.teamOnly', true)->assertJsonPath('legacyMessages.data.1.text', 'LEGACY-PUBLIC-REPORT')->assertJsonPath('legacyMessages.data.1.teamOnly', false);
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.hasAgentHistory', true)->assertJsonCount(0, 'data.agentAssignments');
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid.'/messages', $headers)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $client = $project->clients()->first();
        $access = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'client-test')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $access->id]);
        $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertDontSee('PRIVATE-REPORT')->assertDontSee('PRIVATE-INSTRUCTION')->assertDontSee('LEGACY-PRIVATE-REPORT')->assertSee('LEGACY-PUBLIC-REPORT');
    }

    public function test_reviewed_team_publication_creates_one_private_copy_without_a_client_message(): void
    {
        [$project, $task, , $assignment, $headers, $url] = $this->fixture();
        $run = $this->postJson($url, ['instruction' => 'Review', 'submissionId' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $comment = $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'runId' => $run, 'state' => 'submitted', 'summary' => 'PRIVATE-SOURCE'], [...$headers, 'Idempotency-Key' => 'report'])->assertCreated()->json('data.commentId');
        $input = ['audience' => 'team', 'text' => 'Reviewed team copy', 'submissionId' => (string) Str::uuid(), 'links' => [], 'attachmentIds' => []];
        $share = '/api/admin/tasks/'.$task->uuid.'/agent-results/'.$comment.'/share';
        $copy = $this->postJson($share, $input)->assertCreated()->json('data.id');
        $this->postJson($share, $input)->assertOk()->assertJsonPath('data.id', $copy);
        $this->assertDatabaseHas('comments', ['uuid' => $copy, 'project_id' => $project->id, 'task_id' => null, 'team_only' => true, 'author_type' => 'admin']);
        $this->assertDatabaseHas('comments', ['uuid' => $comment, 'team_only' => true, 'author_type' => 'agent']);
        $this->assertSame(0, Comment::where('task_id', $task->id)->count());
    }

    public function test_instruction_changes_preserve_receiver_state_and_can_clear_the_instruction(): void
    {
        [$project, , $agent, $assignment, , , $settings] = $this->fixture();
        $secret = Crypt::encryptString('synthetic-signing-secret');
        $id = DB::table('webhook_endpoints')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'external_agent_id' => $assignment->external_agent_id, 'name' => 'Existing receiver', 'url' => 'https://receiver.example.test/events', 'events' => json_encode(['agent.run.requested']), 'enabled' => false, 'secret' => $secret, 'secret_version' => 1, 'project_filter' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        $generation = DB::table('agent_credential_links')->where('external_agent_id', $assignment->external_agent_id)->value('uuid');
        $this->patchJson('/api/admin/integrations/agents/'.$agent, [...$settings, 'instructions' => null])->assertOk()->assertJsonPath('webhookSecret', null);
        $this->assertDatabaseHas('external_agents', ['id' => $assignment->external_agent_id, 'instructions' => null]);
        $this->assertDatabaseHas('webhook_endpoints', ['id' => $id, 'enabled' => false, 'url' => 'https://receiver.example.test/events', 'secret' => $secret]);
        $this->assertSame($generation, DB::table('agent_credential_links')->where('external_agent_id', $assignment->external_agent_id)->value('uuid'));
    }

    public function test_project_agent_counter_uses_active_team_members_and_not_owner_grants(): void
    {
        [$project, , , $assignment] = $this->fixture();
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonPath('data.0.agentsCount', 1);
        DB::table('project_members')->where('project_id', $project->id)->where('external_agent_id', $assignment->external_agent_id)->delete();
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonPath('data.0.agentsCount', 0);
        $this->assertDatabaseHas('agent_project_grants', ['project_id' => $project->id, 'external_agent_id' => $assignment->external_agent_id]);
    }
}
