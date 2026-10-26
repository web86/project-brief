<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationCleanupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_revocation_preserves_token_usage_and_idempotency_records_but_denies_bearer_replay(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $token = $this->postJson('/api/admin/integrations/tokens', ['name' => 'CRM', 'scopes' => IntegrationTokens::SCOPES])->assertCreated()->json();
        $headers = ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => 'preserved-after-revoke'];
        $body = ['title' => 'Audited project'];
        $this->postJson('/api/v1/projects', $body, $headers)->assertCreated();
        $record = DB::table('integration_requests')->first();
        $before = DB::table('integration_tokens')->where('uuid', $token['id'])->first();
        $this->assertNotNull($before->last_used_at);
        $this->deleteJson('/api/admin/integrations/tokens/'.$token['id'])->assertOk();
        $this->deleteJson('/api/admin/integrations/tokens/'.$token['id'])->assertOk();
        $this->postJson('/api/v1/projects', $body, $headers)->assertUnauthorized()->assertJsonPath('code', 'invalid_token');
        $this->getJson('/api/v1/projects', $headers)->assertUnauthorized();
        $after = DB::table('integration_tokens')->where('uuid', $token['id'])->first();
        $this->assertNotNull($after->revoked_at);
        foreach (['id', 'secret_hash', 'created_at', 'last_used_at', 'scopes'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
        $this->assertEquals($record, DB::table('integration_requests')->first());
        $this->assertDatabaseCount('projects', 2);
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonPath('tokens.0.id', $token['id'])->assertDontSee($token['secret'])->assertDontSee($after->secret_hash);
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'example.com', 'ip' => '93.184.216.34']);
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $endpoints = [];
        foreach (['Delete me', 'Keep me'] as $name) {
            $uuid = $this->postJson('/api/admin/integrations/webhooks', ['name' => $name, 'url' => 'https://example.com/events', 'events' => ['project.updated'], 'projectFilter' => 'selected', 'projectIds' => [$project->uuid]])->assertCreated()->json('id');
            $endpoints[] = DB::table('webhook_endpoints')->where('uuid', $uuid)->first();
        }
        DB::transaction(fn () => app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'Shared event']));

        return [$project, ...$endpoints];
    }

    public function test_delete_cascades_history_attempts_and_project_selection_only_for_target_endpoint(): void
    {
        [, $deleted, $kept] = $this->fixture();
        Http::fake(['*' => Http::response('', 400)]);
        $this->assertSame(2, app(WebhookProcessor::class)->process());
        $keptDelivery = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $kept->id)->first();
        $this->assertDatabaseCount('webhook_attempts', 2);
        $this->deleteJson('/api/admin/integrations/webhooks/'.$deleted->uuid)->assertOk();
        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $deleted->id]);
        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_endpoint_id' => $deleted->id]);
        $this->assertDatabaseMissing('webhook_endpoint_projects', ['webhook_endpoint_id' => $deleted->id]);
        $this->assertDatabaseCount('webhook_attempts', 1);
        $this->assertDatabaseHas('webhook_attempts', ['webhook_delivery_id' => $keptDelivery->id]);
        $this->assertDatabaseHas('webhook_endpoints', ['id' => $kept->id]);
        $this->assertDatabaseHas('webhook_endpoint_projects', ['webhook_endpoint_id' => $kept->id]);
        $this->assertDatabaseHas('webhook_events', ['id' => $keptDelivery->webhook_event_id]);
        $this->getJson('/api/admin/integrations/webhooks/'.$deleted->uuid.'/deliveries')->assertNotFound();
        $this->travel(61)->seconds();
        $this->postJson('/api/admin/integrations/webhooks/'.$deleted->uuid.'/test')->assertNotFound();
        $this->deleteJson('/api/admin/integrations/webhooks/'.$deleted->uuid)->assertNotFound();
        $this->getJson('/api/admin/integrations/webhooks/'.$kept->uuid.'/deliveries')->assertOk()->assertJsonCount(1, 'data.0.history');
    }

    public function test_deleted_pending_and_processing_deliveries_cannot_send_after_lease_expires(): void
    {
        [, $deleted, $kept] = $this->fixture();
        DB::table('webhook_deliveries')->where('webhook_endpoint_id', $deleted->id)->update(['status' => 'processing', 'available_at' => now()->addSeconds(90)]);
        DB::table('integration_locks')->insert(['name' => 'webhooks', 'owner' => 'old-worker', 'expires_at' => now()->addSeconds(90)]);
        $this->deleteJson('/api/admin/integrations/webhooks/'.$deleted->uuid)->assertOk();
        $this->assertSame('old-worker', DB::table('integration_locks')->value('owner'));
        $this->travel(91)->seconds();
        Http::fake(['*' => Http::response('', 204)]);
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_endpoint_id' => $kept->id, 'status' => 'succeeded']);
        $this->assertSame(0, app(WebhookProcessor::class)->process());
    }

    public function test_forbidden_or_cross_workspace_delete_preserves_all_endpoint_data(): void
    {
        [$project, $deleted] = $this->fixture();
        $this->actingAs(User::factory()->memberOf($project->workspace)->create())->deleteJson('/api/admin/integrations/webhooks/'.$deleted->uuid)->assertForbidden();
        $other = Workspace::factory()->create();
        $this->actingAs(User::factory()->owner($other)->create())->withSession(['workspace_id' => $other->id])->deleteJson('/api/admin/integrations/webhooks/'.$deleted->uuid)->assertNotFound();
        $this->assertDatabaseCount('webhook_endpoints', 2);
        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->assertDatabaseCount('webhook_endpoint_projects', 2);
    }
}
