<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Release910RefinementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_reviews_include_completed_and_archived_projects_but_never_other_workspaces_or_pending_feedback(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $older = ProjectFeedback::factory()->create(['project_id' => $project->id, 'workspace_id' => $project->workspace_id, 'project_client_id' => $project->clients()->first()->id, 'rating' => 4, 'text' => 'Older review', 'submitted_at' => '2026-10-30 10:00:00']);
        $archived = Project::factory()->create(['workspace_id' => $project->workspace_id, 'status' => 'completed']);
        ProjectFeedback::factory()->create(['project_id' => $archived->id, 'workspace_id' => $project->workspace_id, 'project_client_id' => $archived->clients()->first()->id, 'rating' => 5, 'text' => 'Archived review', 'submitted_at' => '2026-11-01 10:00:00']);
        $archived->delete();
        ProjectFeedback::factory()->create(['project_id' => $project->id, 'workspace_id' => $project->workspace_id]);
        ProjectFeedback::factory()->create(['rating' => 1, 'text' => 'FOREIGN', 'submitted_at' => now()]);
        $this->actingAs($owner);
        $this->getJson('/api/admin/reviews')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'data')->assertJsonPath('data.0.text', 'Archived review')->assertJsonPath('data.0.project.archived', true)->assertJsonPath('data.1.id', $older->id)->assertJsonPath('data.1.email', $project->clients()->first()->email)->assertDontSee('FOREIGN');
        $developer = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->actingAs($developer)->getJson('/api/admin/reviews')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->getJson('/api/admin/reviews')->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/reviews')->assertUnauthorized();
    }

    public function test_review_date_range_is_inclusive_in_the_display_timezone_and_paginated(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        foreach (['2026-10-31 20:59:59', '2026-10-31 21:00:00', '2026-11-01 20:59:59', '2026-11-01 21:00:00'] as $index => $date) {
            ProjectFeedback::factory()->create(['project_id' => $project->id, 'workspace_id' => $project->workspace_id, 'submitted_at' => $date, 'rating' => 5, 'text' => 'Boundary-'.$index]);
        }
        $this->getJson('/api/admin/reviews?from=2026-11-01&to=2026-11-01&timezone=Europe%2FIstanbul')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.text', 'Boundary-2')->assertJsonPath('data.1.text', 'Boundary-1');
        $this->getJson('/api/admin/reviews?to=2026-10-30')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/reviews?from=2026-11-02&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/admin/reviews?from=2026-02-30')->assertUnprocessable();
        $this->getJson('/api/admin/reviews?timezone=invalid')->assertUnprocessable();
        ProjectFeedback::factory()->count(24)->create(['project_id' => $project->id, 'workspace_id' => $project->workspace_id, 'submitted_at' => now(), 'rating' => 3]);
        $this->getJson('/api/admin/reviews')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 28)->assertJsonPath('meta.lastPage', 2);
        $this->getJson('/api/admin/reviews?page=2')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_agent_creation_needs_no_key_defaults_to_agent_and_preserves_existing_types_on_edit(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $input = ['name' => 'Unconnected', 'active' => true, 'projectIds' => [$project->uuid]];
        $id = $this->postJson('/api/admin/integrations/agents', $input)->assertCreated()->assertJsonPath('data.kind', 'agent')->json('data.id');
        $this->assertDatabaseCount('integration_tokens', 0);
        $this->assertDatabaseCount('agent_credential_links', 0);
        $this->getJson('/api/v1/agent/me', ['X-ProjectBrief-Agent-ID' => $id])->assertUnauthorized();
        $old = ExternalAgent::create(['workspace_id' => $project->workspace_id, 'name' => 'Existing service', 'kind' => 'service', 'active' => true]);
        $this->patchJson('/api/admin/integrations/agents/'.$old->uuid, [...$input, 'kind' => 'agent'])->assertOk()->assertJsonPath('data.kind', 'service');
        $key = $this->postJson('/api/admin/integrations/tokens', ['type' => 'agent', 'name' => 'Profile only', 'scopes' => ['agent:profile']])->assertCreated()->json();
        foreach ([$id, $old->uuid] as $agent) {
            $this->patchJson('/api/admin/integrations/agents/'.$agent, [...$input, 'credentialId' => $key['id'], 'permissions' => ['projectTasks' => true, 'assignedTasks' => false]])->assertOk();
            $headers = ['Authorization' => 'Bearer '.$key['secret'], 'X-ProjectBrief-Agent-ID' => $agent];
            $this->getJson('/api/v1/agent/me', $headers)->assertOk()->assertJsonPath('data.scopes', ['agent:profile'])->assertJsonPath('data.permissions.projectTasks', false);
            $this->getJson('/api/v1/agent/projects/'.$project->uuid.'/tasks', $headers)->assertForbidden();
        }
        $this->assertDatabaseCount('integration_tokens', 1);
        $this->assertDatabaseCount('agent_credential_links', 2);
        $this->assertSame(['agent:profile'], json_decode(DB::table('integration_tokens')->where('uuid', $key['id'])->value('scopes'), true));
    }
}
