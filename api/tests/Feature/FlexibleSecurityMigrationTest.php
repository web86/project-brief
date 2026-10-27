<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\OwnerProvisioning;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlexibleSecurityMigrationTest extends TestCase
{
    public function test_membership_invalidation_uses_the_models_connection_after_cli_configuration_bootstrap(): void
    {
        $original = config('database.default');
        $resolver = Model::getConnectionResolver();
        $database = tempnam(sys_get_temp_dir(), 'm72-model-connection-');
        config(['database.connections.membership_event' => ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 25], 'database.default' => 'membership_event']);
        Schema::clearResolvedInstance('db.schema');
        $models = null;
        $event = 'eloquent.creating: '.WorkspaceMember::class;
        $dispatcher = Model::getEventDispatcher();
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                (require $path)->up();
            }
            $models = new DatabaseManager($this->app, $this->app['db.factory']);
            Model::setConnectionResolver($models);
            $this->assertNotSame(DB::connection()->getPdo(), $models->connection()->getPdo());
            $rejectMembership = false;
            $dispatcher->listen($event, function ($member) use (&$rejectMembership): void {
                $this->assertGreaterThan(0, $member->getConnection()->transactionLevel());
                if ($rejectMembership) {
                    throw new \RuntimeException('membership_write_test_failure');
                }
            });
            $owner = app(OwnerProvisioning::class)->create(['name' => 'CLI bootstrap owner', 'email' => 'cli-bootstrap@example.test', 'password' => 'Disposable-password!']);
            $this->assertSame(1, $owner->workspaceMembers()->count());
            $this->assertSame(1, User::where('email', $owner->email)->count());
            $this->assertSame(1, Workspace::count());
            $rejectMembership = true;
            try {
                app(OwnerProvisioning::class)->create(['name' => 'Rollback owner', 'email' => 'cli-rollback@example.test', 'password' => 'Disposable-password!']);
                $this->fail('Membership failure must abort provisioning');
            } catch (\RuntimeException $error) {
                $this->assertSame('membership_write_test_failure', $error->getMessage());
            }
            $this->assertSame(0, User::where('email', 'cli-rollback@example.test')->count());
            $this->assertSame(1, Workspace::count());
        } finally {
            $dispatcher->forget($event);
            Model::setConnectionResolver($resolver);
            $models?->purge('membership_event');
            DB::purge('membership_event');
            config(['database.default' => $original]);
            Schema::clearResolvedInstance('db.schema');
            @unlink($database);
        }
    }

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
