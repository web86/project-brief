<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Models\ProjectClient;
use App\Models\Task;
use App\Models\User;
use App\Services\DocxProjectExporter;
use App\Services\NotificationEvents;
use App\Services\ProjectExportData;
use App\Services\ProjectNotificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Milestone43Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_discussions_reuse_comments_preserve_history_and_read_only_selected_messages_per_user(): void
    {
        $task = Task::factory()->create(['developer_notes' => 'PRIVATE_TECHNICAL_NOTE', 'price' => 12345]);
        $owner = $this->ownerFor($task->project);
        $other = $this->ownerFor($task->project);
        $client = $task->project->clients()->first();
        $first = $task->comments()->create(['text' => 'Old comment', 'author_type' => 'client', 'project_client_id' => $client->id]);
        $second = $task->comments()->create(['text' => 'New comment', 'author_type' => 'admin', 'author_user_id' => $other->id]);
        $task->comments()->create(['text' => 'Own message', 'author_type' => 'admin', 'author_user_id' => $owner->id]);
        $root = '/api/admin/projects/'.$task->project->uuid.'/discussions';
        $this->actingAs($owner)->getJson($root)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('unreadCount', 2)
            ->assertJsonPath('data.0.unread', false)->assertDontSee('PRIVATE_TECHNICAL_NOTE')->assertDontSee('12345');
        $this->assertDatabaseCount('comment_reads', 0);
        $this->postJson($root.'/read', ['ids' => [$first->uuid]])->assertOk()->assertJsonPath('unreadCount', 1);
        $this->getJson($root.'?filter=unread')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->uuid)->assertJsonPath('data.0.authorName', $other->name);
        $this->actingAs($other)->getJson($root)->assertJsonPath('unreadCount', 2);
        $this->actingAs($owner)->postJson($root.'/read', ['all' => true])->assertOk()->assertJsonPath('unreadCount', 0);
        $task->comments()->create(['text' => 'After mark all', 'author_type' => 'client']);
        $this->getJson($root)->assertJsonPath('unreadCount', 1);
        $this->postJson($root, ['text' => 'General project message'])->assertCreated()->assertJsonPath('data.task', null);
        $this->getJson($root.'?kind=general')->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'General project message')->assertJsonPath('data.0.unread', false);
        $this->assertDatabaseHas('comments', ['id' => $first->id, 'task_id' => $task->id, 'text' => 'Old comment']);
        $this->getJson('/api/admin/projects')->assertJsonPath('data.0.unreadCommentCount', 1);
    }

    public function test_discussion_cursor_pagination_is_stable_with_new_comments_and_has_no_duplicates(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($this->ownerFor($task->project));
        for ($i = 0; $i < 31; $i++) {
            $task->comments()->create(['text' => 'Comment '.$i, 'author_type' => 'client']);
        }
        $root = '/api/admin/projects/'.$task->project->uuid.'/discussions';
        $first = $this->getJson($root)->assertOk()->assertJsonCount(25, 'data');
        $task->comments()->create(['text' => 'Concurrent new comment', 'author_type' => 'client']);
        $last = $this->getJson($root.'?cursor='.urlencode($first->json('nextCursor')))->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('nextCursor', null);
        $this->assertCount(31, array_unique([...array_column($first->json('data'), 'id'), ...array_column($last->json('data'), 'id')]));
        $this->assertDatabaseCount('comment_reads', 0);
        $this->getJson($root.'?cursor=corrupt')->assertUnprocessable();
        $newest = $task->comments()->create(['text' => 'Older date, higher ID', 'author_type' => 'client', 'created_at' => now()->subDay()]);
        $newest->forceFill(['created_at' => now()->subDay()])->save();
        $this->assertNotSame($newest->uuid, $this->getJson($root)->json('data.0.id'));
    }

    public function test_discussions_enforce_project_membership_for_feeds_writes_and_read_markers(): void
    {
        $task = Task::factory()->create();
        $foreign = Task::factory()->create();
        $comment = $foreign->comments()->create(['text' => 'FOREIGN_MESSAGE', 'author_type' => 'client']);
        $root = '/api/admin/projects/'.$task->project->uuid.'/discussions';
        $this->actingAs($this->ownerFor($task->project));
        $this->getJson($root)->assertOk()->assertDontSee('FOREIGN_MESSAGE');
        $this->postJson($root.'/read', ['ids' => [$comment->uuid]])->assertNotFound();
        $this->postJson($root.'/read', ['lastSeenId' => PHP_INT_MAX])->assertUnprocessable();
        $this->postJson($root, ['text' => 'Message', 'author_user_id' => 999])->assertUnprocessable();
        foreach ([User::factory()->owner()->create(), User::factory()->memberOf($task->project->workspace)->create()] as $denied) {
            $this->actingAs($denied)->withSession(['workspace_id' => $denied->workspaceMembers()->first()->workspace_id]);
            $this->getJson($root)->assertNotFound();
            $this->postJson($root, ['text' => 'Denied'])->assertNotFound();
            $this->postJson($root.'/read', ['all' => true])->assertNotFound();
        }
        $this->assertDatabaseCount('comment_reads', 0);
        $this->assertDatabaseCount('comments', 1);
        $this->app['auth']->forgetGuards();
        $this->withSession(['workspace_id' => null]);
        auth()->logout();
        $this->getJson($root)->assertUnauthorized();
        $this->postJson($root.'/read', ['all' => true])->assertUnauthorized();
    }

    public function test_existing_project_read_markers_preserve_seen_client_comments_during_upgrade(): void
    {
        $task = Task::factory()->create();
        $owner = $this->ownerFor($task->project);
        $other = $this->ownerFor($task->project);
        $seen = $task->comments()->create(['author_type' => 'client', 'text' => 'Previously read']);
        $history = $task->history()->create(['actor_type' => 'client', 'event_type' => 'comment', 'meta' => ['commentId' => $seen->id]]);
        DB::table('project_reads')->insert(['project_id' => $task->project_id, 'user_id' => $owner->id, 'last_seen_history_id' => $history->id]);
        $unseen = $task->comments()->create(['author_type' => 'client', 'text' => 'Still unread']);
        $task->history()->create(['actor_type' => 'client', 'event_type' => 'comment', 'meta' => ['commentId' => $unseen->id]]);
        $migration = require database_path('migrations/2026_10_20_092216_add_discussions_and_user_locale.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('comment_reads', ['user_id' => $owner->id, 'comment_id' => $seen->id]);
        $this->assertDatabaseMissing('comment_reads', ['user_id' => $owner->id, 'comment_id' => $unseen->id]);
        $this->assertDatabaseMissing('comment_reads', ['user_id' => $other->id, 'comment_id' => $seen->id]);
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_admin_locale_is_persisted_only_for_current_user_and_validated(): void
    {
        $user = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        $this->actingAs($user)->patchJson('/api/admin/me/locale', ['locale' => 'en'])->assertOk()->assertJsonPath('preferredLocale', 'en');
        $this->getJson('/api/admin/me')->assertJsonPath('user.preferredLocale', 'en');
        $this->assertNull($other->fresh()->preferred_locale);
        foreach ([['locale' => 'de'], ['locale' => 'ru', 'user_id' => $other->id]] as $invalid) {
            $this->patchJson('/api/admin/me/locale', $invalid)->assertUnprocessable();
        }
        $this->assertSame('en', $user->fresh()->preferred_locale);
    }

    public function test_every_email_event_localizes_each_recipient_individually_at_background_delivery_time(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'sender@example.test']);
        $task = Task::factory()->create();
        $project = $task->project;
        $ru = $this->ownerFor($project, ['preferred_locale' => 'ru']);
        $en = $this->ownerFor($project, ['preferred_locale' => 'ru']);
        $project->clients()->delete();
        foreach (['ru', 'en'] as $locale) {
            $client = ProjectClient::factory()->for($project)->create(['preferred_locale' => $locale]);
            $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $locale)]);
        }
        foreach (NotificationEvents::CODES as $index => $code) {
            Mail::fake();
            $actor = str_starts_with($code, 'client.') ? 'client' : 'admin';
            $type = match ($code) {
                'client.task_created' => 'created', 'client.task_approved' => 'approved',
                'client.comment_created', 'admin.comment_created' => 'comment', default => 'status',
            };
            $comment = $task->comments()->create(['text' => 'Shared bilingual message', 'author_type' => $actor]);
            $history = $task->history()->create(['actor_type' => $actor, 'event_type' => $type, 'old_value' => 'new',
                'new_value' => match ($code) {
                    'admin.task_done' => 'done', 'admin.task_review' => 'review', default => 'clarification'
                },
                'meta' => ['commentId' => $comment->id]]);
            $event = new ProjectNotificationRequested($history->id, $code, $task->id, $actor, null, 'Actor');
            // Preferences may change between event creation and terminating/queued delivery.
            $en->update(['preferred_locale' => 'en']);
            app()->setLocale($index % 2 ? 'ru' : 'en');
            app(ProjectNotificationService::class)->notify(unserialize(serialize($event)));
            Mail::assertSent(ProjectActivityMail::class, 2);
            foreach (['ru', 'en'] as $locale) {
                Mail::assertSent(ProjectActivityMail::class, function ($mail) use ($locale, $code): bool {
                    $this->assertSame($mail->payload['subject'], $mail->envelope()->subject);
                    if ($mail->payload['locale'] !== $locale) {
                        return false;
                    }
                    $this->assertSame(trans('notifications.events.'.$code, [], $locale), $mail->payload['heading']);
                    foreach (['open', 'access', 'project_label', 'task_label'] as $key) {
                        $this->assertStringContainsString($mail->payload[$key], html_entity_decode(strip_tags($mail->render()), ENT_QUOTES, 'UTF-8'));
                    }
                    $this->assertStringContainsString($mail->payload['next'], view('mail.project-activity-text', ['payload' => $mail->payload])->render());

                    return true;
                });
            }
        }
    }

    public function test_notes_are_opt_in_nonempty_scoped_and_shared_by_pdf_and_docx(): void
    {
        $task = Task::factory()->create(['status' => 'done', 'developer_notes' => "Tech <script>unsafe</script>\nSecond line"]);
        $this->actingAs($this->ownerFor($task->project));
        Task::factory()->for($task->project)->create(['status' => 'new', 'developer_notes' => 'EXCLUDED_NOT_DONE']);
        Task::factory()->for($task->project)->create(['status' => 'done', 'developer_notes' => '   ']);
        $builder = app(ProjectExportData::class);
        $this->assertStringNotContainsString('unsafe', json_encode($builder->build($task->project, 'done', 'en')));
        foreach (['ru' => 'Технические заметки', 'en' => 'Technical notes'] as $locale => $label) {
            $document = $builder->build($task->project, 'done', $locale, ['developerNotes' => true]);
            $json = json_encode($document, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString($label, $json);
            $this->assertStringNotContainsString('EXCLUDED_NOT_DONE', $json);
            $html = view('exports.specification', compact('document'))->render();
            $this->assertStringContainsString('Tech &lt;script&gt;unsafe&lt;/script&gt;', $html);
            $this->assertSame(1, substr_count($html, $label));
            $this->assertStringNotContainsString('<script>', $html);
            $bytes = app(DocxProjectExporter::class)->render($document);
            $file = tempnam(sys_get_temp_dir(), 'notes-test-');
            try {
                file_put_contents($file, $bytes);
                $zip = new \ZipArchive;
                $zip->open($file);
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                $this->assertSame(1, substr_count($xml, $label));
                $this->assertStringContainsString('Tech &lt;script&gt;unsafe&lt;/script&gt;', $xml);
            } finally {
                unlink($file);
            }
            foreach (['pdf', 'docx'] as $format) {
                $this->postJson('/api/admin/projects/'.$task->project->uuid.'/export', ['format' => $format, 'scope' => 'done', 'locale' => $locale, 'fields' => ['developerNotes' => true]])->assertOk();
            }
        }
    }
}
