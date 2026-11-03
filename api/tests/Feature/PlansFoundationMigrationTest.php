<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\AgentAccess;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ExecutionBarrier;
use App\Services\WebhookDestination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlansFoundationMigrationTest extends TestCase
{
    public function test_populated_v0910_upgrade_and_repeat_preserve_every_legacy_column(): void
    {
        $this->isolatedUpgrade(function (object $migration): void {
            // The HTTP fixture represents the pre-foundation runtime, before subscription tables or receipts existed.
            $this->app->instance(ExecutionBarrier::class, new class
            {
                public function catchUp(int $workspaceId): void {}

                public function require(int $workspaceId, string $group, ?object $receipt = null, bool $lockReads = true): ?int
                {
                    return null;
                }

                public function receipt(string $group, ?int $epoch): array
                {
                    return [];
                }
            });
            $this->app->instance(EffectiveParticipation::class, new class
            {
                public function requireGrowthAvailable(int $workspaceId): void {}

                public function requireProjectWritable(int $workspaceId, int $projectId): void {}

                public function visibleProjects(int $workspaceId): Builder
                {
                    return Project::where('workspace_id', $workspaceId);
                }

                public function excludeHeld(\Illuminate\Database\Query\Builder $query, int $workspaceId, string $kind, string $column): \Illuminate\Database\Query\Builder
                {
                    return $query;
                }
            });
            Http::preventStrayRequests();
            $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'receiver.example.test', 'ip' => '93.184.216.34']);
            $project = Project::factory()->create();
            $owner = $this->ownerFor($project);
            $this->actingAs($owner);
            WorkspaceMember::factory()->create(['workspace_id' => $project->workspace_id, 'user_id' => User::factory()->create()->id, 'role' => 'admin']);
            $key = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => 'Synthetic shared', 'scopes' => AgentAccess::SCOPES])->assertCreated()->json();
            $agents = [];
            foreach (['One', 'Two'] as $name) {
                $agents[] = $this->postJson('/api/admin/integrations/agents', ['name' => $name, 'active' => true, 'projectIds' => [$project->uuid], 'credentialId' => $key['id']])->assertCreated()->json('data.id');
            }
            $task = Task::factory()->for($project)->create();
            $this->postJson('/api/admin/tasks/'.$task->uuid.'/agents', ['agentId' => $agents[0], 'deferRun' => true])->assertOk();
            $assignment = TaskAgentAssignment::where('task_id', $task->id)->firstOrFail();
            $run = $this->postJson('/api/admin/tasks/'.$task->uuid.'/agent-assignments/'.$assignment->uuid.'/runs', ['instruction' => 'Synthetic private instruction', 'submissionId' => (string) Str::uuid()])->assertCreated()->json('data.id');
            $headers = ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $agents[0]];
            $this->postJson('/api/v1/agent/tasks/'.$task->uuid.'/reports', ['assignmentId' => $assignment->uuid, 'runId' => $run, 'state' => 'submitted', 'summary' => 'Synthetic private result'], [...$headers, 'Idempotency-Key' => 'upgrade-report'])->assertCreated();
            $this->postJson('/api/admin/integrations/webhooks', ['name' => 'Synthetic independent', 'url' => 'https://receiver.example.test/events', 'events' => ['agent.run.requested']])->assertCreated();
            DB::table('integration_sources')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Synthetic SSO', 'sso_enabled' => true, 'sso_origin' => 'https://sso.example.test', 'created_at' => now(), 'updated_at' => now()]);
            $tables = [];
            foreach (Schema::getTables(DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null) as $table) {
                if (! str_starts_with($table['name'], 'sqlite_')) {
                    $columns = Schema::getColumnListing($table['name']);
                    $tables[$table['name']] = ['columns' => $columns, 'fingerprint' => $this->fingerprint($table['name'], $columns)];
                }
            }
            $this->app->forgetInstance(ExecutionBarrier::class);
            $this->app->forgetInstance(EffectiveParticipation::class);
            $migration->up();
            $migration->up();
            foreach ($tables as $table => $before) {
                $this->assertSame($before['fingerprint'], $this->fingerprint($table, $before['columns']), 'Legacy data changed: '.$table);
            }
            foreach (['plans', 'plan_settings', 'platform_plan_authority', 'workspace_subscriptions', 'owner_trial_claims', 'workspace_execution_epochs', 'workspace_file_usage'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
            if (DB::getDriverName() === 'mysql') {
                foreach ([['projects', ['workspace_id', 'deleted_at', 'status', 'id']], ['workspace_members', ['workspace_id', 'status', 'role', 'user_id']], ['project_clients', ['project_id', 'active', 'entitlement_identity_id', 'id']]] as [$table, $columns]) {
                    $this->assertTrue(collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['columns'] === $columns));
                }
                $explain = DB::select('EXPLAIN SELECT id FROM projects WHERE workspace_id = ? AND deleted_at IS NULL AND status = ?', [$project->workspace_id, 'active']);
                $this->assertStringContainsString('pf_projects_usage_idx', $explain[0]->possible_keys);
            }
            $this->getJson('/api/v1/agent/me', $headers)->assertOk();
            $this->getJson('/api/admin/tasks/'.$task->uuid.'/agent-collaboration')->assertOk()->assertJsonPath('data.0.messages.1.text', 'Synthetic private result');
            $this->assertSame(2, DB::table('agent_credential_links')->count());
            $this->assertNull(DB::table('webhook_endpoints')->first()->external_agent_id);
            foreach (['plans', 'workspace_subscriptions', 'subscription_periods', 'entitlement_client_aliases', 'workspace_storage_reservations'] as $table) {
                $this->assertNotEmpty(Schema::getForeignKeys($table));
                foreach (Schema::getIndexes($table) as $index) {
                    $this->assertLessThanOrEqual(64, strlen($index['name']));
                }
            }
        });
    }

    public function test_mariadb_partial_ddl_commit_can_be_resumed_without_backfill(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Implicit-commit recovery is verified on disposable MariaDB.');
        }
        $this->isolatedUpgrade(function (object $migration): void {
            $interrupted = false;
            DB::listen(function ($query) use (&$interrupted): void {
                if (! $interrupted && str_starts_with(strtolower($query->sql), 'create table `plan_versions`')) {
                    $interrupted = true;
                    throw new \RuntimeException('Synthetic interruption after committed DDL');
                }
            });
            try {
                $migration->up();
                $this->fail('Interruption was not reached');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic interruption after committed DDL', $e->getMessage());
            }
            $this->assertTrue(Schema::hasTable('plan_versions'));
            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasColumn('integration_requests', 'execution_epoch'));
            $this->assertSame(0, DB::table('workspace_subscriptions')->count());
            $this->assertContains('pf_plan_current_fk', array_column(Schema::getForeignKeys('plans'), 'name'));
        });
    }

    private function fingerprint(string $table, array $columns): string
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function isolatedUpgrade(callable $test): void
    {
        $original = config('database.default');
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName(), 'Use the exclusive disposable QA database.');
            $configuration = config('database.connections.'.$original);
            $configuration['database'] = 'projectbrief_m10_upgrade_qa';
        } else {
            $configuration = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        }
        config(['database.connections.plans_upgrade' => $configuration, 'database.default' => 'plans_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            Schema::dropAllTables();
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_plan_inventory_accounting') || str_contains($path, 'add_execution_receipt_to_client_sso_handoffs') || str_contains($path, 'add_restore_cycle_to_entitlement_capacity_reservations')) {
                    continue; // The pre-foundation upgrade fixture excludes later implementation phases.
                }
                if (str_contains($path, 'create_plans_foundation')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $test($migration);
        } finally {
            config(['database.default' => $original]);
            DB::purge('plans_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
