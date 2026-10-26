<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\IntegrationTokens;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookProjectFiltersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'crm.example.com', 'ip' => '93.184.216.34']);
        $one = Project::factory()->create();
        $two = Project::factory()->for($one->workspace)->create();
        $foreign = Project::factory()->create();
        $this->actingAs($this->ownerFor($one));

        return [$one, $two, $foreign];
    }

    private function endpoint(array $extra = []): string
    {
        return $this->postJson('/api/admin/integrations/webhooks', ['name' => 'CRM', 'url' => 'https://crm.example.com/events', 'events' => ['project.updated'], ...$extra])->assertCreated()->json('id');
    }

    private function queue(Project $project): void
    {
        app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'Updated']);
    }

    public function test_all_includes_future_projects_selected_only_includes_its_workspace_projects(): void
    {
        [$one, $two, $foreign] = $this->fixture();
        $all = $this->endpoint();
        $selected = $this->endpoint(['projectFilter' => 'selected', 'projectIds' => [$one->uuid]]);
        $future = Project::factory()->for($one->workspace)->create();
        foreach ([$one, $two, $foreign, $future] as $project) {
            $this->queue($project);
        }
        $this->assertDatabaseCount('webhook_events', 3);
        $this->assertDatabaseCount('webhook_deliveries', 4);
        $selectedRow = DB::table('webhook_endpoints')->where('uuid', $selected)->first();
        $this->assertSame(1, DB::table('webhook_deliveries')->where('webhook_endpoint_id', $selectedRow->id)->count());
        $state = $this->getJson('/api/admin/integrations')->assertOk()->assertJsonCount(3, 'projects')->assertDontSee($foreign->uuid)->json();
        $this->assertSame('all', collect($state['webhooks'])->firstWhere('id', $all)['projectFilter']);
        $this->assertSame([$one->uuid], collect($state['webhooks'])->firstWhere('id', $selected)['projectIds']);
    }

    public function test_invalid_empty_foreign_and_duplicate_selection_are_rejected_without_leaks(): void
    {
        [$one, $two, $foreign] = $this->fixture();
        foreach ([[], [$foreign->uuid], [$one->uuid, $foreign->uuid], [$one->uuid, $one->uuid], ['not-a-uuid']] as $ids) {
            $this->postJson('/api/admin/integrations/webhooks', ['name' => 'CRM', 'url' => 'https://crm.example.com/events', 'events' => ['project.updated'], 'projectFilter' => 'selected', 'projectIds' => $ids])->assertUnprocessable()->assertDontSee($foreign->title);
        }
        $this->assertDatabaseCount('webhook_endpoints', 0);
        $uuid = $this->endpoint();
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectFilter' => 'selected'])->assertUnprocessable();
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectFilter' => 'all', 'projectIds' => [$one->uuid]])->assertUnprocessable();
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectFilter' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseHas('webhook_endpoints', ['uuid' => $uuid, 'project_filter' => 'all', 'secret_version' => 1]);
    }

    public function test_filter_change_cancels_pending_blocks_failed_retry_and_preserves_immutable_event(): void
    {
        [$one, $two] = $this->fixture();
        $uuid = $this->endpoint();
        $this->queue($two);
        Http::fakeSequence()->push('', 400);
        app(WebhookProcessor::class)->process();
        $failed = DB::table('webhook_deliveries')->first();
        $event = DB::table('webhook_events')->first();
        $this->queue($two);
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectFilter' => 'selected', 'projectIds' => [$one->uuid]])->assertOk();
        $this->assertDatabaseHas('webhook_endpoints', ['secret_version' => 2]);
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'cancelled']);
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries/'.$failed->uuid.'/retry')->assertConflict();
        $this->getJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries')->assertOk()->assertJsonPath('data.1.canRetry', false);
        $this->assertSame($event->body, DB::table('webhook_events')->find($event->id)->body);
        $this->assertSame($event->uuid, DB::table('webhook_events')->find($event->id)->uuid);
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectIds' => [$two->uuid, $one->uuid]])->assertOk();
        $version = DB::table('webhook_endpoints')->first()->secret_version;
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectIds' => [$one->uuid, $two->uuid]])->assertOk();
        $this->assertSame($version, DB::table('webhook_endpoints')->first()->secret_version);
        $this->queue($two);
        $this->assertSame(1, DB::table('webhook_deliveries')->where('status', 'pending')->count());
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectFilter' => 'all', 'projectIds' => []])->assertOk();
        $this->assertSame(0, DB::table('webhook_deliveries')->where('status', 'pending')->count());
    }

    public function test_test_delivery_bypasses_selected_filter_but_disable_and_rotation_cancel_pending(): void
    {
        [$one, $two] = $this->fixture();
        $uuid = $this->endpoint(['projectFilter' => 'selected', 'projectIds' => [$one->uuid]]);
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/test')->assertStatus(202);
        $payload = json_decode(DB::table('webhook_events')->first()->body, true);
        $this->assertNull($payload['projectId']);
        Http::fake(['*' => Http::response('', 200)]);
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        $this->queue($one);
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/rotate')->assertOk();
        $this->queue($one);
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['enabled' => false])->assertOk();
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        Http::assertSentCount(1);
    }

    public function test_cascaded_project_deletion_fails_closed_for_pending_and_manual_retry(): void
    {
        [$one, $two] = $this->fixture();
        $uuid = $this->endpoint(['projectFilter' => 'selected', 'projectIds' => [$one->uuid]]);
        $this->queue($one);
        Http::fake(['*' => Http::response('', 400)]);
        app(WebhookProcessor::class)->process();
        $failed = DB::table('webhook_deliveries')->first();
        $this->queue($one);
        $one->forceDelete();
        $this->assertDatabaseCount('webhook_endpoint_projects', 0);
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries/'.$failed->uuid.'/retry')->assertConflict();
        Http::fake();
        app(WebhookProcessor::class)->process();
        Http::assertNothingSent();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'cancelled', 'error_code' => 'project_filtered']);
        $this->queue($two);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonPath('webhooks.0.projectFilter', 'selected')->assertJsonCount(0, 'webhooks.0.projectIds');
    }

    public function test_database_constraints_prevent_cross_tenant_endpoint_project_links(): void
    {
        [$one, $two, $foreign] = $this->fixture();
        $uuid = $this->endpoint();
        $endpoint = DB::table('webhook_endpoints')->where('uuid', $uuid)->first();
        foreach ([$one->workspace_id, $foreign->workspace_id] as $workspace) {
            try {
                DB::transaction(fn () => DB::table('webhook_endpoint_projects')->insert(['webhook_endpoint_id' => $endpoint->id, 'workspace_id' => $workspace, 'project_id' => $foreign->id]));
                $this->fail('Cross-workspace FK accepted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('webhook_endpoint_projects', 0);
            }
        }
    }

    public function test_only_workspace_owner_can_read_and_change_filter_and_tokens_remain_workspace_scoped(): void
    {
        [$one, $two, $foreign] = $this->fixture();
        $uuid = $this->endpoint(['projectFilter' => 'selected', 'projectIds' => [$one->uuid]]);
        $token = app(IntegrationTokens::class)->create($one->workspace_id, ['name' => 'CRM', 'scopes' => ['projects:read']])['secret'];
        $this->getJson('/api/v1/projects', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs(User::factory()->memberOf($one->workspace)->create());
        $this->getJson('/api/admin/integrations')->assertForbidden();
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectIds' => [$two->uuid]])->assertForbidden();
        $this->actingAs($this->ownerFor($foreign))->withSession(['workspace_id' => $foreign->workspace_id]);
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['projectIds' => [$foreign->uuid]])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/integrations')->assertUnauthorized();
    }

    public function test_additive_migration_defaults_legacy_endpoints_to_all_and_preserves_history_and_subscriptions(): void
    {
        [$one] = $this->fixture();
        $uuid = $this->endpoint();
        $this->queue($one);
        Http::fake(['*' => Http::response('', 200)]);
        app(WebhookProcessor::class)->process();
        $migration = require database_path('migrations/2026_10_26_081255_add_webhook_project_filters.php');
        $migration->down();
        $before = DB::table('webhook_endpoints')->where('uuid', $uuid)->first();
        $event = DB::table('webhook_events')->first();
        $delivery = DB::table('webhook_deliveries')->first();
        $attempt = DB::table('webhook_attempts')->first();
        $migration->up();
        $after = DB::table('webhook_endpoints')->where('uuid', $uuid)->first();
        $this->assertSame('all', $after->project_filter);
        foreach (get_object_vars($before) as $key => $value) {
            $this->assertSame($value, $after->$key);
        }
        $this->assertEquals($event, DB::table('webhook_events')->first());
        $this->assertEquals($delivery, DB::table('webhook_deliveries')->first());
        $this->assertEquals($attempt, DB::table('webhook_attempts')->first());
        $this->assertSame(['project.updated'], json_decode($after->events, true));
    }
}
