<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectPermissionsMigrationTest extends TestCase
{
    public function test_existing_assignments_and_accounts_survive_upgrade_rollback_and_reapplication(): void
    {
        $original = config('database.default');
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        $database = null;
        if ($prefix = getenv('M482_MIGRATION_MYSQL_DATABASE')) {
            $this->assertMatchesRegularExpression('/^projectbrief_m482_[a-z0-9_]+$/', $prefix);
            $database = $prefix.'_'.bin2hex(random_bytes(4));
            DB::connection('mysql')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $connection = [...config('database.connections.mysql'), 'database' => $database];
        }
        config(['database.connections.permissions_upgrade' => $connection, 'database.default' => 'permissions_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            $migration = null;
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_permissions_to_project_members_table')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $owner = $this->ownerFor($project);
            $developer = User::factory()->memberOf($project->workspace)->create();
            $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
            $snapshot = [];
            foreach (['users', 'workspaces', 'projects', 'workspace_members', 'project_members'] as $table) {
                $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $migration->up();
                foreach ($snapshot as $table => $rows) {
                    $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                        $data = (array) $row;
                        if ($table === 'workspace_members') {
                            $this->assertFalse((bool) $data['can_create_projects']);
                            unset($data['can_create_projects']);
                        }
                        if ($table === 'project_members') {
                            $this->assertNull($data['permissions']);
                            unset($data['permissions']);
                        }

                        return $data;
                    })->all();
                    $this->assertSame($rows, $after, $table);
                }
                $this->actingAs($developer)->getJson('/api/admin/projects/'.$project->uuid)->assertOk()->assertJsonPath('data.permissions', ProjectPermissions::DEFAULTS);
                $this->actingAs($owner)->getJson('/api/admin/projects/'.$project->uuid)->assertOk()->assertJsonPath('data.permissions.manage_clients', true);
                $migration->down();
                $this->assertFalse(Schema::hasColumn('project_members', 'permissions'));
                $this->assertFalse(Schema::hasColumn('workspace_members', 'can_create_projects'));
            }
        } finally {
            config(['database.default' => $original]);
            DB::purge('permissions_upgrade');
            Schema::clearResolvedInstance('db.schema');
            if ($database !== null) {
                DB::connection('mysql')->statement('DROP DATABASE `'.$database.'`');
            }
        }
    }
}
