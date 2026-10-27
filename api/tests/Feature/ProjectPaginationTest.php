<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProjectPaginationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pagination_filter_sort_and_empty_last_page_are_server_scoped(): void
    {
        $first = Project::factory()->create(['title' => 'Target Z', 'status' => 'active']);
        $this->actingAs($this->ownerFor($first));
        Project::factory()->count(24)->for($first->workspace)->create(['status' => 'active']);
        $inactive = Project::factory()->for($first->workspace)->create(['title' => 'Target A', 'status' => 'inactive']);
        $inactive->clients()->first()->update(['name' => 'Unique client']);
        $trash = Project::factory()->for($first->workspace)->create(['title' => 'Trash target']);
        $trash->delete();
        Project::factory()->count(3)->create();
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 26)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/admin/projects?page=2')->assertJsonCount(6, 'data');
        $this->getJson('/api/admin/projects?q=Target&sort=name')->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Target A');
        $this->getJson('/api/admin/projects?status=inactive')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/projects?client=Unique')->assertJsonPath('data.0.id', $inactive->uuid);
        $this->getJson('/api/admin/projects?q=Missing&page=99')->assertJsonPath('meta.current_page', 1)->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/projects?sort=unsafe')->assertUnprocessable();
    }

    public function test_developers_only_receive_assigned_projects_and_cannot_search_private_client_identities(): void
    {
        $project = Project::factory()->create();
        $dev = User::factory()->memberOf($project->workspace)->create();
        Project::factory()->count(24)->for($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $dev->workspaceMembers()->first()->id]);
        $this->actingAs($dev)->getJson('/api/admin/projects')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $project->uuid);
        $this->getJson('/api/admin/projects?client=private')->assertForbidden();
    }
}
