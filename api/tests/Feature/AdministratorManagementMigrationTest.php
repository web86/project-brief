<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\DeveloperInvitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdministratorManagementMigrationTest extends TestCase
{
    public function test_upgrade_preserves_existing_permissions_members_accounts_and_invitation_tokens(): void
    {
        $original = config('database.default');
        config(['database.connections.administrators_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'administrators_upgrade']);
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (str_contains($path, 'add_administrator_management_fields')) {
                    $migration = require $path;
                } else {
                    (require $path)->up();
                }
            }
            $project = Project::factory()->create();
            $owner = $this->ownerFor($project);
            $admin = User::factory()->memberOf($project->workspace)->create();
            $member = $admin->workspaceMembers()->firstOrFail();
            $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id, 'permissions' => ['manage_clients' => true, 'export_project' => true]]);
            $token = bin2hex(random_bytes(32));
            $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'project_ids' => [$project->id], 'token_hash' => hash('sha256', $token)]);
            $snapshot = [];
            foreach (['users', 'workspaces', 'projects', 'workspace_members', 'project_members', 'developer_invitations'] as $table) {
                $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $migration->up();
                foreach ($snapshot as $table => $rows) {
                    $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table): array {
                        $data = (array) $row;
                        if ($table === 'workspace_members') {
                            $this->assertNull($data['display_name']);
                            unset($data['display_name']);
                        }
                        if ($table === 'developer_invitations') {
                            $this->assertSame($data['created_at'], $data['sent_at']);
                            unset($data['sent_at']);
                        }

                        return $data;
                    })->all();
                    $this->assertSame($rows, $after, $table);
                }
                $this->assertSame($invitation->id, app(DeveloperInvitations::class)->resolve($token)->id);
                $migration->down();
            }
        } finally {
            config(['database.default' => $original]);
            DB::purge('administrators_upgrade');
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
