<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectUnreadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function history(Task $task, string $actor, string $type): int
    {
        return $task->history()->create(['actor_type' => $actor, 'event_type' => $type, 'meta' => ['text' => 'Event'], 'created_at' => now()])->id;
    }

    public function test_read_state_is_personal_and_only_relevant_client_activity_counts(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $admin = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id]);
        $task = Task::factory()->for($project)->create();
        foreach (['created', 'comment', 'approved'] as $type) {
            $this->history($task, 'client', $type);
        }
        foreach (['created', 'comment', 'approved', 'status', 'developer_notes', 'reordered'] as $type) {
            $this->history($task, 'admin', $type);
        }
        foreach (['updated', 'attachment', 'reordered', 'developer_notes', 'price'] as $type) {
            $this->history($task, 'client', $type);
        }
        $url = '/api/admin/projects/'.$project->uuid.'/activity';
        $this->actingAs($owner)->getJson($url)->assertExactJson(['unreadCount' => 3]);
        $this->getJson('/api/admin/projects')->assertJsonPath('data.0.unreadCount', 3);
        $this->assertDatabaseCount('project_reads', 0);
        $this->postJson($url.'/read')->assertExactJson(['unreadCount' => 0]);
        $this->actingAs($admin)->getJson($url)->assertExactJson(['unreadCount' => 3]);
        $this->postJson($url.'/read')->assertExactJson(['unreadCount' => 0]);
        $new = $this->history($task, 'client', 'comment');
        $this->getJson($url)->assertExactJson(['unreadCount' => 1]);
        $this->actingAs($owner)->getJson($url)->assertExactJson(['unreadCount' => 1]);
        $this->assertDatabaseCount('project_reads', 2);
        $this->postJson($url.'/read')->assertOk();
        $this->assertDatabaseHas('project_reads', ['project_id' => $project->id, 'user_id' => $owner->id, 'last_seen_history_id' => $new]);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_cross_tenant_and_unassigned_and_revoked_members_cannot_read_or_mark_activity(): void
    {
        $project = Project::factory()->create();
        $this->history(Task::factory()->for($project)->create(), 'client', 'comment');
        $foreign = User::factory()->owner()->create();
        $unassigned = User::factory()->memberOf($project->workspace)->create();
        foreach ([$foreign, $unassigned] as $user) {
            $this->actingAs($user)->postJson('/api/admin/workspaces/'.$user->workspaceMembers()->first()->workspace->uuid.'/select')->assertOk();
            $this->getJson('/api/admin/projects/'.$project->uuid.'/activity')->assertNotFound();
            $this->postJson('/api/admin/projects/'.$project->uuid.'/activity/read')->assertNotFound();
            $this->getJson('/api/admin/projects')->assertJsonCount(0, 'data');
        }
        $owner = $this->ownerFor($project);
        $this->actingAs($owner)->postJson('/api/admin/workspaces/'.$project->workspace->uuid.'/select')->assertOk();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/activity/read')->assertOk();
        $owner->workspaceMembers()->update(['status' => 'inactive']);
        $this->getJson('/api/admin/projects/'.$project->uuid.'/activity')->assertForbidden();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/activity/read')->assertForbidden();
        $this->assertDatabaseCount('project_reads', 1);
    }

    public function test_deleted_history_never_lowers_watermark_or_resurrects_already_seen_activity(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $old = Task::factory()->for($project)->create();
        $remaining = Task::factory()->for($project)->create();
        $this->history($remaining, 'client', 'created');
        $highest = $this->history($old, 'client', 'comment');
        $url = '/api/admin/projects/'.$project->uuid.'/activity';
        $this->actingAs($owner)->postJson($url.'/read')->assertExactJson(['unreadCount' => 0]);
        $old->delete();
        $this->postJson($url.'/read')->assertExactJson(['unreadCount' => 0]);
        $this->assertDatabaseHas('project_reads', ['last_seen_history_id' => $highest]);
        $this->history($remaining, 'client', 'approved');
        $this->getJson($url)->assertExactJson(['unreadCount' => 1]);
        $remaining->history()->delete();
        $this->postJson($url.'/read')->assertExactJson(['unreadCount' => 0]);
        $this->assertDatabaseHas('project_reads', ['last_seen_history_id' => $highest]);
    }

    public function test_client_cannot_supply_a_read_watermark_and_regular_project_gets_do_not_advance_it(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->history(Task::factory()->for($project)->create(), 'client', 'comment');
        $this->actingAs($owner);
        $root = '/api/admin/projects/'.$project->uuid;
        foreach (['', '?summary=1', '/clients', '/sections', '/notifications', '/tasks', '/activity'] as $suffix) {
            $this->getJson($root.$suffix)->assertOk();
        }
        $this->assertDatabaseCount('project_reads', 0);
        $this->postJson($root.'/activity/read', ['lastSeenHistoryId' => PHP_INT_MAX])->assertUnprocessable();
        $this->assertDatabaseCount('project_reads', 0);
        $this->getJson($root.'/activity')->assertExactJson(['unreadCount' => 1]);
        $this->getJson($root.'?summary=1')->assertJsonMissingPath('data.sections');
        $this->getJson($root)->assertJsonCount(2, 'data.sections');
    }

    public function test_project_list_uses_a_constant_query_count_and_never_loads_structure_per_project(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->owner($workspace)->create();
        Project::factory()->for($workspace)->create();
        $this->actingAs($owner)->getJson('/api/admin/me')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonMissingPath('data.0.sections');
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        $projects = Project::factory()->count(12)->for($workspace)->create();
        foreach ($projects as $project) {
            $this->history(Task::factory()->for($project)->create(), 'client', 'comment');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(13, 'data');
        $this->assertSame($single, count(DB::getQueryLog()));
        $this->assertSame(12, array_sum(array_column($response->json('data'), 'unreadCount')));
        DB::disableQueryLog();
    }
}
