<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentAccess;
use App\Services\WebhookDestination;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentWebhookHotfixTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_platform_owner_can_create_an_agent_with_a_shared_key_without_overflowing_platform_audit(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project, ['platform_admin' => true]));
        $settings = ['name' => 'Synthetic platform-owned agent', 'active' => true, 'projectIds' => [$project->uuid]];
        $this->postJson('/api/admin/integrations/agents', $settings)->assertCreated();
        $key = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => 'Synthetic shared key', 'scopes' => ['agent:profile']])->assertCreated()->json();
        $this->withoutExceptionHandling();
        $agent = $this->postJson('/api/admin/integrations/agents', [...$settings, 'credentialId' => $key['id']])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('agent_credential_links', 1);
        $this->getJson('/api/v1/agent/me', ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $agent])->assertOk();
        $this->assertLessThanOrEqual(60, strlen(DB::table('platform_audit')->where('action', 'like', 'agent_credential_changed%')->value('action')));
    }

    private function owner(): Project
    {
        Http::preventStrayRequests();
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'receiver.example.test', 'ip' => '93.184.216.34']);
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project, ['platform_admin' => true]));

        return $project;
    }

    private function key(string $name = 'Shared key'): array
    {
        return $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => $name, 'scopes' => ['agent:profile']])->assertCreated()->json();
    }

    private function agent(Project $project, ?array $key = null): array
    {
        $body = ['name' => 'Synthetic agent', 'active' => true, 'projectIds' => [$project->uuid], 'credentialId' => $key['id'] ?? null];
        $id = $this->postJson('/api/admin/integrations/agents', $body)->assertCreated()->json('data.id');

        return [$id, $body];
    }

    private function hook(array $events, array $filter = []): array
    {
        return $this->postJson('/api/admin/integrations/webhooks', ['name' => 'Independent receiver', 'url' => 'https://receiver.example.test/events', 'events' => $events, ...$filter])->assertCreated()->json();
    }

    public function test_platform_owner_can_attach_keep_replace_and_detach_a_shared_key_without_mutating_its_scopes(): void
    {
        $project = $this->owner();
        $one = $this->key();
        $two = $this->key('Replacement');
        [$agent, $body] = $this->agent($project);
        $url = '/api/admin/integrations/agents/'.$agent;
        $this->patchJson($url, [...$body, 'credentialId' => $one['id']])->assertOk();
        $generation = DB::table('agent_credential_links')->value('uuid');
        $this->patchJson($url, [...$body, 'credentialId' => $one['id'], 'name' => 'Renamed'])->assertOk();
        $this->assertSame($generation, DB::table('agent_credential_links')->value('uuid'));
        $this->patchJson($url, [...$body, 'credentialId' => $two['id']])->assertOk();
        $this->assertNotSame($generation, DB::table('agent_credential_links')->value('uuid'));
        [$other] = $this->agent($project, $two);
        $headers = fn ($key, $id) => ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $id];
        $this->getJson('/api/v1/agent/me', $headers($one, $agent))->assertForbidden();
        $this->getJson('/api/v1/agent/me', $headers($two, $agent))->assertOk();
        $this->getJson('/api/v1/agent/me', $headers($two, $other))->assertOk();
        $this->patchJson($url, [...$body, 'credentialId' => null])->assertOk();
        $this->getJson('/api/v1/agent/me', $headers($two, $agent))->assertForbidden();
        $this->getJson('/api/v1/agent/me', $headers($two, $other))->assertOk();
        $this->assertDatabaseCount('agent_credential_links', 1);
        $this->assertSame(['agent:profile'], json_decode(DB::table('integration_tokens')->where('uuid', $two['id'])->value('scopes'), true));
        $audit = DB::table('platform_audit')->where('action', 'like', 'agent_credential_changed%')->whereNotNull('object_id')->first();
        $this->assertSame('agent_credential', $audit->object_type);
        $this->assertSame($one['id'], $audit->object_id);
    }

    public function test_agent_edit_cannot_delete_recreate_or_reconfigure_an_existing_personal_webhook(): void
    {
        $project = $this->owner();
        [$agent, $body] = $this->agent($project, $this->key());
        $row = ExternalAgent::where('uuid', $agent)->firstOrFail();
        $id = DB::table('webhook_endpoints')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'external_agent_id' => $row->id, 'name' => 'Existing personal', 'url' => 'https://receiver.example.test/existing', 'events' => json_encode(AgentAccess::EVENTS), 'enabled' => false, 'secret' => Crypt::encryptString('synthetic-secret'), 'secret_version' => 7, 'project_filter' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('webhook_endpoints')->find($id);
        $this->patchJson('/api/admin/integrations/agents/'.$agent, [...$body, 'name' => 'New name', 'instructions' => 'New instruction'])->assertOk();
        $this->assertSame($before, (array) DB::table('webhook_endpoints')->find($id));
        $this->patchJson('/api/admin/integrations/agents/'.$agent, [...$body, 'webhookUrl' => 'https://receiver.example.test/new'])->assertUnprocessable();
        $this->assertSame($before, (array) DB::table('webhook_endpoints')->find($id));
    }

    public function test_independent_receivers_precede_agents_and_select_events_and_projects_for_two_identities(): void
    {
        $project = $this->owner();
        $this->assertDatabaseCount('external_agents', 0);
        $all = $this->hook(['agent.task.assigned', 'agent.run.requested']);
        $selected = $this->hook(['agent.task.assigned'], ['projectFilter' => 'selected', 'projectIds' => [$project->uuid]]);
        $otherEvent = $this->hook(['agent.message.created']);
        $this->assertSame(0, DB::table('webhook_endpoints')->whereNotNull('external_agent_id')->count());
        $otherProject = Project::factory()->create(['workspace_id' => $project->workspace_id]);
        $key = $this->key();
        foreach ([$project, $otherProject] as $parent) {
            [$agent] = $this->agent($parent, $key);
            $task = Task::factory()->for($parent)->create();
            $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent, 'deferRun' => true])->assertOk();
            $event = json_decode(DB::table('webhook_events')->orderByDesc('id')->first()->body, true);
            $this->assertSame($agent, $event['agentId']);
            $this->assertSame($parent->uuid, $event['projectId']);
        }
        $endpoint = fn ($uuid) => DB::table('webhook_endpoints')->where('uuid', $uuid)->value('id');
        $this->assertSame(2, DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint($all['id']))->count());
        $this->assertSame(1, DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint($selected['id']))->count());
        $this->assertSame(0, DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint($otherEvent['id']))->count());
        $mixed = ['project.created', 'agent.task.assigned', 'agent.run.requested'];
        $this->patchJson('/api/admin/integrations/webhooks/'.$all['id'], ['name' => 'Renamed mixed receiver', 'events' => $mixed])->assertOk();
        $this->patchJson('/api/admin/integrations/webhooks/'.$all['id'], ['url' => 'https://receiver.example.test/updated'])->assertOk();
        $this->assertSame($mixed, json_decode(DB::table('webhook_endpoints')->where('uuid', $all['id'])->value('events'), true));
        $this->assertNull(DB::table('webhook_endpoints')->where('uuid', $all['id'])->value('external_agent_id'));
        $this->postJson('/api/admin/integrations/webhooks', ['name' => 'No identity binding', 'url' => 'https://receiver.example.test/events', 'events' => ['agent.task.assigned'], 'agentId' => $agent])->assertUnprocessable();
    }

    public function test_independent_notifications_are_signed_retryable_and_endpoint_revocation_cancels_delivery_without_granting_api_access(): void
    {
        $project = $this->owner();
        $hook = $this->hook(['agent.task.assigned']);
        $key = $this->key();
        [$agent] = $this->agent($project, $key);
        $task = Task::factory()->for($project)->create();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agent, 'deferRun' => true])->assertOk();
        $before = DB::table('webhook_deliveries')->first();
        Http::fakeSequence()->push('', 503)->push('', 204);
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        $this->travel(31)->seconds();
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        $requests = Http::recorded();
        $first = $requests[0][0];
        $this->assertSame($first->body(), $requests[1][0]->body());
        $this->assertSame('sha256='.hash_hmac('sha256', $first->header('X-ProjectBrief-Timestamp')[0].'.'.$first->body(), $hook['secret']), $first->header('X-ProjectBrief-Signature')[0]);
        $this->assertSame($first->header('X-ProjectBrief-Delivery'), $requests[1][0]->header('X-ProjectBrief-Delivery'));
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $before->id, 'status' => 'succeeded', 'attempts' => 2]);
        $this->getJson('/api/v1/agent/me', ['Authorization' => 'Bearer '.$hook['secret'], 'X-ProjectBrief-Agent-ID' => $agent])->assertUnauthorized();
        $this->getJson('/api/v1/agent/tasks', ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $agent])->assertForbidden();
        $next = Task::factory()->for($project)->create();
        $this->postJson('/api/admin/tasks/'.$next->uuid.'/agents', ['agentId' => $agent, 'deferRun' => true])->assertOk();
        $this->assertSame(1, DB::table('webhook_deliveries')->where('status', 'pending')->count());
        $this->patchJson('/api/admin/integrations/webhooks/'.$hook['id'], ['enabled' => false])->assertOk();
        app(WebhookProcessor::class)->process();
        Http::assertSentCount(2);
        $this->deleteJson('/api/admin/integrations/tokens/'.$key['id'])->assertOk();
        $this->getJson('/api/v1/agent/me', ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $agent])->assertUnauthorized();
    }
}
