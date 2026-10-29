<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectFeedback;
use App\Models\Task;
use App\Models\User;
use App\Services\CompanyWebsite;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LiveSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function clientSession(Project $project, ?ProjectClient $client = null): void
    {
        $client ??= $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->app['auth']->forgetGuards();
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    public function test_model_journal_keeps_its_connection_when_console_bootstrap_rebinds_the_default(): void
    {
        $project = Project::factory()->create();
        $name = DB::getDefaultConnection();
        $connection = $project->getConnection();
        $project->setConnection($name);
        $before = $connection->table('project_sync_events')->count();
        config(['database.connections.sync_shadow' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        try {
            DB::setDefaultConnection('sync_shadow');
            $project->update(['title' => 'Console connection regression']);
            $this->assertSame($before + 1, $connection->table('project_sync_events')->count());
            $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('project_sync_events'));
        } finally {
            DB::setDefaultConnection($name);
            DB::purge('sync_shadow');
        }
    }

    public function test_cursor_is_seeded_by_regular_bootstrap_and_poll_is_bounded_and_private(): void
    {
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $cursor = $this->getJson('/api/admin/me')->assertOk()->json('syncCursor');
        Task::factory()->for($foreign)->create(['title' => 'FOREIGN PRIVATE']);
        Task::factory()->for($project)->create(['title' => 'PRIVATE BODY']);
        DB::enableQueryLog();
        $result = $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonPath('changed', true)->assertJsonPath('reset', false)->assertJsonPath('projects.0', $project->uuid);
        $this->assertCount(1, $result->json('projects'));
        $this->assertStringNotContainsString('PRIVATE', $result->getContent());
        $this->assertStringNotContainsString($foreign->uuid, $result->getContent());
        $queries = DB::getQueryLog();
        $journal = array_filter($queries, fn ($row) => str_contains($row['query'], 'project_sync_events'));
        $this->assertCount(1, $journal);
        $this->assertLessThan(20, count($queries));
        if ($directory = getenv('LIVE_SYNC_QA_DIRECTORY')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $entry = array_values($journal)[0];
            file_put_contents($directory.'/sql-measurement.json', json_encode(['requestQueries' => count($queries), 'journalQueries' => count($journal), 'journalSql' => $entry['query'], 'plan' => DB::select('EXPLAIN QUERY PLAN '.$entry['query'], $entry['bindings']), 'responseBytes' => strlen($result->getContent())], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->getJson('/api/admin/sync?cursor='.urlencode($result->json('cursor')))->assertOk()->assertJsonPath('changed', false)->assertJsonCount(0, 'projects');
    }

    public function test_developer_and_client_only_receive_authorized_projects_and_cursors_cannot_cross_identities(): void
    {
        $project = Project::factory()->create();
        $hidden = Project::factory()->for($project->workspace)->create();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->actingAs($developer);
        $cursor = $this->getJson('/api/admin/me')->json('syncCursor');
        Task::factory()->for($hidden)->create();
        Task::factory()->for($project)->create();
        $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('projects.0', $project->uuid);
        $this->clientSession($project);
        $this->getJson('/api/client/project/'.$project->uuid.'/sync?cursor='.urlencode($cursor))->assertUnprocessable();
        $seed = $this->getJson('/api/client/project/'.$project->uuid)->assertOk()->json('data.syncCursor');
        Task::factory()->for($project)->create();
        $this->getJson('/api/client/project/'.$project->uuid.'/sync?cursor='.urlencode($seed))->assertOk()->assertJsonPath('changed', true)->assertJsonPath('projects.0', $project->uuid);
        $this->getJson('/api/client/project/'.$hidden->uuid.'/sync?cursor='.urlencode($seed))->assertNotFound();
    }

    public function test_rolled_back_writes_do_not_publish_and_expired_or_overflowed_cursors_request_one_reset(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $this->actingAs($this->ownerFor($project));
        $cursor = $this->getJson('/api/admin/me')->json('syncCursor');
        $before = DB::table('project_sync_events')->count();
        try {
            DB::transaction(function () use ($task): void {
                $task->update(['status' => 'done']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, DB::table('project_sync_events')->count());
        $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonPath('changed', false);
        DB::table('project_sync_events')->insert(array_fill(0, 102, ['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'kind' => 'project', 'created_at' => now()]));
        $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonPath('reset', true)->assertJsonCount(0, 'projects');
        $this->travel(8)->days();
        $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonPath('reset', true);
    }

    public function test_stale_task_edit_is_rejected_without_losing_server_changes_and_explicit_rebase_succeeds(): void
    {
        $task = Task::factory()->create(['description' => 'Original']);
        $this->actingAs($this->ownerFor($task->project));
        $url = '/api/admin/tasks/'.$task->uuid;
        $version = $this->getJson($url)->assertOk()->json('data.editVersion');
        $task->update(['description' => 'Another participant']);
        $this->patchJson($url, ['description' => 'My draft', 'editVersion' => $version])->assertConflict()->assertJsonPath('code', 'task_conflict');
        $this->assertSame('Another participant', $task->fresh()->description);
        $latest = $this->getJson($url)->json('data.editVersion');
        $this->patchJson($url, ['description' => 'My draft', 'editVersion' => $latest])->assertOk()->assertJsonPath('data.description', 'My draft');
        $section = $task->project->briefSections()->create(['name' => 'Original Section', 'position' => 1]);
        $task->update(['project_section_id' => $section->id]);
        $version = $this->getJson($url)->json('data.editVersion');
        $section->update(['name' => 'Renamed Section']);
        $this->patchJson($url, ['description' => 'Stale section draft', 'editVersion' => $version])->assertConflict();
    }

    public function test_feedback_prompt_resets_after_interrupted_completion_and_review_is_owner_only(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['status' => 'done']);
        $client = $project->clients()->first();
        $entry = ProjectFeedback::create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'project_client_id' => $client->id, 'requested_at' => now(), 'shown_at' => now(), 'dismissed_at' => now()]);
        $task->update(['status' => 'in_progress']);
        $this->assertNull($entry->fresh()->shown_at);
        $this->clientSession($project);
        $root = '/api/client/project/'.$project->uuid.'/feedback';
        $this->getJson($root)->assertJsonPath('eligible', false);
        $task->update(['status' => 'done']);
        $this->postJson($root.'/invitation', ['action' => 'claim'])->assertOk()->assertJsonPath('autoOpen', true);
        $this->postJson($root.'/invitation', ['action' => 'claim'])->assertOk()->assertJsonPath('autoOpen', false);
        $this->postJson($root, ['rating' => 5, 'text' => 'Excellent original feedback'])->assertOk();
        Task::factory()->for($project)->create(['status' => 'new']);
        $this->getJson($root)->assertJsonPath('eligible', false)->assertJsonPath('submitted', true);
        $other = ProjectClient::factory()->for($project)->create();
        $this->clientSession($project, $other);
        $this->postJson($root, ['rating' => 1])->assertConflict();
        $this->getJson('/api/admin/projects/'.$project->uuid.'/feedback')->assertUnauthorized();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->actingAs($developer)->getJson('/api/admin/projects/'.$project->uuid.'/feedback')->assertForbidden();
        $this->actingAs($this->ownerFor($project))->getJson('/api/admin/projects/'.$project->uuid.'/feedback')->assertOk()->assertJsonPath('data.0.rating', 5)->assertJsonPath('data.0.text', 'Excellent original feedback')->assertJsonPath('data.0.author', $client->name);
        $this->assertSame(1, ProjectFeedback::whereNotNull('submitted_at')->where('project_id', $project->id)->count());
    }

    public function test_company_site_normalization_and_validation_match_the_shared_frontend_corpus(): void
    {
        $cases = json_decode(file_get_contents(base_path('../tests/fixtures/company-websites.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $this->assertSame($case['normalized'], CompanyWebsite::normalize($case['input']), $case['input']);
            $this->assertSame($case['valid'], CompanyWebsite::valid($case['input']), $case['input']);
        }
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $url = '/api/admin/workspaces/'.$project->workspace->uuid.'/branding';
        $this->patchJson($url, ['section' => 'contact', 'values' => ['website' => 'company.com']])->assertOk()->assertJsonPath('data.contact.website', 'https://company.com');
        $this->patchJson($url, ['section' => 'contact', 'values' => ['website' => 'http://www.company.com/path']])->assertOk()->assertJsonPath('data.contact.website', 'http://www.company.com/path');
        $this->patchJson($url, ['section' => 'contact', 'values' => ['website' => 'javascript:alert(1)']])->assertUnprocessable()->assertJsonValidationErrors('values.website');
    }
}
