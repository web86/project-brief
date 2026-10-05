<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $token = $project->accessTokens()->create(['token_hash' => hash('sha256', bin2hex(random_bytes(32)))]);
        $this->withSession(['client_project_id' => $project->id, 'client_access_token_id' => $token->id]);
    }

    public function test_upload_is_private_and_authorized_download_and_preview_work(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->image('screenshot.png')]], ['Accept' => 'application/json'])->assertOk();
        $file = Attachment::first();
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame('client', $file->uploaded_by_type);
        $this->assertDatabaseHas('task_history', ['event_type' => 'attachment']);
        $this->get('/api/client/attachments/'.$file->uuid.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/client/attachments/'.$file->uuid.'/preview')->assertOk();
        $this->actingAs(User::factory()->create())->get('/api/admin/attachments/'.$file->uuid.'/download')->assertOk();
    }

    public function test_unsupported_disguised_and_oversized_files_are_rejected(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        foreach ([UploadedFile::fake()->createWithContent('unsafe.html', '<script>alert(1)</script>'), UploadedFile::fake()->createWithContent('fake.png', '<html>unsafe</html>'), UploadedFile::fake()->image('large.png')->size(20481)] as $file) {
            $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [$file]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        }
        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('local')->assertDirectoryEmpty('attachments');
    }

    public function test_other_project_and_guest_cannot_download_or_upload(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->image('safe.png')]], ['Accept' => 'application/json'])->assertOk();
        $file = Attachment::first();
        $this->client(Project::factory()->create());
        $this->getJson('/api/client/attachments/'.$file->uuid.'/download')->assertNotFound();
        $this->getJson('/api/client/attachments/'.$file->uuid.'/preview')->assertNotFound();
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->image('safe.png')]], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertUnauthorized();
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_creation_with_files_is_atomic_and_count_limit_is_enforced(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $this->client($project);
        $this->post('/api/client/tasks', ['title' => 'Скриншот', 'location' => 'Главная', 'description' => 'Пример', 'priority' => 'normal', 'attachments' => [UploadedFile::fake()->image('safe.png')]], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'data.attachments');
        $this->post('/api/client/tasks', ['title' => 'Скриншот', 'location' => 'Главная', 'description' => 'Пример', 'priority' => 'normal', 'attachments' => [UploadedFile::fake()->createWithContent('fake.png', '<html>x</html>')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_original_filename_cannot_choose_storage_path(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $source = UploadedFile::fake()->image('safe.png');
        $file = new UploadedFile($source->getPathname(), '..\\..\\danger.png', 'image/png', null, true);
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [$file]], ['Accept' => 'application/json'])->assertOk();
        $attachment = Attachment::first();
        $this->assertSame('danger.png', $attachment->original_name);
        $this->assertStringStartsWith('attachments/'.$task->uuid.'/',$attachment->path);
        $this->assertStringNotContainsString('..',$attachment->path);
    }
}
