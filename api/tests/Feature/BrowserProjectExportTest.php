<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectExportData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BrowserProjectExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(string $locale = 'en'): array
    {
        return ['format' => 'pdf', 'scope' => 'all', 'locale' => $locale, 'fields' => array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), true)];
    }

    private function fixture(): Project
    {
        config(['browser_pdf.max_per_minute' => 1000]);
        $project = Project::factory()->create(['currency' => 'USD']);
        Task::factory()->for($project)->create(['title' => 'Original Çığ / 中文 / задача', 'description' => '原始需求，不翻译。', 'developer_notes' => 'PRIVATE_DEVELOPER_NOTES', 'estimate_hours' => '1234.50', 'price' => '1234567.89', 'client_approved' => true]);
        $this->actingAs($this->ownerFor($project));

        return $project;
    }

    public function test_pdf_uses_json_and_server_pdf_download_is_rejected(): void
    {
        $project = $this->fixture();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/export-data', $this->payload())->assertOk()->assertJsonPath('schemaVersion', 1);
        $this->postJson('/api/admin/projects/'.$project->uuid.'/export', $this->payload())->assertUnprocessable();
    }

    public function test_four_languages_return_the_same_authorized_document_as_the_existing_builder(): void
    {
        $project = $this->fixture();
        foreach (['ru', 'en', 'tr', 'zh-CN'] as $locale) {
            $response = $this->postJson('/api/admin/projects/'.$project->uuid.'/export-data', $this->payload($locale))->assertOk()
                ->assertJsonPath('schemaVersion', 1)->assertJsonPath('document.totals.taskCount', 1)
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame(app(ProjectExportData::class)->build($project, 'all', $locale, $this->payload()['fields']), $response->json('document'));
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+-specification-\d{4}-\d{2}-\d{2}\.pdf$/', $response->json('filename'));
        }
    }

    public function test_client_json_never_contains_internal_notes_prices_estimates_or_storage_paths(): void
    {
        $project = $this->fixture();
        $this->app['auth']->forgetGuards();
        $client = $project->clients()->first();
        $access = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $access->id]);
        $response = $this->postJson('/api/client/project/'.$project->uuid.'/export-data', $this->payload('zh-CN'))->assertOk();
        foreach (['PRIVATE_DEVELOPER_NOTES', '1234.5', '1234567', 'estimateHours', 'developerNotes', 'token_hash', 'workspace_id', 'storage_path', 'price'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertStringContainsString('原始需求', $response->getContent());
        $foreign = Project::factory()->create();
        $this->postJson('/api/client/project/'.$foreign->uuid.'/export-data', $this->payload())->assertNotFound();
        $access->update(['revoked_at' => now()]);
        $this->postJson('/api/client/project/'.$project->uuid.'/export-data', $this->payload())->assertUnauthorized();
    }

    public function test_admin_json_obeys_existing_project_permission_and_tenant_boundaries(): void
    {
        $project = $this->fixture();
        $member = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->workspaceMembers()->first()->id, 'permissions' => ['export_project' => false]]);
        $this->actingAs($member)->postJson('/api/admin/projects/'.$project->uuid.'/export-data', $this->payload())->assertForbidden();
        $foreign = Project::factory()->create();
        $this->postJson('/api/admin/projects/'.$foreign->uuid.'/export-data', $this->payload())->assertNotFound();
    }

    public function test_limits_apply_after_scope_selection_and_json_size_is_bounded(): void
    {
        $project = $this->fixture();
        Task::factory()->for($project)->create(['client_approved' => false]);
        config(['browser_pdf.max_tasks' => 1]);
        $url = '/api/admin/projects/'.$project->uuid.'/export-data';
        $this->postJson($url, $this->payload())->assertStatus(413);
        $this->postJson($url, [...$this->payload(), 'scope' => 'approved'])->assertOk()->assertJsonPath('document.totals.taskCount', 1);
        config(['browser_pdf.max_tasks' => 1000, 'browser_pdf.max_json_bytes' => 100]);
        $this->postJson($url, $this->payload())->assertStatus(413)->assertJsonPath('code', 'export_too_large');
        config(['browser_pdf.max_json_bytes' => 8 * 1024 * 1024, 'browser_pdf.max_text_bytes' => 10]);
        $this->postJson($url, $this->payload())->assertStatus(413);
        $this->assertSame(2, app(ProjectExportData::class)->build($project, 'all', 'en')['totals']['taskCount']);
        $this->postJson($url, [...$this->payload(), 'format' => 'docx'])->assertUnprocessable();
        $this->postJson($url, [...$this->payload(), 'project_id' => 999])->assertUnprocessable();
    }
}
