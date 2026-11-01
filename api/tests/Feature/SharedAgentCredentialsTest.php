<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedAgentCredentialsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $key = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => 'Trusted executors', 'scopes' => AgentAccess::SCOPES])->assertCreated()->json();
        $agents = [];
        foreach (['Analyst', 'Reviewer'] as $name) {
            $agents[] = $this->postJson('/api/admin/integrations/agents', ['name' => $name, 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid], 'credentialId' => $key['id']])->assertCreated()->json('data.id');
        }

        return [$project, $key, $agents];
    }

    private function headers(array $key, ?string $agent = null): array
    {
        return ['Authorization' => 'Bearer '.$key['secret'], ...($agent ? ['X-ProjectBrief-Agent-ID' => $agent] : [])];
    }

    public function test_shared_key_can_precede_agents_and_requires_an_explicit_current_linked_identity(): void
    {
        [$project, $key, [$one, $two]] = $this->fixture();
        $this->getJson('/api/v1/agent/me', $this->headers($key))->assertForbidden();
        foreach ([$one, $two] as $id) {
            $this->getJson('/api/v1/agent/me', $this->headers($key, $id))->assertOk()->assertJsonPath('data.id', $id);
            $this->assertDatabaseHas('agent_operations', ['integration_token_id' => DB::table('integration_tokens')->where('uuid', $key['id'])->value('id'), 'claimed_agent_uuid' => $id, 'http_status' => 200]);
        }
        $unlinked = $this->postJson('/api/admin/integrations/agents', ['name' => 'Unlinked', 'kind' => 'service', 'active' => true, 'projectIds' => []])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/agent/me', $this->headers($key, $unlinked))->assertForbidden();
        $this->getJson('/api/v1/projects', $this->headers($key, $one))->assertForbidden();
        $this->assertDatabaseCount('agent_credential_links', 2);
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonPath('tokens.0.shared', true)->assertJsonCount(2, 'tokens.0.linkedAgents');
    }

    public function test_shared_scopes_require_grants_membership_and_task_assignment(): void
    {
        [$project, $key, [$one, $two]] = $this->fixture();
        $task = Task::factory()->for($project)->create();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $one])->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid, $this->headers($key, $one))->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid, $this->headers($key, $two))->assertNotFound();
        $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks', $this->headers($key, $one))->assertOk();
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/team/agents/'.$one)->assertOk();
        $this->getJson('/api/v1/agent/tasks/'.$task->uuid, $this->headers($key, $one))->assertNotFound();
    }

    public function test_detach_replacement_and_revoke_stop_access_without_erasing_audit(): void
    {
        [$project, $key, [$one, $two]] = $this->fixture();
        $settings = ['name' => 'Analyst', 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid], 'credentialId' => null];
        $this->getJson('/api/v1/agent/me', $this->headers($key, $one))->assertOk();
        $this->patchJson('/api/admin/integrations/agents/'.$one, $settings)->assertOk();
        $this->getJson('/api/v1/agent/me', $this->headers($key, $one))->assertForbidden();
        $this->getJson('/api/v1/agent/me', $this->headers($key, $two))->assertOk();
        $new = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => 'Replacement', 'scopes' => ['agent:profile']])->assertCreated()->json();
        $this->patchJson('/api/admin/integrations/agents/'.$one, [...$settings, 'credentialId' => $new['id']])->assertOk();
        $this->getJson('/api/v1/agent/me', $this->headers($new, $one))->assertOk();
        $this->deleteJson('/api/admin/integrations/tokens/'.$key['id'])->assertOk();
        foreach ([$one, $two] as $id) {
            $this->getJson('/api/v1/agent/me', $this->headers($key, $id))->assertUnauthorized();
        }
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonCount(1, 'tokens');
        $this->assertDatabaseHas('agent_operations', ['claimed_agent_uuid' => $one, 'http_status' => 200]);
        $this->assertDatabaseHas('integration_tokens', ['uuid' => $key['id']]);
        $this->patchJson('/api/admin/integrations/agents/'.$one, [...$settings, 'credentialId' => $key['id']])->assertNotFound();
    }

    public function test_individual_legacy_credentials_keep_request_format_and_cannot_claim_another_agent(): void
    {
        [, , [$one, $two]] = $this->fixture();
        $key = $this->postJson('/api/admin/integrations/agents/'.$one.'/tokens', ['scopes' => ['agent:profile']])->assertCreated()->json();
        $this->getJson('/api/v1/agent/me', $this->headers($key))->assertOk()->assertJsonPath('data.id', $one);
        $this->getJson('/api/v1/agent/me', $this->headers($key, $two))->assertForbidden();
        $integration = $this->postJson('/api/admin/integrations/tokens', ['name' => 'Ordinary', 'scopes' => ['projects:read']])->assertCreated()->json();
        $this->getJson('/api/v1/agent/me', $this->headers($integration, $one))->assertForbidden();
    }

    public function test_shared_idempotency_keys_are_scoped_to_agent_and_binding_generation(): void
    {
        [$project, $key, [$one, $two]] = $this->fixture();
        $task = Task::factory()->for($project)->create();
        foreach ([$one, $two] as $id) {
            $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $id])->assertOk();
            $assignment = DB::table('task_agent_assignments')->where('external_agent_id', ExternalAgent::where('uuid', $id)->value('id'))->first();
            $body = ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'Private report'];
            $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', $body, [...$this->headers($key, $id), 'Idempotency-Key' => 'same-external-operation'])->assertCreated();
        }
        $this->assertDatabaseCount('agent_reports', 2);
        $this->assertDatabaseCount('integration_requests', 2);
        $settings = ['name' => 'Analyst', 'kind' => 'ai', 'active' => true, 'projectIds' => [$project->uuid], 'credentialId' => null];
        $this->patchJson('/api/admin/integrations/agents/'.$one, $settings)->assertOk();
        $this->patchJson('/api/admin/integrations/agents/'.$one, [...$settings, 'credentialId' => $key['id']])->assertOk();
        $assignment = DB::table('task_agent_assignments')->where('external_agent_id', ExternalAgent::where('uuid', $one)->value('id'))->first();
        $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'state' => 'submitted', 'summary' => 'Private report'], [...$this->headers($key, $one), 'Idempotency-Key' => 'same-external-operation'])->assertConflict();
        $this->assertDatabaseCount('agent_reports', 2);

    }
}
