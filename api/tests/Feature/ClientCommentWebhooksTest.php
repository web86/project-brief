<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectNotificationSetting;
use App\Models\Task;
use App\Services\NotificationEvents;
use App\Services\WebhookDestination;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientCommentWebhooksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(string $mode = 'all', bool $optIn = true): array
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->mock(WebhookDestination::class)->shouldReceive('resolve')->andReturn(['host' => 'crm.example.com', 'ip' => '93.184.216.34']);
        $task = Task::factory()->create();
        $project = $task->project;
        $owner = $this->ownerFor($project);
        $response = $this->actingAs($owner)->withSession(['workspace_id' => $project->workspace_id])->postJson('/api/admin/integrations/webhooks', ['name' => 'CRM', 'url' => 'https://crm.example.com/events', 'events' => $optIn ? ['task.client_comment_created', 'project.client_comment_created'] : ['task.approved'], 'projectFilter' => $mode, 'projectIds' => $mode === 'selected' ? [$project->uuid] : []])->assertCreated();
        ProjectNotificationSetting::where('project_id', $project->id)->first()->update(['event_rules' => array_fill_keys(NotificationEvents::CODES, ['enabled' => false])]);
        $project->clients()->first()->forceFill(['notification_preferences' => ['email' => false, 'push' => false, 'telegram' => false]])->save();
        $this->client($project);

        return [$task, $owner, $response->json('secret')];
    }

    private function client(Project $project): void
    {
        $this->app['auth']->forgetGuards();
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', Str::random(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    public function test_both_client_comment_types_are_opt_in_safe_signed_and_idempotent_in_both_filter_modes(): void
    {
        foreach (['all', 'selected'] as $mode) {
            [$task, $owner, $secret] = $this->fixture($mode);
            $previousEvents = DB::table('webhook_events')->count();
            foreach (['/api/client/tasks/'.$task->uuid.'/comments', '/api/client/project/'.$task->project->uuid.'/discussions'] as $index => $url) {
                $key = Str::uuid()->toString();
                for ($i = 0; $i < 2; $i++) {
                    $this->post($url, ['text' => 'SECRET client text private@example.test', 'submissionId' => $key, 'attachments' => [UploadedFile::fake()->createWithContent('PRIVATE_FILE.txt', 'PRIVATE_BYTES')]], ['Accept' => 'application/json'])->assertSuccessful();
                }
                $event = DB::table('webhook_events')->latest('id')->first();
                $body = json_decode($event->body, true);
                $this->assertSame($index === 0 ? 'task.client_comment_created' : 'project.client_comment_created', $event->type);
                $this->assertSame($task->project->uuid, $body['projectId']);
                $this->assertSame($index === 0 ? $task->uuid : null, $body['taskId']);
                $this->assertSame(Comment::latest('id')->first()->uuid, $body['commentId']);
                $this->assertSame(['type' => 'client', 'id' => $task->project->clients()->first()->uuid], $body['actor']);
                $this->assertSame('ui', $body['origin']);
                $this->assertEquals((object) [], json_decode($event->body)->changes);
                $this->assertSame(['version', 'id', 'type', 'occurredAt', 'workspaceId', 'projectId', 'taskId', 'commentId', 'actor', 'changes', 'origin'], array_keys($body));
                foreach (['SECRET', 'private@example.test', 'PRIVATE_FILE', 'PRIVATE_BYTES', 'attachments/', 'estimate', 'price', $task->project->clients()->first()->name] as $sensitive) {
                    $this->assertStringNotContainsString($sensitive, $event->body);
                }
                $this->postJson($url, ['text' => 'Changed', 'submissionId' => $key])->assertConflict();
            }
            $this->assertSame($previousEvents + 2, DB::table('webhook_events')->count());
            Http::fake(['*' => Http::response('', 204)]);
            app(WebhookProcessor::class)->process();
            foreach (Http::recorded() as [$request]) {
                $sent = json_decode($request->body(), true);
                if ($sent['projectId'] === $task->project->uuid) {
                    $this->assertSame('sha256='.hash_hmac('sha256', $request->header('X-ProjectBrief-Timestamp')[0].'.'.$request->body(), $secret), $request->header('X-ProjectBrief-Signature')[0]);
                }
            }
            $this->actingAs($owner)->withSession(['workspace_id' => $task->project->workspace_id]);
            $counts = $this->getJson('/api/admin/projects/'.$task->project->uuid.'/discussions?kind=general')->assertOk()->json('unreadCounts');
            $this->assertSame(1, $counts['general']);
            $this->assertSame(1, $counts['tasks']);
        }
    }

    public function test_unsubscribed_comments_and_all_admin_comments_do_not_emit_events(): void
    {
        [$task, $owner] = $this->fixture('all', false);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'No subscription'])->assertOk();
        $this->postJson('/api/client/project/'.$task->project->uuid.'/discussions', ['text' => 'No subscription'])->assertCreated();
        $this->actingAs($owner)->withSession(['workspace_id' => $task->project->workspace_id]);
        $uuid = DB::table('webhook_endpoints')->first()->uuid;
        $this->patchJson('/api/admin/integrations/webhooks/'.$uuid, ['events' => ['task.client_comment_created', 'project.client_comment_created']])->assertOk();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Admin task'])->assertOk();
        $this->postJson('/api/admin/projects/'.$task->project->uuid.'/discussions', ['text' => 'Admin general'])->assertCreated();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_comment_creation_rollback_and_attachment_failure_leave_no_outbox_event(): void
    {
        [$task] = $this->fixture();
        Http::fake();
        foreach (['/api/client/tasks/'.$task->uuid.'/comments', '/api/client/project/'.$task->project->uuid.'/discussions'] as $url) {
            DB::beginTransaction();
            $this->postJson($url, ['text' => 'Rolled back'])->assertSuccessful();
            $this->assertDatabaseCount('webhook_events', 1);
            DB::rollBack();
            $this->assertDatabaseCount('webhook_events', 0);
            $this->assertDatabaseCount('comments', 0);
        }
        Attachment::creating(fn () => throw new \RuntimeException('Simulated attachment failure'));
        try {
            foreach (['/api/client/tasks/'.$task->uuid.'/comments', '/api/client/project/'.$task->project->uuid.'/discussions'] as $url) {
                $this->post($url, ['text' => 'Fail atomically', 'submissionId' => Str::uuid()->toString(), 'attachments' => [UploadedFile::fake()->createWithContent('private.txt', 'private')]], ['Accept' => 'application/json'])->assertStatus(500);
                $this->assertDatabaseCount('webhook_events', 0);
                $this->assertDatabaseCount('comments', 0);
                $this->assertSame([], Storage::disk('local')->allFiles());
            }
        } finally {
            Attachment::flushEventListeners();
        }
        Http::assertNothingSent();
    }

    public function test_selected_filter_excludes_both_comment_types_from_other_projects_and_tenants(): void
    {
        [$task] = $this->fixture('selected');
        $same = Project::factory()->for($task->project->workspace)->create();
        $foreign = Project::factory()->create();
        foreach ([$same, $foreign] as $project) {
            $otherTask = Task::factory()->for($project)->create();
            $this->client($project);
            $this->postJson('/api/client/tasks/'.$otherTask->uuid.'/comments', ['text' => 'Excluded task'])->assertOk();
            $this->postJson('/api/client/project/'.$project->uuid.'/discussions', ['text' => 'Excluded general'])->assertCreated();
        }
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }
}
