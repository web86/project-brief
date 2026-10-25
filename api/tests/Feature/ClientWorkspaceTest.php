<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Services\ProjectExportData;
use App\Services\ProjectNotificationService;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use ZipArchive;

class ClientWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function enter(Project $project, ?ProjectClient $client = null): ProjectClient
    {
        $client ??= $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);

        return $client;
    }

    public function test_client_reads_are_personal_and_never_collide_with_admin_ids(): void
    {
        $task = Task::factory()->create(['price' => 78239, 'estimate_hours' => 493, 'developer_notes' => 'PRIVATE_DATA']);
        $project = $task->project;
        $owner = $this->ownerFor($project);
        $first = $this->enter($project);
        $second = ProjectClient::factory()->for($project)->create();
        $comment = $task->comments()->create(['author_type' => 'admin', 'author_user_id' => $owner->id, 'text' => 'Public task comment']);
        $task->comments()->create(['author_type' => 'client', 'project_client_id' => $first->id, 'text' => 'My message']);
        $root = '/api/client/project/'.$project->uuid.'/discussions';
        $this->getJson($root)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('unreadCount', 1)->assertDontSee('PRIVATE_DATA')->assertDontSee('78239')->assertDontSee('493');
        $this->getJson('/api/client/project/'.$project->uuid)->assertJsonPath('data.unreadCommentCount', 1);
        $this->postJson($root.'/read', ['ids' => [$comment->uuid]])->assertOk()->assertJsonPath('unreadCount', 0);
        $this->assertDatabaseHas('client_comment_reads', ['project_client_id' => $first->id, 'comment_id' => $comment->id]);
        $this->assertDatabaseCount('comment_reads', 0);
        $this->enter($project, $second);
        $this->getJson($root)->assertOk()->assertJsonPath('unreadCount', 2);
        $this->postJson($root.'/read', ['all' => true])->assertOk()->assertJsonPath('unreadCount', 0);
        $this->actingAs($owner)->getJson('/api/admin/projects/'.$project->uuid.'/discussions')->assertOk()->assertJsonPath('unreadCount', 1);
        $this->assertDatabaseCount('comment_reads', 0);
    }

    public function test_client_can_write_general_comments_and_page_without_marking_unseen_messages(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $project = Project::factory()->create();
        $client = $this->enter($project);
        $root = '/api/client/project/'.$project->uuid.'/discussions';
        $this->postJson($root, ['text' => 'General client message'])->assertCreated()->assertJsonPath('data.task', null)->assertJsonPath('data.authorName', $client->name);
        Event::assertDispatched(ProjectNotificationRequested::class, fn ($event) => $event->event === 'client.project_comment_created' && $event->projectCommentId !== null && $event->taskId === null);
        for ($i = 0; $i < 30; $i++) {
            Comment::create(['project_id' => $project->id, 'author_type' => 'admin', 'text' => 'Public '.$i]);
        }
        $first = $this->getJson($root.'?kind=general&filter=unread')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('unreadCount', 30);
        Comment::create(['project_id' => $project->id, 'author_type' => 'admin', 'text' => 'After cursor']);
        $last = $this->getJson($root.'?kind=general&filter=unread&cursor='.urlencode($first->json('nextCursor')))->assertOk()->assertJsonCount(5, 'data');
        $this->assertCount(30, array_unique([...array_column($first->json('data'), 'id'), ...array_column($last->json('data'), 'id')]));
        $this->assertDatabaseCount('client_comment_reads', 0);
        $this->getJson($root.'?cursor=corrupt')->assertUnprocessable();
    }

    public function test_all_client_discussion_operations_and_exports_deny_cross_project_access_and_revocation(): void
    {
        $task = Task::factory()->create();
        $foreign = Task::factory()->create();
        $comment = $foreign->comments()->create(['author_type' => 'admin', 'text' => 'FOREIGN_MESSAGE']);
        $this->enter($task->project);
        $own = '/api/client/project/'.$task->project->uuid;
        $other = '/api/client/project/'.$foreign->project->uuid;
        $this->getJson($other.'/discussions')->assertNotFound();
        $this->postJson($other.'/discussions', ['text' => 'Denied'])->assertNotFound();
        $this->postJson($other.'/discussions/read', ['all' => true])->assertNotFound();
        $this->postJson($own.'/discussions/read', ['ids' => [$comment->uuid]])->assertNotFound();
        $this->postJson($own.'/discussions', ['text' => 'Spoof', 'project_client_id' => 999])->assertUnprocessable();
        $this->postJson($other.'/export', ['format' => 'docx', 'scope' => 'all', 'locale' => 'en'])->assertNotFound();
        $task->project->accessTokens()->update(['revoked_at' => now()]);
        $this->getJson($own.'/discussions')->assertUnauthorized();
        $this->postJson($own.'/discussions', ['text' => 'Denied'])->assertUnauthorized();
        $this->postJson($own.'/export', ['format' => 'pdf', 'scope' => 'all', 'locale' => 'en'])->assertUnauthorized();
    }

    public function test_personal_preferences_cannot_modify_another_client_or_project_matrix(): void
    {
        $project = Project::factory()->create();
        $client = $this->enter($project);
        $other = ProjectClient::factory()->for($project)->create();
        $preferences = ['email' => false, 'push' => true, 'telegram' => false];
        $this->getJson('/api/client/notifications')->assertOk()->assertJsonPath('email', $client->email);
        $this->patchJson('/api/client/notifications', ['preferences' => $preferences])->assertOk()->assertJsonPath('preferences', $preferences);
        $this->assertSame($preferences, $client->fresh()->notificationPreferences());
        $this->assertTrue($other->fresh()->notificationPreferences()['email']);
        foreach ([['preferences' => $preferences, 'clientId' => $other->uuid], ['preferences' => [...$preferences, 'price' => true]], ['preferences' => ['email' => true]]] as $body) {
            $this->patchJson('/api/client/notifications', $body)->assertUnprocessable();
        }
        $this->patchJson('/api/admin/projects/'.$project->uuid.'/notifications', ['events' => []])->assertUnauthorized();
        $this->assertNull(DB::table('project_notification_settings')->where('project_id', $project->id)->value('events'));
    }

    public function test_personal_preferences_gate_existing_email_and_push_without_changing_project_events(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'sender@example.test']);
        Mail::fake();
        $task = Task::factory()->create();
        $client = $this->enter($task->project);
        $client->update(['email' => 'client@example.test', 'notification_preferences' => ['email' => false, 'push' => false, 'telegram' => false]]);
        PushSubscription::factory()->create(['user_id' => null, 'project_client_id' => $client->id]);
        $this->mock(WebPushTransport::class)->shouldNotReceive('send');
        $service = app(ProjectNotificationService::class);
        $service->notify(new ProjectNotificationRequested(999, 'admin.task_done', $task->id, 'admin', null, 'Developer'));
        Mail::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertTrue($service->settings($task->project)->preferences()['admin.task_done']['email']);
        $client->update(['notification_preferences' => ['email' => true, 'push' => false, 'telegram' => false]]);
        $service->notify(new ProjectNotificationRequested(1000, 'admin.task_done', $task->id, 'admin', null, 'Developer'));
        Mail::assertSent(ProjectActivityMail::class, 1);
    }

    public function test_client_export_allowlist_omits_private_columns_from_sql_builder_pdf_and_docx_even_with_forged_fields(): void
    {
        $task = Task::factory()->create(['title' => 'Visible task', 'description' => 'PUBLIC_DESCRIPTION', 'expected_result' => 'PUBLIC_RESULT', 'price' => 934567.89, 'estimate_hours' => 76543.21, 'developer_notes' => 'PRIVATE_TECHNICAL_NOTES']);
        $this->enter($task->project);
        $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), true);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = str_replace('`', '"', $query->sql);
            if (str_contains($sql, 'from "tasks"') && str_starts_with($sql, 'select')) {
                $queries[] = $sql;
            }
        });
        $document = app(ProjectExportData::class)->build($task->project, 'all', 'en', $fields, [], true);
        $encoded = json_encode($document);
        foreach (['934567', '76543', 'PRIVATE_TECHNICAL_NOTES', 'estimateHours', 'developerNotes'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        foreach (['pdf', 'docx'] as $format) {
            $response = $this->postJson('/api/client/project/'.$task->project->uuid.'/export', ['format' => $format, 'scope' => 'all', 'locale' => 'en', 'fields' => $fields, 'documentTitle' => 'Client specification', 'pageBreakBySection' => true])->assertOk();
            $bytes = $response->streamedContent();
            if ($format === 'pdf') {
                $this->assertStringStartsWith('%PDF-', $bytes);
            } else {
                $file = tempnam(sys_get_temp_dir(), 'client-export');
                try {
                    file_put_contents($file, $bytes);
                    $zip = new ZipArchive;
                    $this->assertTrue($zip->open($file));
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();
                    $this->assertStringContainsString('PUBLIC_DESCRIPTION', $xml);
                    $this->assertStringNotContainsString('PRIVATE_TECHNICAL_NOTES', $xml);
                    $this->assertStringNotContainsString('934', $xml);
                    $this->assertStringNotContainsString('76543', $xml);
                } finally {
                    unlink($file);
                }
            }
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('select *', $sql);
            foreach (['price', 'estimate_hours', 'developer_notes'] as $private) {
                $this->assertStringNotContainsString('"'.$private.'"', $sql);
            }
        }
    }

    public function test_client_export_scopes_use_shared_builder_and_unknown_options_are_rejected(): void
    {
        $task = Task::factory()->create(['title' => 'Ready task', 'status' => 'done', 'client_approved' => false]);
        Task::factory()->for($task->project)->create(['title' => 'Approved task', 'status' => 'new', 'client_approved' => true]);
        $this->enter($task->project);
        foreach (['done' => 'Ready task', 'approved' => 'Approved task'] as $scope => $title) {
            $document = app(ProjectExportData::class)->build($task->project, $scope, 'ru', [], [], true);
            $this->assertSame(1, $document['totals']['taskCount']);
            $this->assertSame($title, $document['sections'][0]['tasks'][0]['title']);
        }
        $this->postJson('/api/client/project/'.$task->project->uuid.'/export', ['format' => 'docx', 'scope' => 'all', 'locale' => 'en', 'price' => true])->assertUnprocessable();
    }
}
