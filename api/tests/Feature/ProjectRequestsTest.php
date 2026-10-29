<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Mail\ProjectActivityMail;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use App\Services\ProjectNotificationService;
use App\Services\SupportedLocales;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use App\Services\WebhookProjectFilter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProjectRequestsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ROOT = '/api/v1/project-requests';

    private function input(): array
    {
        return ['externalId' => 'web86-request-105', 'client' => ['name' => 'Ada', 'email' => 'ADA@example.test', 'company' => 'Example Ltd', 'website' => 'https://example.test'], 'project' => ['name' => 'Company website', 'description' => 'A catalog of services', 'language' => 'ru']];
    }

    private function token(Workspace $workspace, array $scopes = ['requests:create', 'requests:read', 'requests:approve'], ?int $sourceId = null): array
    {
        $sourceId ??= DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'web86.site', 'created_at' => now(), 'updated_at' => now()]);

        return app(IntegrationTokens::class)->create($workspace->id, ['name' => 'MODX', 'sourceId' => $sourceId, 'scopes' => $scopes]);
    }

    private function headers(array $token, string $key = 'request-105'): array
    {
        return ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => $key];
    }

    public function test_request_is_not_a_project_contact_membership_or_access(): void
    {
        Mail::fake();
        $workspace = Workspace::factory()->create();
        $response = $this->postJson(self::ROOT, $this->input(), $this->headers($this->token($workspace)))->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('project_requests', ['uuid' => $response->json('data.requestId'), 'workspace_id' => $workspace->id, 'email' => 'ada@example.test']);
        foreach (['projects', 'workspace_clients', 'project_clients', 'project_access_tokens', 'project_provisionings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('provisioning_operations', ['operation_type' => 'request.create', 'project_request_uuid' => $response->json('data.requestId')]);
        Mail::assertNothingSent();
    }

    public function test_idempotency_and_source_survive_token_replacement_without_overwrite(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $data = $this->input();
        $first = $this->postJson(self::ROOT, $data, $this->headers($token))->assertCreated()->json();
        $this->postJson(self::ROOT, $data, $this->headers($token))->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first);
        $data['project']['name'] = 'Changed';
        $this->postJson(self::ROOT, $data, $this->headers($token))->assertConflict();
        $this->postJson(self::ROOT, $data, $this->headers($token, 'changed-key'))->assertConflict();
        $old = DB::table('integration_tokens')->where('uuid', $token['id'])->first();
        DB::table('integration_tokens')->where('id', $old->id)->update(['revoked_at' => now()]);
        $replacement = $this->token($workspace, sourceId: $old->integration_source_id);
        $this->postJson(self::ROOT, $this->input(), $this->headers($replacement))->assertOk()->assertExactJson($first);
        $this->postJson(self::ROOT, $this->input(), $this->headers($token))->assertUnauthorized();
        $this->assertDatabaseCount('project_requests', 1);
        $this->assertDatabaseHas('project_requests', ['project_name' => 'Company website']);
        $this->postJson(self::ROOT, $this->input(), $this->headers($this->token($workspace)))->assertCreated();
        $this->assertDatabaseCount('project_requests', 2);
    }

    public function test_approval_invites_and_replays_without_duplicate_records(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $id = $this->postJson(self::ROOT, $this->input(), $this->headers($token))->json('data.requestId');
        $url = self::ROOT.'/'.$id.'/approve';
        $result = $this->postJson($url, [], $this->headers($token, 'approve'))->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.invitation', 'sent')->json('data');
        $this->postJson($url, [], $this->headers($token, 'approve'))->assertOk()->assertJsonPath('data.projectId', $result['projectId']);
        $this->postJson($url, ['sendInvitation' => true], $this->headers($token, 'recover'))->assertOk()->assertJsonPath('data.projectId', $result['projectId']);
        $this->postJson($url, ['sendInvitation' => false], $this->headers($token, 'different'))->assertConflict();
        foreach (['projects', 'workspace_clients', 'project_clients', 'project_provisionings', 'project_access_tokens'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $row = ProjectRequest::first();
        $this->assertNotNull($row->reviewed_at);
        $this->assertSame($token['id'], $row->decided_by_uuid);
        Mail::assertSent(AccountActionMail::class, 1);
        $mail = Mail::sent(AccountActionMail::class)->first();
        $this->get('/access/'.basename($mail->actionUrl))->assertRedirect($result['projectUrl']);
        $this->getJson('/api/client/project/'.$result['projectId'])->assertOk();
    }

    public function test_existing_contact_is_reused_without_issuing_access_before_mailbox_proof(): void
    {
        Mail::fake();
        $legacy = Project::factory()->create(['client_name' => 'Existing', 'client_email' => 'ada@example.test']);
        $token = $this->token($legacy->workspace);
        $id = $this->postJson(self::ROOT, $this->input(), $this->headers($token))->json('data.requestId');
        $first = $this->postJson(self::ROOT.'/'.$id.'/approve', ['sendInvitation' => false], $this->headers($token, 'approve'))->assertOk()->assertJsonPath('data.invitation', 'not_requested')->json('data');
        $data = $this->input();
        $data['externalId'] = 'request-106';
        $id = $this->postJson(self::ROOT, $data, $this->headers($token, '106'))->json('data.requestId');
        $second = $this->postJson(self::ROOT.'/'.$id.'/approve', ['sendInvitation' => false], $this->headers($token, 'approve106'))->assertOk()->json('data');
        $this->assertSame($first['clientId'], $second['clientId']);
        $this->assertNotSame($first['projectId'], $second['projectId']);
        $this->assertDatabaseHas('workspace_clients', ['name' => 'Existing']);
        $this->assertDatabaseCount('workspace_clients', 1);
        $this->assertDatabaseCount('project_access_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_smtp_failure_preserves_approval_and_does_not_resend(): void
    {
        $row = ProjectRequest::factory()->create();
        $token = $this->token($row->workspace, ['requests:approve']);
        $url = self::ROOT.'/'.$row->uuid.'/approve';
        $result = $this->postJson($url, [], $this->headers($token))->assertOk()->assertJsonPath('data.invitation', 'failed')->json('data');
        $this->postJson($url, [], $this->headers($token, 'recover'))->assertOk()->assertJsonPath('data.projectId', $result['projectId'])->assertJsonPath('data.invitation', 'failed');
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseMissing('project_access_tokens', ['revoked_at' => null]);
        $this->assertSame('approved', $row->fresh()->status);
    }

    public function test_rejection_is_final_and_conflicting_decisions_cannot_create_access(): void
    {
        $row = ProjectRequest::factory()->create();
        $token = $this->token($row->workspace);
        $url = self::ROOT.'/'.$row->uuid;
        $this->postJson($url.'/reject', ['reason' => 'Outside scope'], $this->headers($token))->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision.reason', 'Outside scope');
        $this->postJson($url.'/reject', ['reason' => 'Outside scope'], $this->headers($token, 'recover'))->assertOk();
        $this->postJson($url.'/reject', ['reason' => 'Changed'], $this->headers($token, 'changed'))->assertConflict();
        $this->postJson($url.'/approve', [], $this->headers($token, 'approve'))->assertConflict();
        $this->assertNotNull($row->fresh()->reviewed_at);
        foreach (['projects', 'project_clients', 'project_access_tokens'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[TestWith(['requests:create'])] #[TestWith(['requests:read'])] #[TestWith(['projects:provision'])]
    public function test_non_decision_scopes_cannot_approve_or_reject(string $scope): void
    {
        $row = ProjectRequest::factory()->create();
        $headers = $this->headers($this->token($row->workspace, [$scope]));
        $this->postJson(self::ROOT.'/'.$row->uuid.'/approve', [], $headers)->assertForbidden();
        $this->postJson(self::ROOT.'/'.$row->uuid.'/reject', [], $headers)->assertForbidden();
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_decision_scope_cannot_provision_directly_and_create_scope_cannot_read_private_decisions(): void
    {
        $workspace = Workspace::factory()->create();
        $create = $this->token($workspace, ['requests:create']);
        $decision = $this->token($workspace, ['requests:approve']);
        $id = $this->postJson(self::ROOT, $this->input(), $this->headers($create))->json('data.requestId');
        $this->getJson(self::ROOT, $this->headers($create))->assertForbidden();
        $this->getJson(self::ROOT.'/'.$id, $this->headers($create))->assertForbidden();
        $this->postJson('/api/v1/projects/provision', [...$this->input(), 'sendInvitation' => false], $this->headers($decision))->assertForbidden();
        $this->postJson(self::ROOT.'/'.$id.'/reject', ['reason' => 'INTERNAL PRIVATE'], $this->headers($decision))->assertOk();
        $this->postJson(self::ROOT, $this->input(), $this->headers($create))->assertCreated()->assertDontSee('INTERNAL PRIVATE')->assertJsonMissingPath('data.decision');
        $this->getJson(self::ROOT, $this->headers($decision))->assertForbidden();
    }

    public function test_reads_decisions_and_listing_are_scoped_and_bounded(): void
    {
        $row = ProjectRequest::factory()->create(['company' => 'Needle Co']);
        $foreign = ProjectRequest::factory()->create();
        $headers = $this->headers($this->token($row->workspace));
        $this->getJson(self::ROOT.'?search=', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ROOT.'?search=Needle&status=pending&sort=asc&perPage=1', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $row->uuid)->assertJsonPath('meta.total', 1);
        $this->getJson(self::ROOT.'/'.$foreign->uuid, $headers)->assertNotFound();
        $this->postJson(self::ROOT.'/'.$foreign->uuid.'/approve', [], $headers)->assertNotFound();
        $this->postJson(self::ROOT.'/'.$foreign->uuid.'/reject', [], $headers)->assertNotFound();
        $this->getJson(self::ROOT.'?sort=created_at%20DESC;DROP&perPage=101', $headers)->assertUnprocessable();
        $this->getJson(self::ROOT.'?search=%25', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::ROOT.'/'.$row->uuid)->assertUnauthorized();
    }

    #[TestWith(['admin'])]
    public function test_non_owners_have_no_application_access(string $role): void
    {
        $row = ProjectRequest::factory()->create();
        $user = User::factory()->memberOf($row->workspace, $role)->create();
        $user->workspaceMembers()->update(['can_create_projects' => true]);
        $this->actingAs($user)->getJson('/api/admin/project-requests')->assertForbidden();
        $this->getJson('/api/admin/project-requests/count')->assertForbidden();
        $this->getJson('/api/admin/project-requests/'.$row->uuid)->assertForbidden();
        $this->postJson('/api/admin/project-requests/'.$row->uuid.'/approve', [])->assertForbidden();
        $this->postJson('/api/admin/project-requests/'.$row->uuid.'/reject', [])->assertForbidden();
    }

    public function test_ui_and_api_share_the_final_decision_and_original_author(): void
    {
        Mail::fake();
        $row = ProjectRequest::factory()->create();
        $owner = User::factory()->owner($row->workspace)->create();
        $token = $this->token($row->workspace, ['requests:approve']);
        $this->actingAs($owner);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('pendingRequests', 1);
        $url = '/api/admin/project-requests/'.$row->uuid;
        $first = $this->postJson($url.'/approve', ['sendInvitation' => false])->assertOk()->assertJsonPath('data.decision.actor.id', $owner->uuid)->json('data');
        $this->postJson(self::ROOT.'/'.$row->uuid.'/approve', ['sendInvitation' => false], $this->headers($token))->assertOk()->assertJsonPath('data.projectId', $first['projectId'])->assertJsonPath('data.decision.actor.id', $owner->uuid);
        $this->postJson($url.'/reject', [])->assertConflict();
        $this->getJson('/api/admin/project-requests/count')->assertOk()->assertJsonPath('pending', 0);
        $this->assertDatabaseCount('projects', 1);
        Mail::assertNothingSent();
    }

    public function test_business_failure_rolls_back_decision_contact_project_and_outbox(): void
    {
        $row = ProjectRequest::factory()->create();
        $token = $this->token($row->workspace, ['requests:approve']);
        ProjectClient::creating(fn () => throw new \RuntimeException('Injected membership failure'));
        $this->postJson(self::ROOT.'/'.$row->uuid.'/approve', [], $this->headers($token))->assertStatus(500);
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->reviewed_at);
        foreach (['projects', 'workspace_clients', 'project_clients', 'project_provisionings', 'webhook_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_sources_are_owner_scoped_and_old_tokens_do_not_gain_permissions(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->owner($workspace)->create();
        $legacy = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Old', 'scopes' => ['projects:read']]);
        $this->actingAs($owner)->postJson('/api/admin/integrations/tokens', ['name' => 'Form', 'scopes' => ['requests:create']])->assertUnprocessable();
        $created = $this->postJson('/api/admin/integrations/tokens', ['name' => 'Form', 'scopes' => ['requests:create'], 'sourceName' => 'web86.site'])->assertCreated();
        $source = DB::table('integration_sources')->first();
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'Replacement', 'scopes' => ['requests:create'], 'sourceId' => $source->uuid])->assertCreated();
        $this->assertDatabaseCount('integration_sources', 1);
        $foreign = ProjectRequest::factory()->create();
        $foreignSource = DB::table('integration_sources')->find($foreign->integration_source_id);
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'Invalid', 'scopes' => ['requests:create'], 'sourceId' => $foreignSource->uuid])->assertUnprocessable();
        $this->getJson('/api/admin/integrations')->assertOk()->assertDontSee($created->json('secret'))->assertJsonPath('sources.0.id', $source->uuid);
        $old = DB::table('integration_tokens')->where('uuid', $legacy['id'])->first();
        $this->assertSame(['projects:read'], json_decode($old->scopes, true));
        $this->assertNull($old->integration_source_id);
    }

    #[TestWith(['project.language', 'de'])]
    #[TestWith(['client.website', 'javascript:alert(1)'])]
    #[TestWith(['client.email', 'invalid'])]
    #[TestWith(['project.name', '   '])]
    #[TestWith(['project.status', 'approved'])]
    #[TestWith(['sendInvitation', true])]
    public function test_creation_rejects_invalid_and_unexpected_fields(string $path, mixed $value): void
    {
        $token = $this->token(Workspace::factory()->create());
        $data = $this->input();
        data_set($data, $path, $value);
        $this->postJson(self::ROOT, $data, $this->headers($token))->assertUnprocessable();
        $this->assertDatabaseCount('project_requests', 0);
    }

    public function test_payload_limit_idempotency_key_and_rate_limit_are_enforced(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $headers = $this->headers($token);
        $this->postJson(self::ROOT, $this->input(), ['Authorization' => $headers['Authorization']])->assertUnprocessable();
        $data = $this->input();
        $data['project']['description'] = str_repeat('x', 33000);
        $this->postJson(self::ROOT, $data, $headers)->assertStatus(413);
        for ($attempt = 0; $attempt < 60; $attempt++) {
            RateLimiter::hit('integration-workspace:'.$workspace->id, 60);
        }
        $this->postJson(self::ROOT, $this->input(), $headers)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('project_requests', 0);
    }

    #[TestWith(['ru'])]
    #[TestWith(['en'])]
    #[TestWith(['tr'])]
    #[TestWith(['zh-CN'])]
    public function test_request_notifications_use_owner_preferences_and_delivery_deduplication(string $locale): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        $row = ProjectRequest::factory()->create();
        $owner = User::factory()->owner($row->workspace)->create(['preferred_locale' => SupportedLocales::storage($locale)]);
        User::factory()->memberOf($row->workspace)->create();
        User::factory()->owner($row->workspace)->create(['notification_preferences' => ['email' => false, 'push' => false, 'telegram' => false]]);
        User::factory()->owner()->create();
        app(ProjectNotificationService::class)->notifyRequest($row);
        app(ProjectNotificationService::class)->notifyRequest($row);
        Mail::assertSent(ProjectActivityMail::class, 1);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->payload['heading'] === trans('notifications.project_request_heading', [], $locale));
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseHas('notification_deliveries', ['project_request_id' => $row->id, 'project_id' => null, 'event' => 'project_request.created', 'status' => 'sent']);
    }

    public function test_request_webhooks_have_no_contact_text_and_preserve_project_filter_semantics(): void
    {
        $row = ProjectRequest::factory()->create();
        $token = $this->token($row->workspace);
        $endpoint = DB::table('webhook_endpoints')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $row->workspace_id, 'name' => 'CRM', 'url' => 'https://example.test/hook',
            'events' => json_encode(['project_request.created', 'project_request.approved', 'project_request.rejected']), 'enabled' => true, 'secret' => Crypt::encryptString('synthetic'), 'secret_version' => 1, 'project_filter' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        app(WebhookEvents::class)->projectRequest($row, 'created');
        $this->postJson(self::ROOT.'/'.$row->uuid.'/approve', ['sendInvitation' => false], $this->headers($token))->assertOk();
        $this->postJson(self::ROOT.'/'.$row->uuid.'/approve', ['sendInvitation' => false], $this->headers($token, 'again'))->assertOk();
        $events = DB::table('webhook_events')->where('type', 'like', 'project_request.%')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $payload = json_decode($event->body, true);
            $this->assertSame($row->uuid, $payload['requestId']);
            $this->assertArrayHasKey('occurredAt', $payload);
            foreach ([$row->email, $row->client_name, $row->project_name, $row->description, $row->company, $row->website] as $text) {
                $this->assertStringNotContainsString($text, $event->body);
            }
            $this->assertStringNotContainsString('/access/', $event->body);
        }
        $filters = app(WebhookProjectFilter::class);
        $record = DB::table('webhook_endpoints')->find($endpoint);
        $this->assertTrue($filters->matches($record, 'project_request.created', null));
        $record->project_filter = 'selected';
        $this->assertFalse($filters->matches($record, 'project_request.created', null));
        $this->assertFalse($filters->matches($record, 'project_request.approved', $row->fresh()->result['projectId']));
    }

    public function test_request_live_sync_and_counter_are_owner_only(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->owner($workspace)->create();
        $admin = User::factory()->memberOf($workspace)->create();
        $token = $this->token($workspace);
        $this->actingAs($owner);
        $ownerCursor = $this->getJson('/api/admin/me')->json('syncCursor');
        $this->actingAs($admin);
        $adminCursor = $this->getJson('/api/admin/me')->json('syncCursor');
        $this->postJson(self::ROOT, $this->input(), $this->headers($token))->assertCreated();
        $this->getJson('/api/admin/sync?cursor='.urlencode($adminCursor))->assertOk()->assertJsonPath('changed', false);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('pendingRequests', 0);
        $this->actingAs($owner);
        $this->getJson('/api/admin/sync?cursor='.urlencode($ownerCursor))->assertOk()->assertJsonPath('changed', true)->assertJsonPath('reset', true)->assertDontSee('ada@example.test');
        $this->getJson('/api/admin/project-requests/count')->assertOk()->assertJsonPath('pending', 1);
    }

    public function test_request_schema_rollback_refuses_to_erase_persistent_sources_and_decisions(): void
    {
        ProjectRequest::factory()->create();
        $migration = require database_path('migrations/2026_10_29_123816_create_project_requests_foundation.php');
        try {
            $migration->down();
            $this->fail('Request decisions were dropped');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Keep request decisions', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_requests', 1);
    }

    public function test_request_webhook_transport_failure_retries_the_existing_signed_delivery(): void
    {
        Http::preventStrayRequests();
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'crm.example.test', 'ip' => '93.184.216.34']);
        $row = ProjectRequest::factory()->create();
        DB::table('webhook_endpoints')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $row->workspace_id, 'name' => 'CRM', 'url' => 'https://crm.example.test/events',
            'events' => json_encode(['project_request.created']), 'enabled' => true, 'secret' => Crypt::encryptString('synthetic-request-secret'), 'secret_version' => 1, 'project_filter' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        app(WebhookEvents::class)->projectRequest($row, 'created');
        Http::fake(['https://crm.example.test/events' => Http::sequence()->pushStatus(503)->pushStatus(204)]);
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'pending', 'attempts' => 1, 'error_code' => 'remote_transient']);
        $this->travel(1)->minutes();
        app(WebhookProcessor::class)->process();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'succeeded', 'attempts' => 2]);
        $this->assertDatabaseCount('webhook_events', 1);
        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-ProjectBrief-Timestamp')[0];

            return $request->header('X-ProjectBrief-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'synthetic-request-secret');
        });
    }
}
