<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectNotificationSetting;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationEvents;
use App\Services\NotificationPayload;
use App\Services\ProjectNotificationService;
use App\Services\TaskAudit;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function smtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'brief@example.test']);
    }

    private function client(Project $project, ?ProjectClient $client = null): ProjectClient
    {
        $client ??= $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', bin2hex(random_bytes(16)))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);

        return $client;
    }

    public static function clientEvents(): array
    {
        return [['create', 'client.task_created'], ['comment', 'client.comment_created'], ['approve', 'client.task_approved']];
    }

    #[DataProvider('clientEvents')]
    public function test_client_actions_deliver_once_to_primary_admin(string $action, string $code): void
    {
        $this->smtp();
        Mail::fake();
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $task = Task::factory()->create();
        $this->client($task->project);
        $endpoint = '/api/client/tasks/'.$task->uuid;
        match ($action) {
            'create' => $this->postJson('/api/client/tasks', ['title' => 'New idea', 'location' => 'Главная', 'description' => 'Client text', 'priority' => 'normal'])->assertSuccessful(),
            'comment' => $this->postJson($endpoint.'/comments', ['text' => 'Comment'])->assertOk(),
            'approve' => $this->postJson($endpoint.'/approve')->assertOk(),
        };
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($admin->email) && $mail->payload['event'] === $code && str_contains($mail->payload['url'], '/brief/task/'));
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('notification_deliveries', ['event' => $code, 'channel' => 'email', 'status' => 'sent']);
        if ($action === 'approve') {
            $this->postJson($endpoint.'/approve')->assertOk();
            Mail::assertSentCount(1);
        }
    }

    public static function adminEvents(): array
    {
        return [['clarification', 'admin.clarification_requested'], ['review', 'admin.task_review'], ['done', 'admin.task_done'], ['comment', 'admin.comment_created']];
    }

    #[DataProvider('adminEvents')]
    public function test_admin_actions_reach_active_clients_in_their_own_locale_once(string $action, string $code): void
    {
        $this->smtp();
        Mail::fake();
        $task = Task::factory()->create(['developer_notes' => 'PRIVATE-NOTES', 'price' => 987654, 'estimate_hours' => 876543]);
        $john = $task->project->clients()->first();
        $john->update(['name' => 'John', 'email' => 'john@example.test', 'preferred_locale' => 'en']);
        $this->client($task->project, $john);
        $anna = $task->project->clients()->create(['name' => 'Anna', 'email' => 'anna@example.test', 'preferred_locale' => 'ru']);
        $this->client($task->project, $anna);
        $this->actingAs(User::factory()->create());
        if ($action === 'comment') {
            $this->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Please check'])->assertOk();
        } else {
            $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => $action])->assertOk();
        }
        Mail::assertSentCount(2);
        foreach (['en' => $john, 'ru' => $anna] as $locale => $client) {
            Mail::assertSent(ProjectActivityMail::class, function ($mail) use ($locale, $client, $task, $code) {
                if (! $mail->hasTo($client->email)) {
                    return false;
                }
                $this->assertSame($locale, $mail->payload['locale']);
                $html = $mail->render();
                $this->assertStringContainsString('/project/'.$task->project->uuid.'/task/'.$task->uuid, $html);
                foreach (['PRIVATE-NOTES', '987654', '876543', '/access/', 'token_hash', 'storage/app'] as $secret) {
                    $this->assertStringNotContainsString($secret, $html);
                }

                return $mail->hasTo($client->email) && $mail->payload['event'] === $code;
            });
        }
        if ($action !== 'comment') {
            $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => $action])->assertOk();
            Mail::assertSentCount(2);
        }
    }

    public function test_recipient_channels_are_independent_and_inactive_revoked_expired_clients_are_skipped(): void
    {
        $this->smtp();
        Mail::fake();
        $task = Task::factory()->create();
        $admin = User::factory()->create();
        $clients = [];
        foreach (['no-email', 'email', 'inactive', 'revoked', 'expired'] as $name) {
            $client = $task->project->clients()->create(['name' => $name, 'email' => $name === 'no-email' ? null : $name.'@example.test', 'active' => $name !== 'inactive']);
            $task->project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $name), 'revoked_at' => $name === 'revoked' ? now() : null, 'expires_at' => $name === 'expired' ? now()->subDay() : null]);
            PushSubscription::factory()->create(['user_id' => null, 'project_client_id' => $client->id]);
            $clients[$name] = $client;
        }
        $sender = $this->mock(WebPushTransport::class);
        $sender->shouldReceive('available')->andReturn(true);
        $sender->shouldReceive('send')->twice()->withArgs(function ($subscription, $payload) use ($clients, $task) {
            $this->assertContains($subscription->project_client_id, [$clients['no-email']->id, $clients['email']->id]);
            $this->assertSame('admin.task_done', $payload['event']);
            $this->assertSame(rtrim(config('app.url'), '/').'/project/'.$task->project->uuid.'/task/'.$task->uuid, $payload['url']);
            $this->assertCount(7, $payload);

            return true;
        })->andReturn(['status' => 'sent', 'error_code' => null]);
        $this->actingAs($admin)->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'done'])->assertOk();
        Mail::assertSentCount(1);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo('email@example.test'));
        $this->assertDatabaseCount('notification_deliveries', 3);
    }

    public function test_settings_disable_each_channel_and_deduplicate_same_domain_event(): void
    {
        $this->smtp();
        Mail::fake();
        $task = Task::factory()->create();
        $admin = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $admin->id]);
        $events = NotificationEvents::defaults();
        $events['client.task_created'] = ['email' => false, 'push' => false];
        ProjectNotificationSetting::where('project_id', $task->project_id)->update(['events' => $events]);
        $event = new ProjectNotificationRequested(100, 'client.task_created', $task->id, 'client', null, 'John');
        $service = app(ProjectNotificationService::class);
        $service->notify($event);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $events['client.task_created']['email'] = true;
        ProjectNotificationSetting::where('project_id', $task->project_id)->update(['events' => $events]);
        $service->notify($event);
        $service->notify($event);
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_self_identity_and_shared_actor_email_are_suppressed(): void
    {
        $this->smtp();
        Mail::fake();
        $task = Task::factory()->create();
        $client = $this->client($task->project);
        $client->update(['email' => 'shared@example.test']);
        $admin = User::factory()->create(['email' => 'shared@example.test']);
        $service = app(ProjectNotificationService::class);
        $service->notify(new ProjectNotificationRequested(101, 'client.task_created', $task->id, 'client', $client->id, $client->name));
        $service->notify(new ProjectNotificationRequested(102, 'admin.task_done', $task->id, 'client', $client->id, $client->name));
        Mail::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_smtp_failure_does_not_rollback_comment_or_prevent_push(): void
    {
        $this->smtp();
        $task = Task::factory()->create();
        $this->client($task->project);
        $admin = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $admin->id]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('secret provider failure'));
        $sender = $this->mock(WebPushTransport::class);
        $sender->shouldReceive('available')->andReturn(true);
        $sender->shouldReceive('send')->once()->andReturn(['status' => 'sent', 'error_code' => null]);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Keep this comment'])->assertOk();
        $this->assertDatabaseHas('comments', ['task_id' => $task->id, 'text' => 'Keep this comment']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'email', 'status' => 'failed', 'error_code' => 'email_delivery_failed']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'push', 'status' => 'sent']);
    }

    public function test_unconfigured_channels_leave_business_action_successful(): void
    {
        Mail::fake();
        config(['mail.default' => 'log', 'project_notifications.vapid.private_key' => null]);
        $task = Task::factory()->create();
        $this->client($task->project);
        $admin = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $admin->id]);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Saved'])->assertOk();
        Mail::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'email', 'status' => 'skipped', 'error_code' => 'smtp_unconfigured']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'push', 'status' => 'skipped', 'error_code' => 'push_unconfigured']);
    }

    public function test_drafts_internal_changes_and_rolled_back_audits_do_not_dispatch(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$task->uuid, ['title' => 'Edited draft'])->assertOk();
        $this->actingAs(User::factory()->create())->patchJson('/api/admin/tasks/'.$task->uuid, ['estimateHours' => 3, 'price' => 50, 'developerNotes' => 'Notes'])->assertOk();
        Event::assertNotDispatched(ProjectNotificationRequested::class);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_project_settings_validate_persist_defaults_and_never_expose_push_secrets(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->create());
        $path = '/api/admin/projects/'.$project->uuid.'/notifications';
        $response = $this->getJson($path)->assertOk();
        $this->assertTrue($response->json()['events']['client.task_created']['email']);
        $events = NotificationEvents::defaults();
        $events['admin.task_done']['email'] = false;
        $this->patchJson($path, ['notificationEmail' => 'owner@example.test', 'events' => $events])->assertOk();
        $settings = ProjectNotificationSetting::where('project_id', $project->id)->first();
        $this->assertSame(false, $settings->preferences()['admin.task_done']['email']);
        $this->assertSame('owner@example.test', $settings->notification_email);
        $events['bogus'] = ['email' => true, 'push' => true];
        $this->patchJson($path, ['events' => $events])->assertUnprocessable();
        $this->patchJson($path, ['notificationEmail' => 'not-mail', 'events' => NotificationEvents::defaults()])->assertUnprocessable();
    }

    public function test_mail_escapes_input_and_plain_text_is_available(): void
    {
        $task = Task::factory()->create(['title' => '<script>bad</script>', 'developer_notes' => 'INTERNAL']);
        $payload = NotificationPayload::make('admin.task_done', $task, '<img onerror=bad>', 'en', false, '200');
        $mail = new ProjectActivityMail($payload);
        $this->assertStringContainsString('&lt;script&gt;', $mail->render());
        $this->assertStringNotContainsString('<script>bad</script>', $mail->render());
        $this->assertStringNotContainsString('<img onerror=bad>', $mail->render());
        $this->assertStringContainsString('Task completed', view('mail.project-activity-text', ['payload' => $payload])->render());
    }

    public function test_admin_test_actions_never_send_to_clients_and_report_configuration_errors(): void
    {
        $this->smtp();
        Mail::fake();
        $project = Project::factory()->create();
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->actingAs($admin);
        $path = '/api/admin/projects/'.$project->uuid.'/notifications/test';
        $this->postJson($path, ['channel' => 'email'])->assertOk()->assertJsonPath('status', 'sent');
        Mail::assertSentCount(1);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($admin->email));
        $this->postJson($path, ['channel' => 'push'])->assertUnprocessable()->assertJsonPath('code', 'device_not_subscribed');
        config(['mail.default' => 'log', 'project_notifications.vapid.private_key' => null]);
        $this->postJson($path, ['channel' => 'email'])->assertUnprocessable()->assertJsonPath('code', 'smtp_unconfigured');
    }

    public function test_rolled_back_changes_do_not_emit_after_commit_notifications(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $task = Task::factory()->create();
        $admin = User::factory()->create();
        $request = Request::create('/api/admin/tasks/'.$task->uuid, 'PATCH');
        $request->setUserResolver(fn () => $admin);
        DB::beginTransaction();
        TaskAudit::record($request, $task, 'status', 'Changed', 'new', 'done');
        DB::rollBack();
        Event::assertNotDispatched(ProjectNotificationRequested::class);
        $this->assertDatabaseCount('task_history', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_admin_locale_override_and_null_client_locale_fallback_are_localized(): void
    {
        $this->smtp();
        Mail::fake();
        config(['project_notifications.admin_locale' => 'en']);
        $task = Task::factory()->create();
        $client = $this->client($task->project);
        $client->update(['email' => 'client@example.test', 'preferred_locale' => null]);
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Hello'])->assertOk();
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($admin->email) && $mail->payload['locale'] === 'en' && str_contains($mail->payload['subject'], 'New client comment'));
        $this->actingAs($admin)->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'done'])->assertOk();
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($client->email) && $mail->payload['locale'] === 'en' && str_contains($mail->payload['subject'], 'Task completed'));
        Mail::assertSentCount(2);
    }
}
