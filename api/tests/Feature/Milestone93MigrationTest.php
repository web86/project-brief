<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\WorkspaceAgreements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone93MigrationTest extends TestCase
{
    public function test_additive_upgrade_preserves_users_members_permissions_sources_tokens_and_old_invitations(): void
    {
        $original = config('database.default');
        config(['database.connections.m93_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'm93_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_workspace_administrator_agreements')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $owner = $this->ownerFor($project);
            $admin = User::factory()->memberOf($project->workspace)->create();
            $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id, 'permissions' => ['manage_clients' => true]]);
            DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'project_ids' => [$project->id], 'sent_at' => now()]);
            DB::table('integration_sources')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Existing source', 'sso_enabled' => false]);
            $tables = ['workspaces', 'users', 'workspace_members', 'project_members', 'developer_invitations', 'integration_sources'];
            $snapshot = [];
            foreach ($tables as $table) {
                $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $migration->up();
                $this->assertTrue(Schema::hasTable('workspace_agreement_acceptances'));
                $this->assertSame(false, app(WorkspaceAgreements::class)->settings($project->workspace->fresh())['enabled']);
                foreach ($snapshot as $table => $rows) {
                    $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table): array {
                        $data = (array) $row;
                        foreach (match ($table) {
                            'workspaces' => ['administrator_agreement'], 'workspace_members' => ['agreement_requirement'], default => []
                        } as $column) {
                            $this->assertNull($data[$column]);
                            unset($data[$column]);
                        }

                        return $data;
                    })->all();
                    $this->assertSame($rows, $after, $table);
                }
                $migration->down();
            }
        } finally {
            config(['database.default' => $original]);
            DB::purge('m93_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
