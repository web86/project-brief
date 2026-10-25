<?php

namespace Tests\Feature;

use App\Mail\ProjectActivityMail;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\ProjectNotificationSetting;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\NotificationEvents;
use App\Services\NotificationPayload;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CommentAttachmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', Str::random(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    private function send(string $url, array $files, string $text = '', ?string $key = null): TestResponse
    {
        return $this->post($url, ['text' => $text, 'submissionId' => $key ?? Str::uuid()->toString(), 'attachments' => $files], ['Accept' => 'application/json']);
    }

    public function test_task_comment_saves_original_and_separate_bounded_thumbnail_and_one_history_event(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->send('/api/client/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->image('screen.png', 1200, 800)], 'Screenshot')
            ->assertOk()->assertJsonCount(0, 'data.attachments')->assertJsonCount(1, 'data.comments.0.attachments')
            ->assertJsonPath('data.comments.0.text', 'Screenshot')->assertDontSee('thumbnail_path')->assertDontSee('attachments/comments/');
        $file = Attachment::firstOrFail();
        Storage::disk('local')->assertExists([$file->path, $file->thumbnail_path]);
        $this->assertSame($task->id, $file->task_id);
        $this->assertSame($task->project_id, Comment::first()->project_id);
        $size = getimagesize(Storage::disk('local')->path($file->thumbnail_path));
        $this->assertSame([480, 320], [$size[0], $size[1]]);
        $this->assertDatabaseCount('task_history', 1);
        $this->get('/api/client/attachments/'.$file->uuid.'/preview')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/api/client/attachments/'.$file->uuid.'/original')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/client/attachments/'.$file->uuid.'/download')->assertOk()->assertDownload('screen.png');
    }

    public function test_file_only_general_comment_supports_multiple_same_name_files_and_private_access(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $this->client($project);
        $this->send('/api/client/project/'.$project->uuid.'/discussions', [UploadedFile::fake()->createWithContent('notes.txt', 'one'), UploadedFile::fake()->createWithContent('notes.txt', 'two')])
            ->assertCreated()->assertJsonPath('data.text', '')->assertJsonCount(2, 'data.attachments');
        [$first, $second] = Attachment::all()->all();
        $this->assertNull($first->task_id);
        $this->assertNotSame($first->path, $second->path);
        $this->get('/api/client/attachments/'.$first->uuid.'/download')->assertOk();
        $this->get('/api/client/attachments/'.$first->uuid.'/preview')->assertNotFound();
        $this->get('/api/client/attachments/'.$first->uuid.'/original')->assertNotFound();
        $this->getJson('/api/client/project/'.$project->uuid.'/discussions?kind=general')->assertJsonCount(2, 'data.0.attachments');
        $this->actingAs($this->ownerFor($project))->get('/api/admin/attachments/'.$first->uuid.'/download')->assertOk();
    }

    public function test_retry_is_idempotent_and_changed_payload_with_same_key_is_rejected(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        foreach (['/api/client/tasks/'.$task->uuid.'/comments', '/api/client/project/'.$task->project->uuid.'/discussions'] as $url) {
            $key = Str::uuid()->toString();
            for ($i = 0; $i < 2; $i++) {
                $this->send($url, [UploadedFile::fake()->createWithContent('notes.txt', 'same')], '', $key)->assertSuccessful();
            }
            $this->send($url, [UploadedFile::fake()->createWithContent('notes.txt', 'different')], '', $key)->assertConflict();
        }
        $this->assertDatabaseCount('comments', 2);
        $this->assertDatabaseCount('attachments', 2);
        $this->assertDatabaseCount('task_history', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_retries_are_scoped_to_author_and_project(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $key = Str::uuid()->toString();
        $url = '/api/client/tasks/'.$task->uuid.'/comments';
        $this->send($url, [UploadedFile::fake()->createWithContent('notes.txt', 'one')], '', $key)->assertOk();
        $this->actingAs($this->ownerFor($task->project));
        $this->send('/api/admin/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->createWithContent('notes.txt', 'one')], '', $key)->assertOk();
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_empty_and_invalid_format_count_size_total_and_pixel_limits_are_rejected(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $url = '/api/client/tasks/'.$task->uuid.'/comments';
        $this->postJson($url, ['text' => '  '])->assertUnprocessable();
        $this->post($url, ['attachments' => [UploadedFile::fake()->image('image.png')]], ['Accept' => 'application/json'])->assertUnprocessable();
        foreach ([
            [UploadedFile::fake()->createWithContent('evil.png', '<script>x</script>')],
            [UploadedFile::fake()->createWithContent('evil.html', '<html>x</html>')],
            [UploadedFile::fake()->image('wide.png', 8193, 1)],
            [UploadedFile::fake()->image('large.png')->size(20481)],
            array_map(fn ($i) => UploadedFile::fake()->image($i.'.png'), range(1, 6)),
            array_map(fn ($i) => UploadedFile::fake()->image($i.'.png')->size(15000), range(1, 3)),
        ] as $files) {
            $this->send($url, $files)->assertUnprocessable();
        }
        $this->assertDatabaseCount('comments', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_database_failure_rolls_back_comment_files_and_thumbnail_and_retry_can_succeed(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $attempt = 0;
        Attachment::creating(function () use (&$attempt) {
            $attempt++;
            if ($attempt === 2) {
                throw new \RuntimeException('Simulated database failure');
            }
        });
        try {
            $key = Str::uuid()->toString();
            $files = fn () => [UploadedFile::fake()->image('first.png'), UploadedFile::fake()->createWithContent('notes.txt', 'second')];
            $url = '/api/client/tasks/'.$task->uuid.'/comments';
            $this->send($url, $files(), 'Retained', $key)->assertStatus(500);
            $this->assertDatabaseCount('comments', 0);
            $this->assertDatabaseCount('attachments', 0);
            $this->assertDatabaseCount('task_history', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->send($url, $files(), 'Retained', $key)->assertOk();
            $this->assertDatabaseCount('comments', 1);
        } finally {
            Attachment::flushEventListeners();
        }
    }

    public function test_storage_write_failure_rolls_back_comment(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->send('/api/client/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->assertStatus(500);
        $this->assertDatabaseCount('comments', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_foreign_uuid_and_revoked_client_and_developer_membership_cannot_read_any_attachment_kind(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $project = $task->project;
        $this->client($project);
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->image('idea.png')]], ['Accept' => 'application/json'])->assertOk();
        $this->send('/api/client/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->image('comment.png')])->assertOk();
        $this->send('/api/client/project/'.$project->uuid.'/discussions', [UploadedFile::fake()->image('general.png')])->assertCreated();
        $files = Attachment::all();
        $this->client(Project::factory()->create());
        foreach ($files as $file) {
            foreach (['download', 'preview', 'original'] as $action) {
                $this->getJson('/api/client/attachments/'.$file->uuid.'/'.$action)->assertNotFound();
            }
        }
        $this->client($project);
        $project->clients()->first()->update(['active' => false]);
        $this->getJson('/api/client/attachments/'.$files[0]->uuid.'/download')->assertUnauthorized();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $member = $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->actingAs($developer)->withSession(['workspace_id' => $project->workspace_id]);
        foreach ($files as $file) {
            $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertOk();
        }
        $member->delete();
        foreach ($files as $file) {
            $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertNotFound();
        }
    }

    public function test_trashed_idea_retains_comment_files_and_restore_restores_access(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->send('/api/client/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->image('screen.png')])->assertOk();
        $file = Attachment::first();
        $task->delete();
        $this->getJson('/api/client/attachments/'.$file->uuid.'/download')->assertNotFound();
        Storage::disk('local')->assertExists([$file->path, $file->thumbnail_path]);
        $this->actingAs($this->ownerFor($task->project))->postJson('/api/admin/tasks/'.$task->uuid.'/restore')->assertOk()->assertJsonCount(1, 'data.comments.0.attachments');
        $this->getJson('/api/admin/attachments/'.$file->uuid.'/download')->assertOk();
    }

    public function test_comment_attachments_do_not_consume_idea_file_limit(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->send('/api/client/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->image('screen.png')])->assertOk();
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => array_map(fn ($i) => UploadedFile::fake()->image($i.'.png'), range(1, 10))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(10, 'data.attachments')->assertJsonCount(1, 'data.comments.0.attachments');
    }

    public function test_attachment_only_notification_is_localized_and_links_to_comment_without_download_links(): void
    {
        Storage::fake('local');
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->send('/api/client/tasks/'.$task->uuid.'/comments', [UploadedFile::fake()->image('screen.png')])->assertOk();
        $comment = Comment::first();
        $this->assertSame('Клиент прикрепил изображение', NotificationPayload::commentText($comment, 'ru'));
        $payload = NotificationPayload::make('client.comment_created', $task, 'Anna', 'en', true, $task->history()->first()->id);
        $this->assertSame('Client attached an image', $payload['message']);
        $this->assertStringEndsWith('#comment-'.$comment->uuid, $payload['url']);
        $this->assertStringNotContainsString('/attachments/', json_encode($payload));
        $this->assertSame([], (new ProjectActivityMail($payload))->attachments);
        $this->assertDatabaseCount('task_history', 1);
    }

    public function test_general_file_batch_notifies_each_enabled_channel_once_including_after_retry(): void
    {
        Storage::fake('local');
        Mail::fake();
        Http::preventStrayRequests();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'local.example.test', 'mail.from.address' => 'brief@example.test',
            'telegram.transport' => 'deno', 'telegram.bridge_url' => 'https://test-telegram.deno.dev/telegram/send', 'telegram.bridge_secret' => str_repeat('b', 40)]);
        Http::fake(['test-telegram.deno.dev/*' => Http::response(['ok' => true])]);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        PushSubscription::factory()->create(['user_id' => $owner->id]);
        TelegramConnection::factory()->create(['user_id' => $owner->id]);
        ProjectNotificationSetting::where('project_id', $project->id)->first()->update(['delivery_mode' => 'personal', 'event_rules' => array_fill_keys(NotificationEvents::CODES, ['enabled' => true, 'recipients' => ['mode' => 'all', 'ids' => []]])]);
        $push = $this->mock(WebPushTransport::class);
        $push->shouldReceive('available')->andReturn(true);
        $push->shouldReceive('send')->once()->withArgs(function ($subscription, $payload) {
            $this->assertStringContainsString('#comment-', $payload['url']);
            $this->assertStringNotContainsString('/attachments/', json_encode($payload));

            return true;
        })->andReturn(['status' => 'sent', 'error_code' => null]);
        $this->client($project);
        $key = Str::uuid()->toString();
        for ($i = 0; $i < 2; $i++) {
            $this->send('/api/client/project/'.$project->uuid.'/discussions', [UploadedFile::fake()->createWithContent('one.txt', 'one'), UploadedFile::fake()->createWithContent('two.txt', 'two')], '', $key)->assertCreated();
        }
        Mail::assertSentCount(1);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => count($mail->attachments) === 0 && ! str_contains(json_encode($mail->payload), '/attachments/'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('comments', 1);
        $this->assertDatabaseCount('notification_deliveries', 3);
        $this->assertSame(3, NotificationDelivery::where('status', 'sent')->count());
    }
}
