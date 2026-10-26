<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(Workspace $workspace, array $scopes = IntegrationTokens::SCOPES, array $extra = []): string
    {
        return app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Test CRM', 'scopes' => $scopes, ...$extra])['secret'];
    }

    private function headers(string $token, string $key = 'unique-key'): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key];
    }

    public function test_browser_identity_does_not_authorize_stateless_api_and_valid_bearer_does_not_create_cookies(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project))->getJson('/api/v1/projects')->assertUnauthorized()->assertJsonPath('code', 'invalid_token');
        $response = $this->getJson('/api/v1/projects', $this->headers($this->token($project->workspace)))->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(0, $response->headers->getCookies());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_tokens_are_hashed_once_visible_owner_only_and_revoked(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $response = $this->actingAs($owner)->postJson('/api/admin/integrations/tokens', ['name' => 'CRM', 'scopes' => ['projects:read'], 'expiresAt' => now()->addDay()->toISOString()])->assertCreated();
        $secret = $response->json('secret');
        $this->assertMatchesRegularExpression('/^pb_[a-f0-9]{64}$/', $secret);
        $row = DB::table('integration_tokens')->first();
        $this->assertSame(hash('sha256', $secret), $row->secret_hash);
        $this->getJson('/api/admin/integrations')->assertOk()->assertDontSee($secret)->assertDontSee($row->secret_hash);
        $this->getJson('/api/v1/projects', $this->headers($secret))->assertOk();
        $this->assertNotNull(DB::table('integration_tokens')->first()->last_used_at);
        $this->deleteJson('/api/admin/integrations/tokens/'.$response->json('id'))->assertOk();
        $this->getJson('/api/v1/projects', $this->headers($secret))->assertUnauthorized();
    }

    public function test_expired_invalid_and_missing_tokens_are_unauthorized(): void
    {
        $workspace = Workspace::factory()->create();
        foreach (['', 'not-a-token', $this->token($workspace, ['projects:read'], ['expiresAt' => now()->subSecond()])] as $token) {
            $this->getJson('/api/v1/projects', $this->headers($token))->assertUnauthorized();
        }
    }

    public function test_scopes_and_two_workspace_idor_are_enforced(): void
    {
        $one = Project::factory()->create();
        $two = Project::factory()->create();
        $headers = $this->headers($this->token($one->workspace, ['tasks:read']));
        $this->getJson('/api/v1/projects', $headers)->assertForbidden()->assertJsonPath('code', 'insufficient_scope');
        $this->getJson('/api/v1/projects/'.$two->uuid.'/tasks', $headers)->assertNotFound();
        $this->postJson('/api/v1/projects/'.$one->uuid.'/tasks', ['title' => 'x'], $headers)->assertForbidden();
        $headers = $this->headers($this->token($one->workspace, ['projects:read']));
        $this->getJson('/api/v1/projects', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $one->uuid);
        $this->getJson('/api/v1/projects/'.$two->uuid, $headers)->assertNotFound();
        $this->getJson('/api/v1/projects/'.$one->id, $headers)->assertNotFound();
        $this->getJson('/api/v1/projects/'.$one->uuid, $headers)->assertOk();
    }

    public function test_sensitive_fields_never_leak_and_estimates_require_explicit_scope(): void
    {
        $project = Project::factory()->create(['client_email' => 'private@example.test']);
        Task::factory()->for($project)->create(['developer_notes' => 'VERY-PRIVATE', 'price' => 1200, 'estimate_hours' => 6]);
        $headers = $this->headers($this->token($project->workspace, ['tasks:read', 'projects:read']));
        $response = $this->getJson('/api/v1/projects/'.$project->uuid.'/tasks', $headers)->assertOk()->assertDontSee('VERY-PRIVATE')->assertJsonMissingPath('data.0.price')->assertJsonMissingPath('data.0.estimateHours');
        $this->getJson('/api/v1/projects/'.$project->uuid, $headers)->assertOk()->assertDontSee('private@example.test')->assertJsonMissingPath('data.clientEmail');
        $this->getJson('/api/v1/projects/'.$project->uuid.'/tasks', $this->headers($this->token($project->workspace, ['tasks:read', 'estimates:read'])))->assertOk()->assertJsonPath('data.0.price', 1200)->assertJsonPath('data.0.estimateHours', 6)->assertDontSee('VERY-PRIVATE');
    }

    public function test_project_creation_replays_conflicts_and_links_external_identity(): void
    {
        $workspace = Workspace::factory()->create();
        $headers = $this->headers($this->token($workspace));
        $data = ['title' => 'CRM project', 'website' => 'https://example.test', 'currency' => 'EUR', 'clientName' => 'Anna', 'clientEmail' => 'anna@example.test', 'sourceSystem' => 'n8n', 'externalId' => 'deal-1'];
        $response = $this->postJson('/api/v1/projects', $data, $headers)->assertCreated();
        $id = $response->json('data.id');
        $this->assertStringContainsString($id, $response->json('data.managerUrl'));
        $this->postJson('/api/v1/projects', $data, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($response->json());
        $this->postJson('/api/v1/projects', [...$data, 'title' => 'Different'], $headers)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson('/api/v1/projects', $data, [...$headers, 'Idempotency-Key' => 'other-key'])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_sections', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('project_clients', ['name' => 'Anna', 'email' => 'anna@example.test']);
        $this->postJson('/api/v1/projects', $data, $this->headers($this->token(Workspace::factory()->create())))->assertCreated();
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_task_creation_reuses_sections_numbering_and_history_and_replay(): void
    {
        $project = Project::factory()->create();
        $headers = $this->headers($this->token($project->workspace));
        $data = ['title' => 'Photo gallery', 'location' => '/catalog', 'section' => 'New section', 'description' => 'Larger images', 'priority' => 'normal', 'expectedResult' => 'Readable photos'];
        $response = $this->postJson('/api/v1/projects/'.$project->uuid.'/tasks', $data, $headers)->assertCreated()->assertJsonPath('data.status', 'new')->assertJsonPath('data.clientApproved', false);
        $this->assertNotEmpty($response->json('data.number'));
        $this->postJson('/api/v1/projects/'.$project->uuid.'/tasks', $data, $headers)->assertCreated()->assertExactJson($response->json());
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('project_sections', ['name' => 'New section', 'project_id' => $project->id]);
        $this->assertDatabaseHas('task_history', ['event_type' => 'created']);
    }

    public function test_strict_input_validation_and_pagination(): void
    {
        $workspace = Workspace::factory()->create();
        $headers = $this->headers($this->token($workspace));
        foreach ([[], ['title' => ' '], ['title' => 'Valid', 'workspace_id' => 9], ['title' => 'Valid', 'developerNotes' => 'secret'], ['title' => 'Valid', 'website' => 'javascript:alert(1)'], ['title' => 'Valid', 'sourceSystem' => 'crm']] as $data) {
            $this->postJson('/api/v1/projects', $data, $headers)->assertUnprocessable();
        }
        $this->postJson('/api/v1/projects', ['title' => 'Valid'], ['Authorization' => $headers['Authorization']])->assertUnprocessable();
        $this->getJson('/api/v1/projects?perPage=101', $headers)->assertUnprocessable();
        $this->getJson('/api/v1/projects?perPage=1', $headers)->assertOk()->assertJsonPath('meta.perPage', 1);
        $this->assertDatabaseCount('projects', 0);
        $project = Project::factory()->for($workspace)->create();
        foreach ([[], ['title' => 'Task', 'location' => ' ', 'description' => 'x', 'priority' => 'normal'], ['title' => 'Task', 'location' => 'x', 'description' => 'x', 'priority' => 'normal', 'price' => 20]] as $data) {
            $this->postJson('/api/v1/projects/'.$project->uuid.'/tasks', $data, $headers)->assertUnprocessable();
        }
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_admin_client_and_other_workspace_cannot_manage_integration_objects(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $id = $this->actingAs($owner)->postJson('/api/admin/integrations/tokens', ['name' => 'CRM', 'scopes' => ['projects:read']])->assertCreated()->json('id');
        $this->actingAs(User::factory()->memberOf($project->workspace)->create())->getJson('/api/admin/integrations')->assertForbidden();
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'x', 'scopes' => ['projects:read']])->assertForbidden();
        $other = Workspace::factory()->create();
        $this->actingAs(User::factory()->owner($other)->create())->withSession(['workspace_id' => $other->id])->deleteJson('/api/admin/integrations/tokens/'.$id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withSession(['project_client_id' => $project->clients->first()->id, 'workspace_id' => null])->getJson('/api/admin/integrations')->assertUnauthorized();
    }

    public function test_api_is_csrf_independent_and_workspace_selection_cannot_change_token_scope(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn () => new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $one = Project::factory()->create();
        $two = Project::factory()->create();
        $this->withSession(['workspace_id' => $two->workspace_id])->postJson('/api/v1/projects', ['title' => 'No CSRF required'], $this->headers($this->token($one->workspace)))->assertCreated();
        $this->assertDatabaseHas('projects', ['title' => 'No CSRF required', 'workspace_id' => $one->workspace_id]);
        $this->actingAs($this->ownerFor($two))->postJson('/api/admin/integrations/tokens', ['name' => 'x', 'scopes' => ['projects:read']])->assertStatus(419);
    }

    public function test_json_body_limit_and_content_type_are_explicit(): void
    {
        $workspace = Workspace::factory()->create();
        $headers = $this->headers($this->token($workspace));
        $this->post('/api/v1/projects', ['title' => 'Form data'], $headers)->assertStatus(415)->assertJsonPath('code', 'invalid_content_type');
        $this->postJson('/api/v1/projects', ['title' => str_repeat('x', 32769)], $headers)->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_integration_migration_rolls_back_and_reapplies_without_touching_business_data(): void
    {
        $project = Project::factory()->create();
        $migration = require database_path('migrations/2026_10_26_065515_create_integration_tables.php');
        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('webhook_events'));
            $this->assertDatabaseHas('projects', ['uuid' => $project->uuid]);
        } finally {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('webhook_events'));
        $this->assertDatabaseHas('projects', ['uuid' => $project->uuid]);
    }

    public function test_api_rate_limits_and_unknown_endpoints_are_json(): void
    {
        $workspace = Workspace::factory()->create();
        $headers = $this->headers($this->token($workspace));
        $this->getJson('/api/v1/unknown', $headers)->assertNotFound()->assertJsonPath('code', 'not_found');
        for ($i = 0; $i < 59; $i++) {
            $this->getJson('/api/v1/projects', $headers)->assertOk();
        }
        $this->getJson('/api/v1/projects', $headers)->assertTooManyRequests()->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    }
}
