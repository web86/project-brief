<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectDiscussions;
use App\Services\ProjectNotificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $person = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $person->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $person->id, 'client_access_token_id' => $token->id]);
    }

    public function test_atomic_placement_at_any_position_across_empty_sections_and_stale_anchors(): void
    {
        $project = Project::factory()->create(['sections' => ['Main', 'Empty']]);
        [$main, $empty] = $project->briefSections;
        $tasks = Task::factory()->count(4)->for($project)->create(['section' => 'Main']);
        $this->actingAs($this->ownerFor($project));
        $place = fn ($task, $data) => $this->postJson('/api/admin/tasks/'.$task->uuid.'/place', $data);
        $place($tasks[3], ['sectionId' => $main->uuid, 'beforeTaskId' => $tasks[1]->uuid])->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame([$tasks[0]->id, $tasks[3]->id, $tasks[1]->id, $tasks[2]->id], $main->tasks()->orderBy('position')->pluck('id')->all());
        $place($tasks[3], ['sectionId' => $empty->uuid, 'beforeTaskId' => null, 'afterTaskId' => null])->assertOk();
        $this->assertSame(1, $tasks[3]->fresh()->position);
        $place($tasks[1], ['sectionId' => $empty->uuid, 'beforeTaskId' => $tasks[3]->uuid])->assertOk();
        $this->assertSame([$tasks[1]->id, $tasks[3]->id], $empty->tasks()->orderBy('position')->pluck('id')->all());
        $place($tasks[0], ['sectionId' => $main->uuid, 'beforeTaskId' => $tasks[3]->uuid])->assertConflict();
        $place($tasks[0], ['sectionId' => $empty->uuid, 'afterTaskId' => $tasks[1]->uuid])->assertOk();
        $this->assertSame([1, 2, 3], $empty->tasks()->orderBy('position')->pluck('position')->all());
        $this->getJson('/api/admin/tasks/'.$tasks[0]->uuid)->assertJsonPath('data.displayNumber', '2.2');
        $place($tasks[0], ['sectionId' => $empty->uuid, 'beforeTaskId' => $tasks[1]->uuid, 'afterTaskId' => $tasks[3]->uuid])->assertUnprocessable();
        $foreign = Task::factory()->create();
        $place($tasks[0], ['sectionId' => $foreign->projectSection->uuid])->assertNotFound();
        $place($foreign, ['sectionId' => $main->uuid])->assertNotFound();
    }

    public static function deletionStates(): array
    {
        $cases = [];
        foreach (['new', 'clarification', 'approved', 'in_progress', 'review', 'done'] as $status) {
            foreach ([false, true] as $approved) {
                foreach (['admin', 'client'] as $actor) {
                    $cases[] = [$actor, $status, $approved];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('deletionStates')]
    public function test_both_actors_recheck_current_status_and_approval_before_trashing(string $actor, string $status, bool $approved): void
    {
        $task = Task::factory()->create();
        if ($actor === 'admin') {
            $this->actingAs($this->ownerFor($task->project));
        } else {
            $this->client($task->project);
        }
        $url = '/api/'.$actor.'/tasks/'.$task->uuid;
        $this->getJson($url)->assertOk();
        $task->update(['status' => $status, 'client_approved' => $approved]);
        $response = $this->deleteJson($url);
        if ($status === 'new' && ! $approved) {
            $response->assertOk();
            $this->assertSoftDeleted($task);
            $this->deleteJson($url)->assertNotFound();
            $this->assertDatabaseCount('task_history', 1);
        } else {
            $response->assertForbidden();
            $this->assertNotSoftDeleted($task);
        }
    }

    public function test_trash_preserves_everything_excludes_regular_reads_and_restores_original_slot(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        Storage::fake('local');
        $task = Task::factory()->create(['title' => 'TRASH_ONLY_SENTINEL']);
        $project = $task->project;
        $sibling = Task::factory()->for($project)->create(['section' => $task->section]);
        $owner = $this->ownerFor($project);
        $comment = $task->comments()->create(['author_type' => 'client', 'text' => 'Retain me']);
        $task->history()->create(['actor_type' => 'client', 'event_type' => 'created', 'meta' => ['text' => 'Original event'], 'created_at' => now()]);
        Storage::disk('local')->put('retained.txt', 'original bytes');
        $file = $task->attachments()->create(['uploaded_by_type' => 'client', 'original_name' => 'retained.txt', 'stored_name' => 'retained.txt', 'mime_type' => 'text/plain', 'size' => 14, 'disk' => 'local', 'path' => 'retained.txt']);
        $this->client($project);
        $this->deleteJson('/api/client/tasks/'.$task->uuid)->assertOk();
        $this->assertSoftDeleted($task);
        $this->assertModelExists($comment);
        $this->assertModelExists($file);
        $this->assertDatabaseHas('task_history', ['task_id' => $task->id, 'event_type' => 'created']);
        Storage::disk('local')->assertExists('retained.txt');
        $this->assertSame(1, $sibling->fresh()->position);
        app(ProjectNotificationService::class)->notify(new ProjectNotificationRequested(1, 'client.task_created', $task->id, 'client', $project->clients()->first()->id, 'Client'));
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->getJson('/api/client/tasks')->assertJsonCount(1, 'data');
        $this->getJson('/api/client/tasks/'.$task->uuid)->assertNotFound();
        $this->get('/api/client/attachments/'.$file->uuid.'/download')->assertNotFound();
        $this->postJson('/api/client/tasks/'.$task->uuid.'/restore')->assertNotFound();
        $this->actingAs($owner);
        $root = '/api/admin/projects/'.$project->uuid;
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertNotFound();
        $this->getJson($root.'/trash')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.comments.0.text', 'Retain me');
        $this->getJson($root.'/activity')->assertJsonPath('unreadCount', 0);
        $this->getJson('/api/admin/projects')->assertJsonPath('data.0.tasksCount', 1);
        $this->assertSame(0, app(ProjectDiscussions::class)->query($project)->count());
        $export = $this->postJson($root.'/export', ['format' => 'docx', 'scope' => 'all', 'locale' => 'en'])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'trash-export-');
        file_put_contents($path, $export->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertStringNotContainsString('TRASH_ONLY_SENTINEL', $zip->getFromName('word/document.xml'));
        $zip->close();
        unlink($path);
        $this->deleteJson($root.'/sections/'.$task->projectSection->uuid)->assertUnprocessable();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/restore')->assertOk()->assertJsonPath('data.position', 1);
        $this->assertNotSoftDeleted($task);
        $this->assertSame(2, $sibling->fresh()->position);
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/restore')->assertConflict();
        $this->get('/api/admin/attachments/'.$file->uuid.'/download')->assertOk();
        $this->getJson($root.'/trash')->assertJsonCount(0, 'data');
        Event::assertNotDispatched(ProjectNotificationRequested::class);
    }

    public function test_foreign_project_trash_delete_and_restore_are_inaccessible(): void
    {
        $own = Task::factory()->create();
        $other = Task::factory()->create();
        $this->client($own->project);
        $this->deleteJson('/api/client/tasks/'.$other->uuid)->assertNotFound();
        $other->delete();
        $this->actingAs($this->ownerFor($own->project));
        $this->getJson('/api/admin/projects/'.$other->project->uuid.'/trash')->assertNotFound();
        $this->postJson('/api/admin/tasks/'.$other->uuid.'/restore')->assertNotFound();
        $this->deleteJson('/api/admin/tasks/'.$other->uuid)->assertNotFound();
        $this->actingAs(User::factory()->memberOf($own->project->workspace)->create());
        $own->delete();
        $this->postJson('/api/admin/tasks/'.$own->uuid.'/restore')->assertNotFound();
    }
}
