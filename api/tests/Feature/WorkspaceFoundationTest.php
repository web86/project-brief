<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use App\Services\OwnerProvisioning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_factory_users_have_no_implicit_membership_or_global_privileges(): void
    {
        $legacy = User::factory()->create(['role' => 'admin']);
        $this->assertSame(0, $legacy->workspaceMembers()->count());
        $this->actingAs($legacy)->getJson('/api/admin/projects')->assertForbidden();
        $this->getJson('/api/session')->assertExactJson(['authenticated' => false]);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/admin/login', ['email' => $legacy->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_neutral_legacy_role_with_active_membership_can_login_and_bootstrap_safely(): void
    {
        $user = User::factory()->owner()->create(['role' => 'other']);
        $workspace = $user->workspaceMembers()->first()->workspace;
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('state', 'pending_2fa')
            ->assertJsonMissingPath('user.id')->assertJsonMissingPath('workspace.workspace_id');
        $this->actingAs($user);
        $this->getJson('/api/session')->assertExactJson(['authenticated' => true, 'securityRecommended' => false, 'type' => 'admin', 'workspace' => ['id' => $workspace->uuid, 'name' => $workspace->name]]);
    }

    public function test_workspace_selection_is_server_side_membership_checked_and_stale_selection_is_rejected(): void
    {
        $first = Workspace::factory()->create();
        $second = Workspace::factory()->create();
        $foreign = Workspace::factory()->create();
        $user = User::factory()->owner($second)->create();
        $first->members()->create(['user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($user)->getJson('/api/admin/workspaces')->assertExactJson(['data' => [['id' => $first->uuid, 'name' => $first->name], ['id' => $second->uuid, 'name' => $second->name]]]);
        $this->getJson('/api/admin/me')->assertJsonPath('workspace.id', $first->uuid)->assertJsonPath('user.membershipRole', 'admin')->assertSessionHas(CurrentWorkspace::SESSION_KEY, $first->id);
        $this->postJson('/api/admin/workspaces/'.$foreign->uuid.'/select')->assertNotFound()->assertSessionHas(CurrentWorkspace::SESSION_KEY, $first->id);
        $this->postJson('/api/admin/workspaces/'.$second->id.'/select')->assertNotFound();
        $this->postJson('/api/admin/workspaces/'.$second->uuid.'/select')->assertExactJson(['data' => ['id' => $second->uuid, 'name' => $second->name]])->assertSessionHas(CurrentWorkspace::SESSION_KEY, $second->id);
        $this->getJson('/api/session')->assertJsonPath('workspace.id', $second->uuid);
        $second->members()->update(['status' => 'inactive']);
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->getJson('/api/session')->assertForbidden();
        $this->getJson('/api/admin/workspaces')->assertJsonCount(1, 'data');
        $this->postJson('/api/admin/workspaces/'.$second->uuid.'/select')->assertNotFound();
        $this->postJson('/api/admin/workspaces/'.$first->uuid.'/select')->assertOk();
        $this->getJson('/api/admin/projects')->assertOk();
        $this->withSession([CurrentWorkspace::SESSION_KEY => $foreign->id])->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/admin/logout')->assertOk();
    }

    public function test_revoked_only_membership_blocks_login_and_admin_session_but_allows_logout(): void
    {
        $user = User::factory()->owner()->create();
        $user->workspaceMembers()->update(['status' => 'inactive']);
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable()->assertJsonPath('code', 'invalid_credentials');
        $this->actingAs($user)->getJson('/api/session')->assertExactJson(['authenticated' => false]);
        $this->getJson('/api/admin/me')->assertForbidden();
        $this->postJson('/api/admin/logout')->assertOk();
    }

    public function test_first_cli_owner_creation_is_atomic_and_additional_cli_accounts_are_isolated_owners(): void
    {
        $this->assertDatabaseCount('workspaces', 0);
        $this->artisan('app:create-admin')->expectsQuestion('Имя', 'First owner')->expectsQuestion('Email', 'first@example.test')->expectsQuestion('Пароль (не менее 12 символов)', 'Long-secret-password')->expectsOutput('Администратор создан.')->assertSuccessful();
        $first = User::where('email', 'first@example.test')->firstOrFail();
        $this->assertSame('user', $first->role);
        $this->assertDatabaseHas('workspace_members', ['user_id' => $first->id, 'role' => 'owner', 'status' => 'active']);
        $this->assertSame('First owner', $first->workspaceMembers()->first()->workspace->name);
        $this->artisan('app:create-admin')->expectsQuestion('Имя', 'Invalid')->expectsQuestion('Email', 'bad')->expectsQuestion('Пароль (не менее 12 символов)', 'short')->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $other = app(OwnerProvisioning::class)->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'Long-secret-password']);
        $this->assertNotSame($first->workspaceMembers()->first()->workspace_id, $other->workspaceMembers()->first()->workspace_id);
        try {
            app(OwnerProvisioning::class)->create(['name' => 'Duplicate', 'email' => $first->email, 'password' => 'Long-secret-password']);
            $this->fail('Duplicate identity must roll back workspace creation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('users', 2);
            $this->assertDatabaseCount('workspaces', 2);
            $this->assertDatabaseCount('workspace_members', 2);
        }
    }

    public function test_first_owner_can_recover_the_single_ownerless_legacy_workspace(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Legacy installation']);
        Project::factory()->for($workspace)->create();
        $owner = app(OwnerProvisioning::class)->create(['name' => 'Recovery', 'email' => 'recover@example.test', 'password' => 'Long-secret-password']);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_database_rejects_tenantless_projects_cross_workspace_assignments_and_workspace_cascade_deletion(): void
    {
        $one = Workspace::factory()->create();
        $two = Workspace::factory()->create();
        $user = User::factory()->memberOf($one)->create();
        $project = Project::factory()->for($two)->create();
        $assigned = User::factory()->memberOf($two)->create()->workspaceMembers()->first();
        $project->members()->create(['workspace_id' => $two->id, 'workspace_member_id' => $assigned->id]);
        foreach ([
            fn () => Project::create(['title' => 'Missing workspace']),
            fn () => $project->members()->create(['workspace_id' => $two->id, 'workspace_member_id' => $user->workspaceMembers()->first()->id]),
            fn () => $project->members()->create(['workspace_id' => $one->id, 'workspace_member_id' => $user->workspaceMembers()->first()->id]),
            fn () => $project->members()->create(['workspace_id' => $two->id, 'workspace_member_id' => $assigned->id]),
            fn () => $two->delete(),
            fn () => $one->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']),
            fn () => $one->members()->create(['user_id' => User::factory()->create()->id, 'role' => 'global_admin', 'status' => 'active']),
            fn () => $one->members()->create(['user_id' => User::factory()->create()->id, 'role' => 'admin', 'status' => 'invited']),
            fn () => $assigned->update(['workspace_id' => $one->id]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('A database tenant invariant was not enforced.');
            } catch (QueryException) {
                $this->assertTrue($project->fresh()->exists);
            }
        }
    }
}
