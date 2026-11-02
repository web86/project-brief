<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Services\AccountSecurity;
use App\Services\LiveNotifications;
use App\Services\ProjectExportData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientHistoryPrivacyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_client_allowlist_hides_agent_lifecycle_and_metadata_but_preserves_human_publication(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $task = Task::factory()->for($project)->create();
        $private = $task->comments()->create(['author_type' => 'agent', 'team_only' => true, 'text' => 'PRIVATE_EXECUTION']);
        $public = $task->comments()->create(['author_type' => 'admin', 'author_user_id' => $owner->id, 'text' => 'Reviewed and ready for your approval.']);
        foreach (['agent_assigned', 'agent_removed', 'agent_run_requested', 'agent_started', 'agent_reports', 'agent_run_cancelled', 'future_internal_event'] as $type) {
            $task->history()->create(['event_type' => $type, 'actor_type' => 'admin', 'meta' => ['agentName' => 'PRIVATE_EXECUTOR', 'text' => 'PRIVATE_EXECUTION'], 'created_at' => now()]);
        }
        foreach ([['status', 'agent', []], ['status', 'admin', ['teamOnly' => true]], ['status', 'admin', ['runId' => 'PRIVATE_RUN']], ['comment', 'admin', ['commentId' => $private->id]]] as [$type, $actor, $meta]) {
            $task->history()->create(['event_type' => $type, 'actor_type' => $actor, 'meta' => $meta, 'created_at' => now()]);
        }
        $task->history()->create(['event_type' => 'created', 'actor_type' => 'system', 'meta' => [], 'created_at' => now()]);
        $task->history()->create(['event_type' => 'status', 'actor_type' => 'admin', 'old_value' => 'new', 'new_value' => 'approved', 'created_at' => now()]);
        $task->history()->create(['event_type' => 'comment', 'actor_type' => 'admin', 'meta' => ['commentId' => $public->id, 'agentSourceCommentId' => $private->id, 'text' => 'Developer shared an agent result'], 'created_at' => now()]);
        // Legacy malformed public records must also stay private, including linked attachments.
        $legacy = $task->comments()->create(['author_type' => 'agent', 'text' => 'PRIVATE_LEGACY']);
        $legacy->attachments()->create(['task_id' => $task->id, 'uploaded_by_type' => 'admin', 'original_name' => 'PRIVATE_FILE.txt', 'stored_name' => 'file', 'mime_type' => 'text/plain', 'size' => 1, 'disk' => 'local', 'path' => 'file']);
        $this->actingAs($owner)->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonCount(14, 'data.history')->assertJsonCount(3, 'data.comments');
        $this->app['auth']->forgetGuards();
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'synthetic-access')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $response = $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertJsonCount(3, 'data.history')->assertJsonCount(1, 'data.comments')->assertJsonMissingPath('data.agentAssignments')->assertJsonMissingPath('data.comments.0.agentKind');
        foreach (['PRIVATE_', 'agentSource', 'agentState', 'agentKind', 'agentReport', 'agent result'] as $text) {
            $this->assertStringNotContainsString($text, $response->getContent());
        }
        $this->getJson('/api/client/project/'.$project->uuid.'/discussions')->assertOk()->assertJsonCount(1, 'data');
        $export = app(ProjectExportData::class)->build($project, 'all', 'en', ['attachments' => true], client: true);
        $this->assertStringNotContainsString('PRIVATE_', json_encode($export));
        $this->assertDatabaseCount('task_history', 14);
        $request = Request::create('/api/client/sync');
        $request->attributes->set('client_project', $project);
        $request->attributes->set('project_client', $client);
        $updates = app(LiveNotifications::class)->changes($request, ['history' => 0, 'comments' => 0], null);
        $this->assertStringNotContainsString('PRIVATE_', json_encode($updates));
    }

    public function test_totp_protected_accounts_do_not_repeat_optional_passkey_onboarding(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->assertNull($owner->security_recommendations_seen_at);
        $this->assertFalse(app(AccountSecurity::class)->recommendationsPending($owner));
        $this->actingAs($owner)->getJson('/api/security/status')->assertOk()->assertJsonPath('recommendationsPending', false)->assertJsonPath('totp', true)->assertJsonPath('recoverySaved', true);
        $owner->forceFill(['recovery_codes_acknowledged_at' => null])->save();
        $this->getJson('/api/security/status')->assertJsonPath('state', 'enrollment_required');
        $this->getJson('/api/admin/me')->assertForbidden();
    }
}
