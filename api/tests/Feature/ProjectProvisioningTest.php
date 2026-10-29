<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use App\Services\IntegrationWrites;
use App\Services\WebhookEvents;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProjectProvisioningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENDPOINT = '/api/v1/projects/provision';

    private function input(array $extra = []): array
    {
        return [...['externalId' => 'order-1042', 'client' => ['name' => 'Ada', 'email' => 'ada@example.test', 'company' => 'Studio', 'website' => 'https://example.test'],
            'project' => ['name' => 'Website', 'description' => 'Build an accessible site', 'language' => 'tr'], 'sendInvitation' => false], ...$extra];
    }

    private function token(Workspace $workspace, array $scopes = ['projects:provision']): array
    {
        return app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Orders', 'scopes' => $scopes]);
    }

    private function headers(array $token, string $key = 'order-1042'): array
    {
        return ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => $key];
    }

    private function mail(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
    }

    public function test_creates_atomic_project_contact_and_membership_without_an_authentication_token(): void
    {
        Mail::fake();
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);

        $response = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated()
            ->assertJsonPath('data.created', true)->assertJsonPath('data.invitation', 'not_requested');

        $project = Project::where('uuid', $response->json('data.projectId'))->firstOrFail();
        $this->assertSame($workspace->id, $project->workspace_id);
        $this->assertSame('tr', $project->language);
        $this->assertSame('Build an accessible site', $project->description);
        $this->assertSame(rtrim(config('projectbrief.frontend_url'), '/').'/project/'.$project->uuid, $response->json('data.projectUrl'));
        $this->assertDatabaseHas('workspace_clients', ['uuid' => $response->json('data.clientId'), 'email' => 'ada@example.test', 'company' => 'Studio']);
        $this->assertDatabaseHas('project_clients', ['uuid' => $response->json('data.projectClientId'), 'project_id' => $project->id, 'preferred_locale' => 'tr']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('project_access_tokens', 0);
        $this->assertDatabaseCount('integration_requests', 1);
        $this->getJson('/api/client/project/'.$project->uuid)->assertUnauthorized();
        Mail::assertNothingSent();
    }

    public function test_reuses_workspace_contact_but_requires_separate_project_identity_proof(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $first = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated()->json('data');
        $second = $this->postJson(self::ENDPOINT, $this->input(['externalId' => 'second-order', 'client' => ['name' => 'Changed', 'email' => 'ADA@example.test']]), $this->headers($token, 'second-order'))->assertCreated()->json('data');

        $this->assertSame($first['clientId'], $second['clientId']);
        $this->assertNotSame($first['projectId'], $second['projectId']);
        $this->assertNotSame($first['projectClientId'], $second['projectClientId']);
        $this->assertDatabaseCount('workspace_clients', 1);
        $this->assertDatabaseCount('project_clients', 2);
        $this->assertDatabaseHas('workspace_clients', ['name' => 'Ada']);
        $this->assertDatabaseCount('project_access_tokens', 0);
    }

    public function test_legacy_contact_is_found_only_inside_workspace_without_changing_its_access(): void
    {
        $legacy = Project::factory()->create(['client_name' => 'Existing Client', 'client_email' => 'ada@example.test']);
        $old = $legacy->clients()->first();
        $response = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($this->token($legacy->workspace)))->assertCreated();
        $this->assertDatabaseHas('workspace_clients', ['uuid' => $response->json('data.clientId'), 'name' => 'Existing Client']);
        $this->assertNull($old->fresh()->workspace_client_id);
        $this->assertDatabaseCount('project_access_tokens', 0);
    }

    public function test_identical_email_in_other_workspace_never_reuses_contact_or_access(): void
    {
        $first = Workspace::factory()->create();
        $second = Workspace::factory()->create();
        $one = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($this->token($first)))->assertCreated()->json('data');
        $two = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($this->token($second)))->assertCreated()->json('data');
        $this->assertNotSame($one['clientId'], $two['clientId']);
        $this->assertDatabaseCount('workspace_clients', 2);
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_same_key_replays_normalized_body_and_new_key_recovers_by_external_id(): void
    {
        $token = $this->token(Workspace::factory()->create());
        $one = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated()->json();
        $reordered = $this->input();
        $reordered['client'] = array_reverse($reordered['client'], true);
        $this->postJson(self::ENDPOINT, $reordered, $this->headers($token))->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($one);
        $two = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token, 'recovery'))->assertOk()->assertJsonPath('data.created', false)->json();
        $this->assertSame($one['data']['projectId'], $two['data']['projectId']);
        $this->assertSame($one['data']['projectUrl'], $two['data']['projectUrl']);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_provisionings', 1);
        $this->assertDatabaseHas('provisioning_operations', ['outcome' => 'replayed']);
    }

    #[TestWith(['order-1042'])]
    #[TestWith(['different-key'])]
    public function test_returns_409_for_conflicting_key_or_external_id_without_mutation(string $key): void
    {
        $token = $this->token(Workspace::factory()->create());
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated();
        $body = $this->input(['project' => ['name' => 'Overwrite', 'language' => 'en']]);
        $this->postJson(self::ENDPOINT, $body, $this->headers($token, $key))->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseMissing('projects', ['title' => 'Overwrite']);
    }

    public function test_external_id_namespace_is_separate_for_each_integration(): void
    {
        $workspace = Workspace::factory()->create();
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($this->token($workspace)))->assertCreated();
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($this->token($workspace)))->assertCreated();
        $this->assertDatabaseCount('projects', 2);
        $this->assertDatabaseCount('workspace_clients', 1);
    }

    public function test_invalid_expired_and_revoked_tokens_return_401_and_create_nothing(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $this->postJson(self::ENDPOINT, $this->input())->assertUnauthorized();
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers(['secret' => 'bad']))->assertUnauthorized();
        DB::table('integration_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertUnauthorized();
        DB::table('integration_tokens')->update(['expires_at' => null, 'revoked_at' => now()]);
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertUnauthorized();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('provisioning_operations', 0);
    }

    public function test_legacy_write_scope_cannot_provision_and_disabled_token_cannot_replay(): void
    {
        $workspace = Workspace::factory()->create();
        $legacy = $this->token($workspace, ['projects:write']);
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($legacy))->assertForbidden();
        $token = $this->token($workspace);
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated();
        DB::table('integration_tokens')->where('uuid', $token['id'])->update(['provisioning_enabled' => false]);
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_revocation_is_rechecked_inside_write_transaction_after_authentication(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $request = Request::create(self::ENDPOINT, 'POST');
        $request->headers->set('Idempotency-Key', 'race');
        $request->attributes->set('integration_token', DB::table('integration_tokens')->where('uuid', $token['id'])->first());
        DB::table('integration_tokens')->update(['revoked_at' => now()]);
        $this->expectException(HttpException::class);
        app(IntegrationWrites::class)->run($request, function (): array {
            $this->fail('Revoked token reached creation');
        });
    }

    #[TestWith(['workspaceId', 'foreign-workspace'])]
    #[TestWith(['templateId', 'future-template'])]
    #[TestWith(['sendInvitation', 'false'])]
    #[TestWith(['project', ['name' => 'x', 'language' => 'de']])]
    #[TestWith(['client', ['name' => 'x', 'email' => 'ada@example.test', 'active' => true]])]
    public function test_returns_422_for_unsafe_or_unsupported_input(string $field, mixed $value): void
    {
        $token = $this->token(Workspace::factory()->create());
        $this->postJson(self::ENDPOINT, $this->input([$field => $value]), $this->headers($token))->assertUnprocessable();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('workspace_clients', 0);
        $this->assertDatabaseCount('integration_requests', 0);
    }

    public function test_missing_key_and_oversized_body_cannot_create_records(): void
    {
        $token = $this->token(Workspace::factory()->create());
        $this->postJson(self::ENDPOINT, $this->input(), ['Authorization' => 'Bearer '.$token['secret']])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, $this->input(['externalId' => str_repeat('x', 33000)]), $this->headers($token))->assertStatus(413);
        $this->assertDatabaseCount('projects', 0);
    }

    #[TestWith(['ru', 'ru'])]
    #[TestWith(['en', 'en'])]
    #[TestWith(['tr', 'tr'])]
    #[TestWith(['zh-CN', 'zh'])]
    public function test_supported_project_language_sets_invitation_locale(string $language, string $stored): void
    {
        $this->mail();
        $token = $this->token(Workspace::factory()->create());
        $this->postJson(self::ENDPOINT, $this->input(['project' => ['name' => 'Localized', 'language' => $language], 'sendInvitation' => true]), $this->headers($token))
            ->assertCreated()->assertJsonPath('data.invitation', 'sent');
        $this->assertDatabaseHas('projects', ['language' => $language]);
        $this->assertDatabaseHas('project_clients', ['preferred_locale' => $stored]);
        Mail::assertSent(AccountActionMail::class, fn ($mail) => $mail->language === $language);
    }

    public function test_mail_runs_outside_operation_transaction_and_retries_never_duplicate_mail_or_expose_secrets(): void
    {
        $this->mail();
        Log::spy();
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $body = $this->input(['sendInvitation' => true]);
        Mail::swap(new MailManager($this->app));
        Mail::shouldReceive('to')->once()->with('ada@example.test')->andReturnUsing(function () {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertDatabaseCount('project_provisionings', 1);

            return new class
            {
                public function send($mail): void {}
            };
        });
        $response = $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'sent');
        $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'sent');
        $this->postJson(self::ENDPOINT, $body, $this->headers($token, 'recover'))->assertOk()->assertJsonPath('data.invitation', 'sent');
        $this->assertStringNotContainsString('/access/', $response->getContent());
        $this->assertStringNotContainsString($token['secret'], $response->getContent());
        $this->assertDatabaseCount('project_access_tokens', 1);
        $this->assertStringNotContainsString('/access/', DB::table('integration_requests')->value('response'));
        Log::shouldNotHaveReceived('error');
    }

    public function test_failed_smtp_preserves_result_and_revokes_only_failed_invitation(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SECRET-SMTP-PASSWORD /access/secret'));
        Log::spy();
        $token = $this->token(Workspace::factory()->create());
        $body = $this->input(['sendInvitation' => true]);
        $response = $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'failed');
        $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.projectId', $response->json('data.projectId'))->assertJsonPath('data.invitation', 'failed');
        $this->assertDatabaseCount('projects', 1);
        $this->assertNotNull(ProjectAccessToken::first()->revoked_at);
        Log::shouldHaveReceived('warning')->with('Account email delivery failed', ['error_code' => 'account_mail_failed', 'exception_type' => \RuntimeException::class])->once();
        Log::shouldNotHaveReceived('error');
        $response->assertDontSee('SECRET-SMTP-PASSWORD');
    }

    public function test_event_creation_failure_rolls_back_contact_project_membership_and_request(): void
    {
        $token = $this->token(Workspace::factory()->create());
        $this->mock(WebhookEvents::class)->shouldReceive('provisioned')->once()->andThrow(new \RuntimeException('simulated outbox failure'));
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertStatus(500);
        foreach (['workspace_clients', 'projects', 'project_clients', 'project_provisionings', 'integration_requests'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('provisioning_operations', ['outcome' => 'error', 'http_status' => 500]);
    }

    public function test_permanent_delete_keeps_external_id_tombstone_and_never_recreates_project(): void
    {
        $token = $this->token(Workspace::factory()->create());
        $result = $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated()->json('data');
        Project::where('uuid', $result['projectId'])->firstOrFail()->forceDelete();
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token, 'recover'))->assertStatus(410);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_provisionings', 1);
    }

    public function test_schema_upgrade_and_empty_rollback_preserve_existing_clients_and_tokens(): void
    {
        $project = Project::factory()->create(['client_email' => 'legacy@example.test']);
        $client = $project->clients()->first();
        $token = $this->token($project->workspace, ['projects:read']);
        $migration = require database_path('migrations/2026_10_29_092022_add_project_provisioning_foundation.php');
        $migration->down();
        $this->assertDatabaseHas('projects', ['uuid' => $project->uuid]);
        $this->assertDatabaseHas('project_clients', ['uuid' => $client->uuid]);
        $this->assertDatabaseHas('integration_tokens', ['uuid' => $token['id']]);
        $migration->up();
        $this->assertDatabaseHas('integration_tokens', ['uuid' => $token['id'], 'provisioning_enabled' => false]);
        $this->getJson('/api/v1/projects/'.$project->uuid, $this->headers($token))->assertOk();
    }

    public function test_nonempty_schema_rollback_refuses_to_erase_contacts_and_deduplication(): void
    {
        $token = $this->token(Workspace::factory()->create());
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated();
        $migration = require database_path('migrations/2026_10_29_092022_add_project_provisioning_foundation.php');
        try {
            $migration->down();
            $this->fail('Nonempty provisioning schema was dropped');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Keep the additive schema', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_provisionings', 1);
        $this->assertDatabaseCount('workspace_clients', 1);
    }

    public function test_explicit_owner_resend_updates_automatic_invitation_status(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $body = $this->input(['sendInvitation' => true]);
        $result = $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'failed')->json('data');
        $this->mail();
        $this->actingAs(User::factory()->owner($workspace)->create())
            ->postJson('/api/admin/projects/'.$result['projectId'].'/clients/'.$result['projectClientId'].'/invitation')->assertOk()->assertJsonPath('invitation', 'sent');
        $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'sent');
        Mail::assertSentCount(1);
    }

    public function test_pending_invitation_recovers_but_unconfirmed_sending_is_not_resent(): void
    {
        $this->mail();
        $token = $this->token(Workspace::factory()->create());
        $body = $this->input(['sendInvitation' => true]);
        $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated();
        DB::table('project_provisionings')->update(['invitation_status' => 'sending']);
        $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'sending');
        Mail::assertSentCount(1);
        DB::table('project_provisionings')->update(['invitation_status' => 'pending']);
        $this->postJson(self::ENDPOINT, $body, $this->headers($token))->assertCreated()->assertJsonPath('data.invitation', 'sent');
        Mail::assertSentCount(2);
    }

    public function test_owner_controls_switch_and_operation_history_is_workspace_scoped(): void
    {
        $workspace = Workspace::factory()->create();
        $token = $this->token($workspace);
        $this->postJson(self::ENDPOINT, $this->input(), $this->headers($token))->assertCreated();
        $owner = User::factory()->owner($workspace)->create();
        $this->actingAs($owner)->getJson('/api/admin/integrations/operations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.outcome', 'success')->assertDontSee('ada@example.test')->assertDontSee($token['secret']);
        $this->patchJson('/api/admin/integrations/tokens/'.$token['id'], ['provisioningEnabled' => false])->assertOk();
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonPath('tokens.0.provisioningEnabled', false);
        $other = Workspace::factory()->create();
        $this->actingAs(User::factory()->owner($other)->create())->withSession(['workspace_id' => $other->id])->getJson('/api/admin/integrations/operations')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/admin/integrations/tokens/'.$token['id'], ['provisioningEnabled' => true])->assertNotFound();
        $this->actingAs(User::factory()->memberOf($workspace)->create())->getJson('/api/admin/integrations/operations')->assertForbidden();
        $this->patchJson('/api/admin/integrations/tokens/'.$token['id'], ['provisioningEnabled' => true])->assertForbidden();
    }
}
