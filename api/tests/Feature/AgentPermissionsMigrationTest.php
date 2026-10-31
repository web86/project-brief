<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Services\AgentPermissions;
use App\Services\IntegrationTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentPermissionsMigrationTest extends TestCase
{
    public function test_upgrade_adds_only_nullable_permissions_and_preserves_historical_visibility_and_tokens(): void
    {
        $original = config('database.default');
        config(['database.connections.agent_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'agent_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_permissions_to_external_agents')) {
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
            $project->members()->create(['workspace_id' => $project->workspace_id, 'external_agent_id' => $agent->id, 'permissions' => []]);
            $assignment = TaskAgentAssignment::create(['workspace_id' => $project->workspace_id, 'task_id' => $task->id, 'external_agent_id' => $agent->id, 'state' => 'submitted']);
            DB::table('agent_reports')->insert(['uuid' => (string) Str::uuid(), 'task_agent_assignment_id' => $assignment->id, 'comment_id' => $comment->id, 'state' => 'submitted', 'result_url' => 'https://example.test/historical-result', 'created_at' => now()]);
            $comment->attachments()->create(['task_id' => $task->id, 'disk' => 'local', 'path' => 'historical.txt', 'stored_name' => 'historical.txt', 'original_name' => 'historical.txt', 'mime_type' => 'text/plain', 'size' => 12, 'uploaded_by_type' => 'agent']);
            $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Existing credential', 'agentId' => $agent->id, 'scopes' => ['agent:tasks:read']]);
            $tables = ['external_agents', 'comments', 'attachments', 'agent_reports', 'task_agent_assignments', 'integration_tokens', 'task_history', 'agent_project_grants', 'project_members'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $migration->up();
            foreach ($tables as $table) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                    $data = (array) $row;
                    if ($table === 'external_agents') {
                        $this->assertNull($data['permissions']);
                        unset($data['permissions']);
                    }

                    return $data;
                })->all();
                $this->assertSame($before[$table], $after, $table);
            }
            $this->assertSame(AgentPermissions::DEFAULTS, AgentPermissions::normalize($agent->fresh()->permissions));
            $this->assertFalse(Comment::firstOrFail()->team_only);
            $this->assertSame(['agent:tasks:read'], json_decode(DB::table('integration_tokens')->where('uuid', $token['id'])->value('scopes'), true));
        } finally {
            config(['database.default' => $original]);
            DB::purge('agent_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
