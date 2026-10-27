<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlexibleSecurityMigrationTest extends TestCase
{
    public function test_actual_071_schema_and_encrypted_factors_survive_upgrade_and_rollback(): void
    {
        $original = config('database.default');
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        $database = null;
        if ($prefix = getenv('M72_MIGRATION_MYSQL_DATABASE')) {
            $this->assertMatchesRegularExpression('/^projectbrief_m72_[a-z0-9_]+$/', $prefix);
            $database = $prefix.'_'.bin2hex(random_bytes(4));
            DB::connection('mysql')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $connection = [...config('database.connections.mysql'), 'database' => $database];
        }
        config(['database.connections.security_upgrade' => $connection, 'database.default' => 'security_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            $migration = null;
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_security_recommendations_seen_at')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $owner = $this->ownerFor($project);
            $client = $project->clients()->first();
            $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
            $snapshot = [];
            foreach (['users', 'passkeys', 'project_access_tokens', 'workspace_members', 'projects'] as $table) {
                $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $migration->up();
                $this->assertTrue(Schema::hasColumn('users', 'security_recommendations_seen_at'));
                foreach ($snapshot as $table => $rows) {
                    $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table): array {
                        $data = (array) $row;
                        if ($table === 'users') {
                            $this->assertNull($data['security_recommendations_seen_at']);
                            unset($data['security_recommendations_seen_at']);
                        }

                        return $data;
                    })->all();
                    $this->assertSame($rows, $after, $table);
                }
                $this->assertTrue($owner->fresh()->hasEnabledTwoFactorAuthentication());
                $this->assertTrue($owner->fresh()->hasPasskeysEnabled());
                $migration->down();
                $this->assertFalse(Schema::hasColumn('users', 'security_recommendations_seen_at'));
            }
        } finally {
            config(['database.default' => $original]);
            DB::purge('security_upgrade');
            Schema::clearResolvedInstance('db.schema');
            if ($database !== null) {
                DB::connection('mysql')->statement('DROP DATABASE `'.$database.'`');
            }
        }
    }
}
