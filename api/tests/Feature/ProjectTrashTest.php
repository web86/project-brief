<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\IntegrationTokens;
use App\Services\ProjectLifecycle;
use App\Services\ProjectNotificationService;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectTrashTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function statuses(): array
    {
        return [['active'], ['inactive']];
    }

    #[DataProvider('statuses')]
    public function test_lifecycle_preserves_original_activity_and_all_project_data(string $status): void
    {
        Storage::fake('local');
        $project = Project::factory()->create(['status' => $status]);
        $task = Task::factory()->for($project)->create();
        $alreadyDeleted = Task::factory()->for($project)->create();
        $alreadyDeleted->delete();
        $comment = $task->comments()->create(['project_id' => $project->id, 'author_type' => 'admin', 'text' => 'Preserve me']);
        Storage::disk('local')->put('attachments/preserved.txt', 'preserved');
        $file = $task->attachments()->create(['comment_id' => $comment->id, 'original_name' => 'file.txt', 'stored_name' => 'file.txt', 'mime_type' => 'text/plain', 'size' => 9, 'disk' => 'local', 'path' => 'attachments/preserved.txt', 'uploaded_by_type' => 'admin']);
        $developer = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->actingAs($this->ownerFor($project));
        $root = '/api/admin/projects/'.$project->uuid;
        $this->getJson('/api/admin/projects/trash')->assertJsonCount(0, 'data');
        $this->postJson($root.'/trash')->assertOk();
        app(ProjectNotificationService::class)->notify(new ProjectNotificationRequested(1, 'client.task_created', $task->id, 'client', null, 'Client'));
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertSoftDeleted($project);
        $trashed = Project::withTrashed()->findOrFail($project->id);
        $this->assertSame('inactive', $trashed->status);
        $this->assertSame($status, $trashed->status_before_trash);
        $this->getJson('/api/admin/projects')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/projects/trash')->assertJsonPath('data.0.id', $project->uuid)->assertJsonStructure(['data' => [['deletedAt']]]);
        foreach (['', '/tasks', '/sections', '/clients', '/team', '/discussions', '/activity', '/notifications', '/trash'] as $suffix) {
            $this->getJson($root.$suffix)->assertNotFound();
        }
        $this->postJson($root.'/export', ['format' => 'docx', 'scope' => 'all', 'locale' => 'en'])->assertNotFound();
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertNotFound();
        $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertNotFound();
        $this->postJson('/api/admin/tasks/'.$alreadyDeleted->uuid.'/restore')->assertNotFound();
        $this->postJson($root.'/trash')->assertNotFound();
        $this->postJson($root.'/restore')->assertOk();
        $this->assertSame($status, $project->fresh()->status);
        $this->assertNull($project->fresh()->status_before_trash);
        $this->assertSame(1, $project->tasks()->count());
        $this->assertTrue($alreadyDeleted->fresh()->trashed());
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'text' => 'Preserve me']);
        $this->assertDatabaseHas('attachments', ['id' => $file->id]);
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($developer)->getJson($root)->assertOk();
    }

    public function test_counts_are_aggregated_exclude_deleted_tasks_and_inactive_developers(): void
    {
        $project = Project::factory()->create(['currency' => 'EUR']);
        Task::factory()->for($project)->create(['status' => 'done']);
        Task::factory()->for($project)->create(['status' => 'new']);
        Task::factory()->for($project)->create(['status' => 'done'])->delete();
        foreach (['active', 'inactive'] as $status) {
            $user = User::factory()->memberOf($project->workspace)->create();
            $user->workspaceMembers()->first()->update(['status' => $status]);
            $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $user->workspaceMembers()->first()->id]);
        }
        $this->actingAs($this->ownerFor($project));
        DB::enableQueryLog();
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonPath('data.0.clientsCount', 1)->assertJsonPath('data.0.teamCount', 1)->assertJsonPath('data.0.tasksCount', 2)->assertJsonPath('data.0.doneCount', 1)->assertJsonPath('data.0.currency', 'EUR')->assertJsonStructure(['data' => [['updatedAt']]]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        Project::factory()->count(12)->for($project->workspace)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/admin/projects')->assertJsonCount(13, 'data')->assertJsonPath('data.0.tasksCount', 0)->assertJsonPath('data.0.doneCount', 0);
        $this->assertLessThanOrEqual($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_permissions_and_workspace_isolation_apply_to_every_trash_operation(): void
    {
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $developer = User::factory()->memberOf($project->workspace)->create();
        $assignment = $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $root = '/api/admin/projects/'.$project->uuid;
        $this->actingAs($developer)->postJson($root.'/trash')->assertForbidden();
        $assignment->update(['permissions' => ['manage_trash' => true]]);
        $this->postJson($root.'/trash')->assertOk();
        $this->getJson('/api/admin/projects/trash')->assertJsonCount(1, 'data');
        $this->deleteJson($root.'/permanent', ['confirmed' => true])->assertForbidden();
        $this->deleteJson('/api/admin/projects/trash', ['confirmed' => true, 'projectIds' => [$project->uuid]])->assertForbidden();
        $assignment->update(['permissions' => ['manage_trash' => false]]);
        $this->postJson($root.'/restore')->assertForbidden();
        $this->getJson('/api/admin/projects/trash')->assertJsonCount(0, 'data');
        $this->actingAs($owner)->postJson($root.'/restore')->assertOk();
        foreach (['trash', 'restore'] as $action) {
            $this->postJson('/api/admin/projects/'.$foreign->uuid.'/'.$action)->assertNotFound();
        }
        $this->deleteJson('/api/admin/projects/'.$foreign->uuid.'/permanent', ['confirmed' => true])->assertNotFound();
        $this->assertNotNull($foreign->fresh());
    }

    public function test_client_sessions_cannot_mutate_or_read_trashed_projects_and_work_after_restore(): void
    {
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'client')]);
        $session = ['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id];
        $root = '/api/admin/projects/'.$project->uuid;
        $this->withSession($session)->postJson($root.'/trash')->assertUnauthorized();
        $this->postJson($root.'/restore')->assertUnauthorized();
        $this->deleteJson($root.'/permanent', ['confirmed' => true])->assertUnauthorized();
        $this->deleteJson('/api/admin/projects/trash', ['confirmed' => true, 'projectIds' => []])->assertUnauthorized();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner)->postJson($root.'/trash')->assertOk();
        $this->withSession($session)->getJson('/api/client/project')->assertUnauthorized();
        $this->postJson($root.'/restore')->assertOk();
        $this->withSession($session)->getJson('/api/client/project')->assertOk()->assertJsonPath('data.id', $project->uuid);
    }

    public function test_empty_trash_requires_exact_confirmation_and_preserves_other_projects_and_users(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->workspace)->create();
        $foreign = Project::factory()->create();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $member = $developer->workspaceMembers()->first();
        foreach ([$project, $other] as $p) {
            $p->members()->create(['workspace_id' => $p->workspace_id, 'workspace_member_id' => $member->id]);
        }
        $task = Task::factory()->for($project)->create();
        $comment = $task->comments()->create(['project_id' => null, 'author_type' => 'admin', 'text' => 'Legacy']);
        $discussion = $project->tasks()->first()->comments()->create(['project_id' => $project->id, 'task_id' => null, 'author_type' => 'admin', 'text' => 'Discussion']);
        $task->history()->create(['actor_type' => 'admin', 'event_type' => 'created']);
        foreach ([$comment, $discussion] as $c) {
            $path = 'attachments/'.$c->uuid;
            Storage::disk('local')->put($path, 'file');
            Storage::disk('local')->put($path.'.thumb', 'thumb');
            $c->attachments()->create(['task_id' => $c->task_id, 'original_name' => 'file', 'stored_name' => 'file', 'mime_type' => 'text/plain', 'size' => 4, 'disk' => 'local', 'path' => $path, 'thumbnail_path' => $path.'.thumb', 'uploaded_by_type' => 'admin']);
        }
        $task->delete();
        $this->actingAs($this->ownerFor($project));
        $users = User::count();
        $invitation = DeveloperInvitation::create(['workspace_id' => $project->workspace_id, 'invited_by' => auth()->id(), 'email' => 'invite@example.test', 'project_ids' => [$project->id, $other->id], 'locale' => 'en', 'token_hash' => hash('sha256', 'invite'), 'expires_at' => now()->addDay()]);
        $this->postJson('/api/admin/projects/'.$project->uuid.'/trash')->assertOk();
        app(ProjectLifecycle::class)->trash($foreign);
        $this->deleteJson('/api/admin/projects/trash', ['projectIds' => [$project->uuid]])->assertUnprocessable();
        $this->deleteJson('/api/admin/projects/trash', ['confirmed' => true, 'projectIds' => []])->assertConflict();
        $this->deleteJson('/api/admin/projects/trash', ['confirmed' => true, 'projectIds' => [$project->uuid, $foreign->uuid]])->assertConflict();
        $this->deleteJson('/api/admin/projects/trash', ['confirmed' => true, 'projectIds' => [$project->uuid]])->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        foreach (['tasks', 'comments', 'attachments', 'task_history', 'project_file_deletions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame($users, User::count());
        $this->assertNotNull($other->fresh());
        $this->assertNotNull(Project::withTrashed()->find($foreign->id));
        $this->assertDatabaseHas('project_members', ['project_id' => $other->id, 'workspace_member_id' => $member->id]);
        $this->assertSame([$other->id], $invitation->fresh()->project_ids);
    }

    public function test_rest_reads_idempotent_replays_and_webhook_outbox_exclude_trash(): void
    {
        Http::preventStrayRequests();
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'example.com', 'ip' => '93.184.216.34']);
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $this->postJson('/api/admin/integrations/webhooks', ['name' => 'Test', 'url' => 'https://example.com/events', 'events' => ['project.updated']])->assertCreated();
        app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'Before']);
        $headers = ['Authorization' => 'Bearer '.app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Test', 'scopes' => IntegrationTokens::SCOPES])['secret'], 'Idempotency-Key' => 'create'];
        $body = ['title' => 'Replay'];
        $created = $this->postJson('/api/v1/projects', $body, $headers)->assertCreated()->json('data.id');
        $root = '/api/admin/projects/'.$project->uuid;
        $this->postJson($root.'/trash')->assertOk();
        $this->getJson('/api/v1/projects/'.$project->uuid, $headers)->assertNotFound();
        foreach (['tasks', 'comments'] as $suffix) {
            $this->getJson('/api/v1/projects/'.$project->uuid.'/'.$suffix, $headers)->assertNotFound();
        }
        $this->getJson('/api/v1/projects', $headers)->assertJsonCount(1, 'data')->assertDontSee($project->uuid);
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'cancelled', 'error_code' => 'project_trashed']);
        $this->assertSame(0, app(WebhookProcessor::class)->process());
        Http::assertNothingSent();
        $this->postJson('/api/admin/projects/'.$created.'/trash')->assertOk();
        $this->postJson('/api/v1/projects', $body, $headers)->assertNotFound();
        $this->postJson('/api/admin/projects/'.$created.'/restore')->assertOk();
        $this->postJson('/api/v1/projects', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->postJson('/api/admin/projects/'.$created.'/trash')->assertOk();
        $this->deleteJson('/api/admin/projects/'.$created.'/permanent', ['confirmed' => true])->assertOk();
        $this->postJson('/api/v1/projects', $body, $headers)->assertGone()->assertDontSee('Replay');
        $this->deleteJson($root.'/permanent', ['confirmed' => true])->assertOk();
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_team_edits_preserve_assignments_of_trashed_projects_without_listing_them(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->workspace)->create();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $member = $developer->workspaceMembers()->first();
        foreach ([$project, $other] as $p) {
            $p->members()->create(['workspace_id' => $p->workspace_id, 'workspace_member_id' => $member->id]);
        }
        $this->actingAs($this->ownerFor($project));
        $this->postJson('/api/admin/projects/'.$project->uuid.'/trash')->assertOk();
        $this->getJson('/api/admin/projects/'.$other->uuid.'/team')->assertOk()->assertDontSee($project->uuid);
        $this->patchJson('/api/admin/projects/'.$other->uuid.'/team/members/'.$member->id, ['projects' => [$other->uuid]])->assertOk();
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'workspace_member_id' => $member->id]);
    }

    public function test_failed_file_cleanup_is_durable_and_retryable(): void
    {
        $id = DB::table('project_file_deletions')->insertGetId(['disk' => 'local', 'path' => 'attachments/retry']);
        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')->with('attachments/retry')->andReturn(true);
        $disk->shouldReceive('delete')->with('attachments/retry')->andReturn(false, true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->artisan('projects:cleanup-files')->assertFailed();
        $this->assertDatabaseHas('project_file_deletions', ['id' => $id]);
        $this->artisan('projects:cleanup-files')->assertSuccessful();
        $this->assertDatabaseCount('project_file_deletions', 0);
    }

    public function test_rollback_refuses_to_expose_trash_or_drop_pending_file_cleanup(): void
    {
        $migration = require database_path('migrations/2026_10_26_200540_add_project_trash_lifecycle.php');
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project))->postJson('/api/admin/projects/'.$project->uuid.'/trash')->assertOk();
        try {
            $migration->down();
            $this->fail('Rollback exposed trash.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('before rollback', $exception->getMessage());
        }
        $this->postJson('/api/admin/projects/'.$project->uuid.'/restore')->assertOk();
        DB::table('project_file_deletions')->insert(['disk' => 'local', 'path' => 'attachments/pending']);
        try {
            $migration->down();
            $this->fail('Rollback lost pending cleanup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('before rollback', $exception->getMessage());
        }
        DB::table('project_file_deletions')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('projects', 'deleted_at'));
        $migration->up();
        $this->assertSame('active', $project->fresh()->status);
    }
}
