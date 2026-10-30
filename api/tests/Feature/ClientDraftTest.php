<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientDraftTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $person = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $person->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $person->id, 'client_access_token_id' => $token->id]);
    }

    public static function editableStates(): array
    {
        return [['new'], ['clarification']];
    }

    public static function lockedStates(): array
    {
        return [['approved', false], ['in_progress', false], ['review', false], ['done', false], ['new', true], ['clarification', true]];
    }

    #[DataProvider('editableStates')]
    public function test_editing_is_allowed_for_unapproved_drafts_and_trash_is_restricted_to_new(string $status): void
    {
        Storage::fake('local');
        $task = Task::factory()->create(['status' => $status, 'client_approved' => false]);
        $sibling = Task::factory()->for($task->project)->create(['section' => $task->section]);
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$task->uuid, ['title' => 'Updated', 'section' => ' Mobile menu ', 'location' => 'https://example.com/item', 'description' => 'New brief', 'expectedResult' => 'Expected', 'priority' => 'high'])->assertOk()->assertJsonPath('data.section.name', 'Mobile menu')->assertJsonPath('data.location', 'https://example.com/item');
        $this->assertSame('Updated', $task->fresh()->title);
        $this->assertSame(1, $sibling->fresh()->position);
        $this->assertDatabaseHas('task_history', ['task_id' => $task->id, 'event_type' => 'updated', 'project_client_id' => $task->project->clients()->first()->id]);
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->createWithContent('draft.txt', 'draft content')]], ['Accept' => 'application/json'])->assertOk();
        $path = $task->attachments()->first()->path;
        $task->comments()->create(['author_type' => 'client', 'text' => 'Draft discussion']);
        $other = Task::factory()->create();
        Storage::disk('local')->put('other/private.txt', 'keep');
        $other->attachments()->create(['uploaded_by_type' => 'client', 'original_name' => 'private.txt', 'stored_name' => 'private.txt', 'mime_type' => 'text/plain', 'size' => 4, 'disk' => 'local', 'path' => 'other/private.txt']);
        $section = $task->fresh()->projectSection;
        if ($status === 'clarification') {
            $this->deleteJson('/api/client/tasks/'.$task->uuid)->assertForbidden();

            return;
        }
        $this->deleteJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertJsonPath('deleted', true);
        $this->assertSoftDeleted($task);
        $this->assertDatabaseHas('comments', ['task_id' => $task->id]);
        $this->assertDatabaseHas('task_history', ['task_id' => $task->id]);
        $this->assertDatabaseHas('attachments', ['task_id' => $task->id]);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists('other/private.txt');
        $this->assertModelExists($section);
        $this->assertModelExists($sibling);
    }

    #[DataProvider('lockedStates')]
    public function test_edit_delete_and_attachment_changes_are_forbidden_after_approval_or_work(string $status, bool $approved): void
    {
        Storage::fake('local');
        $task = Task::factory()->create(['status' => $status, 'client_approved' => $approved]);
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$task->uuid, ['title' => 'Tampered'])->assertForbidden();
        $this->deleteJson('/api/client/tasks/'.$task->uuid)->assertForbidden();
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->createWithContent('new.txt', 'new')]], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertModelExists($task);
        $this->assertNotSame('Tampered', $task->fresh()->title);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_free_form_names_are_trimmed_only_reused_and_all_positions_are_contiguous(): void
    {
        $project = Project::factory()->create();
        $project->briefSections()->first()->update(['position' => 8]);
        $this->client($project);
        $make = fn ($name) => $this->postJson('/api/client/tasks', ['title' => 'Idea', 'location' => $name, 'description' => 'Description', 'priority' => 'normal'])->assertCreated()->json('data');
        $first = $make('  https://Example.com/Delivery/?A=B  ');
        $second = $make('https://Example.com/Delivery/?A=B');
        $lower = $make('https://example.com/delivery/?a=b');
        $this->assertSame($first['sectionId'], $second['sectionId']);
        $this->assertNotSame($first['sectionId'], $lower['sectionId']);
        $this->assertSame('https://Example.com/Delivery/?A=B', $first['section']['name']);
        $this->assertSame([1, 2], $project->tasks()->where('project_section_id', Task::where('uuid', $first['id'])->first()->project_section_id)->orderBy('position')->pluck('position')->all());
        $this->assertSame([1, 2, 3, 4], $project->briefSections()->pluck('position')->all());
        $this->deleteJson('/api/client/tasks/'.$first['id'])->assertOk();
        $this->getJson('/api/client/tasks/'.$second['id'])->assertOk()->assertJsonPath('data.position', 1);
    }

    public function test_other_projects_and_protected_fields_cannot_be_changed(): void
    {
        $own = Task::factory()->create();
        $other = Task::factory()->create();
        $this->client($own->project);
        $this->patchJson('/api/client/tasks/'.$other->uuid, ['title' => 'Tampered'])->assertNotFound();
        $this->deleteJson('/api/client/tasks/'.$other->uuid)->assertNotFound();
        foreach (['client_approved' => true, 'position' => 99, 'project_section_id' => $other->project_section_id, 'project_client_id' => 99, 'estimateHours' => 20, 'price' => 10, 'developerNotes' => 'secret'] as $key => $value) {
            $this->patchJson('/api/client/tasks/'.$own->uuid, [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->patchJson('/api/client/tasks/'.$own->uuid, ['status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors('editVersion');
        $version = $this->getJson('/api/client/tasks/'.$own->uuid)->assertOk()->json('data.editVersion');
        $this->patchJson('/api/client/tasks/'.$own->uuid, ['status' => 'done', 'editVersion' => $version])->assertForbidden();
        $this->assertModelExists($other);
        $this->assertSame('new', $own->fresh()->status);
    }

    public function test_shared_legacy_file_is_retained_for_its_other_task(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $other = Task::factory()->create();
        $this->client($task->project);
        Storage::disk('local')->put('shared/file.txt', 'shared original');
        foreach ([$task, $other] as $owner) {
            $owner->attachments()->create(['uploaded_by_type' => 'client', 'original_name' => 'file.txt', 'stored_name' => 'file.txt', 'mime_type' => 'text/plain', 'size' => 15, 'disk' => 'local', 'path' => 'shared/file.txt']);
        }
        $this->deleteJson('/api/client/tasks/'.$task->uuid)->assertOk();
        $this->assertSoftDeleted($task);
        $this->assertModelExists($other);
        $this->assertDatabaseHas('attachments', ['task_id' => $other->id, 'path' => 'shared/file.txt']);
        Storage::disk('local')->assertExists('shared/file.txt');
    }

    public function test_guest_cannot_delete_a_client_idea(): void
    {
        $this->deleteJson('/api/client/tasks/00000000-0000-4000-8000-000000000000')->assertUnauthorized();
    }
}
