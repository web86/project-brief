<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectClient;
use App\Services\Plans\LegacyInventory;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlansInventoryMigrationTest extends TestCase
{
    public function test_populated_phase_two_upgrade_is_additive_repeatable_and_has_no_automatic_backfill(): void
    {
        $original = config('database.default');
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
            $configuration = config('database.connections.'.$original);
            $configuration['database'] = 'projectbrief_m10_upgrade_qa';
        } else {
            $configuration = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        }
        config(['database.connections.phase3_upgrade' => $configuration, 'database.default' => 'phase3_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            Schema::dropAllTables();
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_restore_cycle_to_entitlement_capacity_reservations')) {
                    continue;
                }
                if (str_contains($path, 'add_plan_inventory_accounting')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $this->ownerFor($project);
            ProjectClient::factory()->create(['project_id' => $project->id]);
            $this->seed(PlansFoundationSeeder::class);
            $tables = [];
            foreach (Schema::getTables(DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null) as $table) {
                if (! str_starts_with($table['name'], 'sqlite_')) {
                    $tables[$table['name']] = hash('sha256', json_encode(DB::table($table['name'])->get()->all(), JSON_THROW_ON_ERROR));
                }
            }
            $migration->up();
            $migration->up();
            foreach ($tables as $table => $fingerprint) {
                $this->assertSame($fingerprint, hash('sha256', json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR)), 'Existing data changed: '.$table);
            }
            foreach (['plan_inventory_cohorts', 'plan_inventory_items', 'entitlement_client_observations', 'workspace_subscriptions', 'owner_trial_claims'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
            app(LegacyInventory::class)->bootstrap();
            app(LegacyInventory::class)->process($project->workspace_id);
            $this->assertDatabaseCount('workspace_subscriptions', 1);
            $this->assertDatabaseCount('owner_trial_claims', 0);
        } finally {
            Schema::dropAllTables();
            config(['database.default' => $original]);
            DB::purge('phase3_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
