<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\LegacyWorkspaceBackfill;
use App\Services\OwnerProvisioning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceMigrationTest extends TestCase
{
    private function legacy(callable $test): void
    {
        $original = config('database.default');
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        $fixtureDatabase = null;
        if ($prefix = getenv('WORKSPACE_MIGRATION_MYSQL_DATABASE')) {
            $this->assertMatchesRegularExpression('/^projectbrief_m2_[a-z0-9_]+$/', $prefix);
            $fixtureDatabase = $prefix.'_'.bin2hex(random_bytes(5));
            DB::connection('mysql')->statement('CREATE DATABASE `'.$fixtureDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $connection = [...config('database.connections.mysql'), 'database' => $fixtureDatabase];
        }
        config(['database.connections.workspace_legacy' => $connection, 'database.default' => 'workspace_legacy']);
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (basename($path) < '2026_10_08') {
                    (require $path)->up();
                }
            }
            $test();
        } finally {
            config(['database.default' => $original]);
            DB::purge('workspace_legacy');
            Schema::clearResolvedInstance('db.schema');
            if ($fixtureDatabase !== null) {
                DB::connection('mysql')->statement('DROP DATABASE `'.$fixtureDatabase.'`');
            }
        }
    }

    private function migrateWorkspace(): void
    {
        foreach (glob(database_path('migrations/2026_10_*.php')) as $migration) {
            if (basename($migration) >= '2026_10_08') {
                (require $migration)->up();
            }
        }
    }

    private function legacyUser(string $email, string $name, string $created): int
    {
        return DB::table('users')->insertGetId(['name' => $name, 'email' => $email, 'password' => bcrypt('Legacy-password-long'), 'created_at' => $created, 'updated_at' => $created]);
    }

    private function legacyProject(): int
    {
        return DB::table('projects')->insertGetId(['uuid' => (string) Str::uuid(), 'title' => 'Legacy project', 'client_name' => 'Original', 'currency' => 'RUB', 'sections' => '["Main"]', 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function existingUsers(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('existingUsers')]
    public function test_actual_legacy_schema_backfill_preserves_every_dependent_row_and_existing_access(int $users): void
    {
        $this->legacy(function () use ($users): void {
            $first = $this->legacyUser('first@example.test', 'Oldest owner', '2020-01-01 00:00:00');
            $second = $users === 2 ? $this->legacyUser('second@example.test', 'Additional admin', '2021-01-01 00:00:00') : null;
            $project = $this->legacyProject();
            $another = $this->legacyProject();
            $client = DB::table('project_clients')->insertGetId(['uuid' => (string) Str::uuid(), 'project_id' => $project, 'name' => 'Legacy client', 'preferred_locale' => 'ru', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $plain = str_repeat('a', 64);
            $token = DB::table('project_access_tokens')->insertGetId(['project_id' => $project, 'project_client_id' => $client, 'token_hash' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);
            $task = DB::table('tasks')->insertGetId(['uuid' => (string) Str::uuid(), 'project_id' => $project, 'title' => 'Legacy task', 'description' => 'Original content', 'developer_notes' => 'Private unchanged', 'estimate_hours' => 3.5, 'price' => 12000, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('comments')->insert(['uuid' => (string) Str::uuid(), 'task_id' => $task, 'author_type' => 'client', 'project_client_id' => $client, 'text' => 'Original comment', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('task_history')->insert(['task_id' => $task, 'actor_type' => 'client', 'project_client_id' => $client, 'event_type' => 'comment', 'meta' => '{"text":"Original history"}', 'created_at' => now()]);
            DB::table('attachments')->insert(['uuid' => (string) Str::uuid(), 'task_id' => $task, 'uploaded_by_type' => 'client', 'project_client_id' => $client, 'original_name' => 'file.txt', 'stored_name' => 'original-stored.txt', 'mime_type' => 'text/plain', 'size' => 10, 'disk' => 'local', 'path' => 'private/original', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('project_notification_settings')->insert(['project_id' => $project, 'notification_email' => 'legacy@example.test', 'events' => '{"client.comment_created":{"email":true,"push":false}}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('notification_deliveries')->insert(['project_id' => $project, 'dedup_key' => hash('sha256', 'old-delivery'), 'event' => 'client.comment_created', 'channel' => 'email', 'recipient_type' => 'admin', 'recipient_id' => $first, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('push_subscriptions')->insert(['uuid' => (string) Str::uuid(), 'user_id' => $first, 'endpoint_hash' => hash('sha256', 'endpoint'), 'endpoint' => 'https://fcm.googleapis.com/send/legacy', 'public_key' => 'unchanged', 'auth_token' => 'unchanged', 'created_at' => now(), 'updated_at' => now()]);
            $before = [];
            foreach (['users', 'projects', 'project_clients', 'project_access_tokens', 'tasks', 'comments', 'task_history', 'attachments', 'project_notification_settings', 'notification_deliveries', 'push_subscriptions'] as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $this->migrateWorkspace();
            $workspace = DB::table('workspaces')->first();
            $this->assertSame('Oldest owner', $workspace->name);
            $this->assertSame(1, DB::table('workspaces')->count());
            $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $first, 'role' => 'owner', 'status' => 'active']);
            $this->assertSame([$workspace->id, $workspace->id], DB::table('projects')->orderBy('id')->pluck('workspace_id')->all());
            if ($second !== null) {
                $this->assertDatabaseHas('workspace_members', ['user_id' => $second, 'role' => 'admin', 'status' => 'active']);
                $this->assertSame(2, DB::table('project_members')->count());
                $this->actingAs(User::findOrFail($second))->getJson('/api/admin/projects')->assertJsonCount(2, 'data');
                $this->getJson('/api/admin/tasks/'.DB::table('tasks')->where('id', $task)->value('uuid'))->assertOk();
            } else {
                $this->assertSame(0, DB::table('project_members')->count());
            }
            $this->actingAs(User::findOrFail($first))->getJson('/api/admin/projects')->assertForbidden()->assertJsonPath('state', 'enrollment_required');
            $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/project/'.DB::table('projects')->where('id', $project)->value('uuid'));
            $this->getJson('/api/client/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.developerNotes');
            foreach ($before as $table => $rows) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                    $data = (array) $row;
                    if ($table === 'users') {
                        foreach (['preferred_locale', 'notification_preferences', 'notification_email', 'notification_email_resolved_at', 'pending_notification_email', 'notification_email_token_hash', 'notification_email_expires_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'pending_two_factor_secret', 'last_totp_step', 'recovery_codes_acknowledged_at', 'security_recovery_token_hash', 'security_recovery_expires_at'] as $added) {
                            $this->assertNull($data[$added]);
                            unset($data[$added]);
                        }
                    }
                    if ($table === 'project_clients') {
                        $this->assertNull($data['notification_preferences']);
                        unset($data['notification_preferences']);
                    }
                    if ($table === 'project_notification_settings') {
                        $this->assertSame('legacy', $data['delivery_mode']);
                        $this->assertNull($data['event_rules']);
                        unset($data['delivery_mode'], $data['event_rules']);
                    }
                    if ($table === 'comments') {
                        $this->assertNull($data['project_id']);
                        $this->assertNull($data['submission_key']);
                        $this->assertNull($data['submission_fingerprint']);
                        unset($data['project_id'], $data['submission_key'], $data['submission_fingerprint']);
                    }
                    if ($table === 'attachments') {
                        $this->assertNull($data['thumbnail_path']);
                        unset($data['thumbnail_path']);
                    }
                    if ($table === 'tasks') {
                        $this->assertNull($data['deleted_at']);
                        unset($data['deleted_at']);
                    }
                    if ($table === 'projects') {
                        unset($data['workspace_id']);
                    }

                    return $data;
                })->all();
                if ($table === 'users') {
                    foreach ($after as &$row) {
                        $this->assertSame('initials', $row['avatar_source']);
                        $this->assertSame(0, $row['auth_session_version']);
                        foreach (['pending_email', 'email_change_token_hash', 'email_change_expires_at', 'avatar_source', 'avatar_path', 'auth_session_version'] as $column) {
                            unset($row[$column]);
                        }
                    }
                    unset($row);
                }
                if ($table === 'projects') {
                    foreach ($after as &$row) {
                        $this->assertNull($row['deleted_at']);
                        $this->assertNull($row['status_before_trash']);
                        unset($row['deleted_at'], $row['status_before_trash']);
                    }
                    unset($row);
                }
                if ($table === 'project_access_tokens') {
                    // Entering the preserved link intentionally updates these activity timestamps.
                    foreach ($rows as &$row) {
                        unset($row['last_used_at'], $row['updated_at']);
                    }
                    unset($row);
                    foreach ($after as &$row) {
                        unset($row['last_used_at'], $row['updated_at']);
                    }
                    unset($row);
                }
                $this->assertSame($rows, $after, $table.' existing values must survive');
            }
            $this->postJson('/api/admin/login', ['email' => 'first@example.test', 'password' => 'Legacy-password-long'])->assertOk()->assertJsonPath('state', 'enrollment_required');
            if ($second !== null) {
                $this->postJson('/api/admin/login', ['email' => 'second@example.test', 'password' => 'Legacy-password-long'])->assertOk()->assertJsonPath('user.membershipRole', 'admin');
            }
            $snapshot = DB::table('workspace_members')->orderBy('id')->get()->toJson();
            LegacyWorkspaceBackfill::run();
            $this->assertSame($snapshot, DB::table('workspace_members')->orderBy('id')->get()->toJson());
            $this->assertSame(1, DB::table('workspaces')->count());
            $this->assertNotNull(Project::find($another)->workspace);
        });
    }

    public function test_empty_legacy_installation_stays_empty_until_owner_is_created(): void
    {
        $this->legacy(function (): void {
            $this->migrateWorkspace();
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('projects', 0);
            $this->assertDatabaseCount('workspaces', 0);
            $user = app(OwnerProvisioning::class)->create(['name' => 'Fresh owner', 'email' => 'fresh@example.test', 'password' => 'Long-password-secret']);
            $this->assertDatabaseCount('workspaces', 1);
            $this->assertDatabaseHas('workspace_members', ['user_id' => $user->id, 'role' => 'owner']);
        });
    }

    public function test_projects_without_users_are_preserved_in_a_neutral_workspace_and_first_cli_owner_recovers_it(): void
    {
        $this->legacy(function (): void {
            $project = $this->legacyProject();
            $this->migrateWorkspace();
            $workspace = DB::table('workspaces')->first();
            $this->assertSame(config('app.name'), $workspace->name);
            $this->assertDatabaseHas('projects', ['id' => $project, 'workspace_id' => $workspace->id]);
            $this->assertDatabaseCount('workspace_members', 0);
            $owner = app(OwnerProvisioning::class)->create(['name' => 'Recovery', 'email' => 'recovery@example.test', 'password' => 'Long-password-secret']);
            $this->assertDatabaseCount('workspaces', 1);
            $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);
        });
    }
}
