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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'crm.example.com', 'ip' => '93.184.216.34']);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $response = $this->postJson('/api/admin/integrations/webhooks', ['name' => 'CRM', 'url' => 'https://crm.example.com/events', 'events' => WebhookEvents::TYPES])->assertCreated();

        return [$project, $response->json('id'), $response->json('secret')];
    }

    private function queue(Project $project): void
    {
        DB::transaction(fn () => app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'Новый заголовок']));
    }

    public function test_transactional_outbox_rollback_and_noop_without_network_in_user_request(): void
    {
        [$project] = $this->fixture();
        Http::fake();
        DB::beginTransaction();
        $this->queue($project);
        $this->assertDatabaseCount('webhook_events', 1);
        DB::rollBack();
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->patchJson('/api/admin/projects/'.$project->uuid, ['title' => $project->title])->assertOk();
        $this->assertDatabaseCount('webhook_events', 0);
        $this->patchJson('/api/admin/projects/'.$project->uuid, ['title' => 'Changed'])->assertOk();
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        Http::assertNothingSent();
    }

    public function test_ui_and_api_creation_and_status_and_client_approval_have_distinct_events(): void
    {
        [$project] = $this->fixture();
        Http::fake();
        $taskData = ['title' => 'Gallery', 'location' => 'Общее', 'description' => 'Pictures', 'priority' => 'normal'];
        $task = $this->postJson('/api/admin/projects/'.$project->uuid.'/tasks', $taskData)->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/tasks/'.$task, ['description' => 'Changed public content'])->assertOk();
        $this->patchJson('/api/admin/tasks/'.$task, ['description' => 'Changed public content'])->assertOk();
        $this->patchJson('/api/admin/tasks/'.$task, ['status' => 'approved'])->assertOk();
        $this->patchJson('/api/admin/tasks/'.$task, ['status' => 'approved'])->assertOk();
        $this->patchJson('/api/admin/tasks/'.$task, ['estimateHours' => 5, 'price' => 111, 'developerNotes' => 'VERY-PRIVATE'])->assertOk();
        $this->assertSame(['task.created', 'task.updated', 'task.status_changed'], DB::table('webhook_events')->pluck('type')->all());
        $this->app['auth']->forgetGuards();
        $client = $project->clients->first();
        $access = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', bin2hex(random_bytes(32)))]);
        $this->withSession(['client_access_token_id' => $access->id, 'project_client_id' => $client->id, 'client_project_id' => $project->id])->postJson('/api/client/tasks/'.$task.'/approve')->assertOk();
        $this->postJson('/api/client/tasks/'.$task.'/approve')->assertOk();
        $this->assertSame(['task.created', 'task.updated', 'task.status_changed', 'task.approved'], DB::table('webhook_events')->pluck('type')->all());
        $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'API', 'scopes' => IntegrationTokens::SCOPES])['secret'];
        $this->postJson('/api/v1/projects/'.$project->uuid.'/tasks', $taskData, ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'api-task'])->assertCreated();
        $event = DB::table('webhook_events')->latest('id')->first();
        $payload = json_decode($event->body, true);
        $this->assertSame('api', $payload['origin']);
        $this->assertSame('integration', $payload['actor']['type']);
        foreach (DB::table('webhook_events')->pluck('body') as $body) {
            $this->assertStringNotContainsString('VERY-PRIVATE', $body);
            $this->assertStringNotContainsString('price', $body);
            $this->assertStringNotContainsString('estimate', $body);
        }
        Http::assertNothingSent();
    }

    public function test_shared_project_creation_emits_for_ui_and_api_without_client_details(): void
    {
        [$project] = $this->fixture();
        $this->postJson('/api/admin/projects', ['title' => 'UI', 'clientEmail' => 'private@example.test'])->assertCreated();
        $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'API', 'scopes' => ['projects:write']])['secret'];
        $data = ['title' => 'API', 'clientName' => 'Private client', 'clientEmail' => 'private@example.test'];
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'new-project'];
        $this->postJson('/api/v1/projects', $data, $headers)->assertCreated();
        $this->postJson('/api/v1/projects', $data, $headers)->assertCreated();
        $this->assertSame(['project.created', 'project.created'], DB::table('webhook_events')->pluck('type')->all());
        foreach (DB::table('webhook_events')->pluck('body') as $body) {
            $this->assertStringNotContainsString('private@example.test', $body);
            $this->assertStringNotContainsString('Private client', $body);
        }
    }

    public function test_signature_matches_raw_bytes_and_retry_keeps_event_delivery_and_body(): void
    {
        [$project, $uuid, $secret] = $this->fixture();
        $this->queue($project);
        Http::fakeSequence()->push('', 429)->push('', 500)->push('', 204);
        $body = DB::table('webhook_events')->first()->body;
        $eventId = DB::table('webhook_events')->first()->uuid;
        $deliveryId = DB::table('webhook_deliveries')->first()->uuid;
        $processor = app(WebhookProcessor::class);
        $this->assertSame(1, $processor->process());
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'pending', 'attempts' => 1, 'http_status' => 429]);
        $this->assertSame(0, $processor->process());
        $this->travel(31)->seconds();
        $processor->process();
        $this->travel(61)->seconds();
        $processor->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'succeeded', 'attempts' => 3]);
        $this->assertDatabaseCount('webhook_attempts', 3);
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($body, $request->body());
            $this->assertSame($deliveryId, $request->header('X-ProjectBrief-Delivery')[0]);
            $timestamp = $request->header('X-ProjectBrief-Timestamp')[0];
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $request->header('X-ProjectBrief-Signature')[0]);
            $this->assertSame($eventId, json_decode($request->body(), true)['id']);
        }
        $this->getJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries')->assertOk()->assertJsonPath('data.0.status', 'succeeded')->assertJsonCount(3, 'data.0.history')->assertDontSee($secret);
    }

    public function test_connection_errors_have_bounded_backoff_and_terminal_failure(): void
    {
        [$project] = $this->fixture();
        $this->queue($project);
        Http::fake(fn () => throw new ConnectionException('secret response should not be logged'));
        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(1, app(WebhookProcessor::class)->process());
            $this->travel(3601)->seconds();
        }
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'failed', 'attempts' => 6, 'error_code' => 'transport_error']);
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        $this->assertDatabaseCount('webhook_attempts', 6);
        $this->assertStringNotContainsString('secret response', json_encode(DB::table('webhook_attempts')->get()));
    }

    public function test_permanent_4xx_and_redirect_are_not_retried_and_manual_retry_preserves_event(): void
    {
        [$project, $uuid] = $this->fixture();
        $this->queue($project);
        Http::fakeSequence()->push('sensitive third party response', 400)->push('', 302, ['Location' => 'http://127.0.0.1/'])->push('', 200);
        app(WebhookProcessor::class)->process();
        $delivery = DB::table('webhook_deliveries')->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertStatus(202);
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'failed', 'http_status' => 302]);
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertStatus(202);
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['uuid' => $delivery->uuid, 'webhook_event_id' => $delivery->webhook_event_id, 'status' => 'succeeded']);
        $this->getJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries')->assertDontSee('sensitive third party response');
    }

    public function test_disabled_rotated_and_changed_endpoints_cancel_old_deliveries_and_secrets_are_encrypted(): void
    {
        [$project, $uuid, $secret] = $this->fixture();
        Http::fake();
        $this->assertNotSame($secret, DB::table('webhook_endpoints')->first()->secret);
        $this->assertSame($secret, Crypt::decryptString(DB::table('webhook_endpoints')->first()->secret));
        $this->getJson('/api/admin/integrations')->assertDontSee($secret)->assertDontSee(DB::table('webhook_endpoints')->first()->secret);
        $this->queue($project);
        $new = $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/rotate')->assertOk()->json('secret');
        $this->assertNotSame($secret, $new);
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'cancelled']);
        $this->queue($project);
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['enabled' => false])->assertOk();
        $this->queue($project);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['enabled' => true])->assertOk();
        $this->queue($project);
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['url' => 'https://crm.example.com/new'])->assertOk();
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        Http::assertNothingSent();
    }

    public function test_event_selection_tenant_isolation_and_test_delivery(): void
    {
        [$project, $uuid] = $this->fixture();
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['events' => ['task.approved']])->assertOk();
        $this->queue($project);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/test')->assertStatus(202)->assertJsonPath('queued', true);
        $this->assertDatabaseHas('webhook_events', ['type' => 'webhook.test']);
        $other = Workspace::factory()->create();
        $this->actingAs(User::factory()->owner($other)->create())->withSession(['workspace_id' => $other->id]);
        foreach (['rotate', 'test'] as $action) {
            $this->postJson('/api/admin/integrations/webhooks/'.$uuid.'/'.$action)->assertNotFound();
        }
        $this->getJson('/api/admin/integrations/webhooks/'.$uuid.'/deliveries')->assertNotFound();
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['enabled' => false])->assertNotFound();
        $this->deleteJson('/api/admin/integrations/webhooks/'.$uuid)->assertNotFound();
    }

    public function test_processor_obeys_lock_limit_and_cron_command(): void
    {
        [$project] = $this->fixture();
        $this->queue($project);
        $this->queue($project);
        Http::fake(['*' => Http::response('', 200)]);
        DB::table('integration_locks')->insert(['name' => 'webhooks', 'owner' => 'another-process', 'expires_at' => now()->addSeconds(90)]);
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        Http::assertNothingSent();
        $this->travel(91)->seconds();
        $this->artisan('integrations:webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('webhook_deliveries')->where('status', 'pending')->count());
        $this->artisan('integrations:webhooks')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_destination_checks_public_and_reserved_ranges_and_mixed_dns(): void
    {
        $guard = new WebhookDestination;
        foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.2', '169.254.169.254', '100.64.0.1', '0.1.2.3', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::1', '::ffff:127.0.0.1', 'fe80::1', 'fc00::1', '2001:db8::1', '2002::1'] as $ip) {
            $this->assertFalse($guard->publicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue($guard->publicIp($ip), $ip);
        }
        $guard = new class extends WebhookDestination
        {
            public function addresses(string $host): array
            {
                return ['8.8.8.8', '127.0.0.1'];
            }
        };
        foreach (['http://crm.example.com', 'https://localhost', 'https://127.0.0.1', 'https://user:pass@crm.example.com', 'https://crm.example.com:8443', 'https://crm.example.com/#fragment', 'https://crm.example.com'] as $url) {
            try {
                $guard->resolve($url);
                $this->fail('Accepted unsafe URL: '.$url);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_transport_pins_checked_dns_disables_proxies_and_redirects_and_bounds_response(): void
    {
        [$project] = $this->fixture();
        $this->queue($project);
        Http::fake(function (HttpRequest $request, array $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['decode_content']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(['crm.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(8, $options['timeout']);
            try {
                $options['progress'](0, 16385);
                $this->fail('Oversized response accepted.');
            } catch (\RuntimeException $e) {
                $this->assertSame('response_too_large', $e->getMessage());
            }

            return Http::response('', 200);
        });
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'succeeded']);
    }

    public function test_dns_is_rechecked_at_delivery_and_private_rebinding_is_terminal(): void
    {
        [$project] = $this->fixture();
        $this->queue($project);
        Http::fake();
        $this->instance(WebhookDestination::class, new class extends WebhookDestination
        {
            public function addresses(string $host): array
            {
                return ['169.254.169.254'];
            }
        });
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'failed', 'error_code' => 'unsafe_destination']);
        Http::assertNothingSent();
    }
}
