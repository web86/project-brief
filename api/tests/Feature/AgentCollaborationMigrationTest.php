<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentCollaborationMigrationTest extends TestCase
{
    public function test_populated_v098_upgrade_preserves_data_tokens_and_missing_membership_without_automatic_grants(): void
    {
        $original = config('database.default');
        config(['database.connections.agent_collaboration_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'agent_collaboration_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (basename($path) > '2026_11_01_074535_add_agent_collaboration_and_task_formatting.php') {
                    continue;
                }
                if (str_contains($path, 'add_agent_collaboration_and_task_formatting')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $task = Task::factory()->for($project)->create();
            $agent = ExternalAgent::create(['workspace_id' => $project->workspace_id, 'name' => 'Historical agent', 'kind' => 'ai', 'active' => true]);
            $comment = Comment::create(['project_id' => $project->id, 'task_id' => $task->id, 'author_type' => 'agent', 'external_agent_id' => $agent->id, 'text' => 'Existing public result', 'team_only' => false]);
            DB::table('agent_project_grants')->insert(['workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id, 'project_id' => $project->id]);
            $assignment = TaskAgentAssignment::create(['workspace_id' => $project->workspace_id, 'task_id' => $task->id, 'external_agent_id' => $agent->id, 'state' => 'submitted']);
            DB::table('agent_reports')->insert(['uuid' => (string) Str::uuid(), 'task_agent_assignment_id' => $assignment->id, 'comment_id' => $comment->id, 'state' => 'submitted', 'result_url' => 'https://example.test/historical-result', 'created_at' => now()]);
            $comment->attachments()->create(['task_id' => $task->id, 'disk' => 'local', 'path' => 'historical.txt', 'stored_name' => 'historical.txt', 'original_name' => 'historical.txt', 'mime_type' => 'text/plain', 'size' => 12, 'uploaded_by_type' => 'agent']);
            $token = ['id' => (string) Str::uuid()];
            DB::table('integration_tokens')->insert(['uuid' => $token['id'], 'workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id, 'name' => 'Existing credential', 'scopes' => json_encode(['agent:tasks:read']), 'secret_hash' => hash('sha256', 'old-synthetic-secret'), 'created_at' => now(), 'updated_at' => now()]);
            $tables = ['projects', 'tasks', 'external_agents', 'comments', 'attachments', 'agent_reports', 'task_agent_assignments', 'integration_tokens', 'task_history', 'agent_project_grants', 'project_members'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $migration->up();
            foreach ($tables as $table) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                    $data = (array) $row;
                    $added = ['projects' => ['agent_instructions'], 'tasks' => ['description_html', 'description_html_hash'], 'external_agents' => ['instructions'], 'agent_reports' => ['agent_run_id']];
                    foreach ($added[$table] ?? [] as $column) {
                        $this->assertArrayHasKey($column, $data);
                        $this->assertNull($data[$column]);
                        unset($data[$column]);
                    }

                    return $data;
                })->all();
                $this->assertSame($before[$table], $after, $table);
            }
            $migration->up();
            $this->assertSame(0, DB::table('agent_runs')->count());
            $this->assertSame(0, DB::table('agent_messages')->count());
            $this->assertFalse(DB::table('project_members')->where('external_agent_id', $agent->id)->exists());
            $this->assertFalse(Comment::firstOrFail()->team_only);
            $this->assertSame(['agent:tasks:read'], json_decode(DB::table('integration_tokens')->where('uuid', $token['id'])->value('scopes'), true));
        } finally {
            config(['database.default' => $original]);
            DB::purge('agent_collaboration_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
