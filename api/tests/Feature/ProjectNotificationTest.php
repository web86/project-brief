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

    private function assertContent(ProjectActivityMail $mail, string $event, string $locale, string $project, string $title, ?string $message): void
    {
        $headings = [
            'ru' => ['client.task_created' => 'Новая идея клиента', 'client.comment_created' => 'Новый комментарий клиента', 'client.task_approved' => 'Клиент согласовал задачу', 'admin.clarification_requested' => 'Нужна ваша информация', 'admin.comment_created' => 'Комментарий разработчика', 'admin.task_review' => 'Задача готова к проверке', 'admin.task_done' => 'Задача выполнена'],
            'en' => ['client.task_created' => 'New client idea', 'client.comment_created' => 'New client comment', 'client.task_approved' => 'Task approved by client', 'admin.clarification_requested' => 'Clarification needed', 'admin.comment_created' => 'New developer comment', 'admin.task_review' => 'Task ready for review', 'admin.task_done' => 'Task completed'],
        ];
        $actions = [
            'ru' => ['client.task_created' => 'ознакомиться с идеей', 'client.comment_created' => 'ответить клиенту', 'client.task_approved' => 'согласованный объём работ', 'admin.clarification_requested' => 'ответьте разработчику', 'admin.comment_created' => 'ответить разработчику', 'admin.task_review' => 'оставьте комментарий, если нужны изменения', 'admin.task_done' => 'посмотреть выполненную работу'],
            'en' => ['client.task_created' => 'review the idea', 'client.comment_created' => 'reply to the client', 'client.task_approved' => 'view the approved scope', 'admin.clarification_requested' => 'answer the developer', 'admin.comment_created' => 'reply to the developer', 'admin.task_review' => 'leave a comment if changes are needed', 'admin.task_done' => 'view the completed work'],
        ];
        $heading = $headings[$locale][$event];
        $this->assertSame($locale, $mail->payload['locale']);
        $this->assertSame('[ProjectBrief] '.$heading.' — '.NotificationPayload::excerpt($project, 80), $mail->envelope()->subject);
        $this->assertSame($heading, $mail->payload['heading']);
        $this->assertSame($message, $mail->payload['message']);
        $this->assertNotEmpty($mail->payload['number']);
        foreach ([$mail->render(), view('mail.project-activity-text', ['payload' => $mail->payload])->render()] as $rendered) {
            $plain = html_entity_decode(strip_tags($rendered), ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString($heading, $plain);
            $this->assertStringContainsString($project, $plain);
            $this->assertStringContainsString($title, $plain);
            $this->assertStringContainsString($mail->payload['explanation'], $plain);
            $this->assertStringContainsString($actions[$locale][$event], $plain);
            $this->assertStringContainsString($mail->payload['access'], $plain);
            $this->assertStringContainsString($mail->payload['url'], $rendered);
        }
        if ($message !== null) {
            $this->assertStringContainsString(e($message), $mail->render());
            $this->assertStringContainsString($message, view('mail.project-activity-text', ['payload' => $mail->payload])->render());
            $this->assertStringNotContainsString('<img onerror=bad>', $mail->render());
            $this->assertStringNotContainsString('<script>alert(1)</script>', $mail->render());
        }
    }

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
        $cases = [];
        foreach (['ru', 'en'] as $locale) {
            foreach (['create' => 'client.task_created', 'comment' => 'client.comment_created', 'approve' => 'client.task_approved'] as $action => $code) {
                $cases[] = [$action, $code, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('clientEvents')]
    public function test_client_actions_deliver_once_to_primary_admin(string $action, string $code, string $locale): void
    {
        config(['project_notifications.admin_locale' => $locale]);
        $this->smtp();
        Mail::fake();
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $task = Task::factory()->create();
        $client = $this->client($task->project);
        $client->update(['name' => 'Иван']);
        $endpoint = '/api/client/tasks/'.$task->uuid;
        match ($action) {
            'create' => $this->postJson('/api/client/tasks', ['title' => 'New idea', 'location' => 'Главная', 'description' => 'Нужно добавить выбор доставки <script>alert(1)</script>', 'priority' => 'normal'])->assertSuccessful(),
            'comment' => $this->postJson($endpoint.'/comments', ['text' => 'На мобильном кнопка съезжает <img onerror=bad>'])->assertOk(),
            'approve' => $this->postJson($endpoint.'/approve')->assertOk(),
        };
        Mail::assertSent(ProjectActivityMail::class, function ($mail) use ($admin, $code, $locale, $task, $action) {
            if (! $mail->hasTo($admin->email)) {
                return false;
            }
            $this->assertContent($mail, $code, $locale, $task->project->title, $action === 'create' ? 'New idea' : $task->title,
                match ($action) {
                    'create' => 'Нужно добавить выбор доставки <script>alert(1)</script>',
                    'comment' => 'На мобильном кнопка съезжает <img onerror=bad>',
                    default => null,
                });
            $this->assertStringContainsString('Иван', $mail->payload['explanation']);

            return $mail->payload['event'] === $code && str_contains($mail->payload['url'], '/brief/task/');
        });
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
            $this->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Я изменил форму. Проверьте, пожалуйста. <img onerror=bad>'])->assertOk();
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
                foreach (['developer_notes', 'developerNotes', 'estimate_hours', 'estimateHours', 'price', 'token_hash', 'storage_path'] as $privateKey) {
                    $this->assertArrayNotHasKey($privateKey, $mail->payload);
                }
                $this->assertContent($mail, $code, $locale, $task->project->title, $task->title,
                    $code === 'admin.comment_created' ? 'Я изменил форму. Проверьте, пожалуйста. <img onerror=bad>' : null);
                $html = $mail->render();
                $plain = view('mail.project-activity-text', ['payload' => $mail->payload])->render();
                $this->assertStringContainsString('/project/'.$task->project->uuid.'/task/'.$task->uuid, $html);
                foreach (['PRIVATE-NOTES', '987654', '876543', '/access/', 'token_hash', 'storage/app', ...$task->project->accessTokens()->pluck('token_hash')->all()] as $secret) {
                    $this->assertStringNotContainsString($secret, $html);
                    $this->assertStringNotContainsString($secret, $plain);
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

    public function test_comment_context_is_bound_to_exact_audit_comment_and_push_is_concise(): void
    {
        $task = Task::factory()->create(['developer_notes' => 'PRIVATE']);
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Первый вопрос <b>текст</b>'])->assertOk();
        $first = $task->history()->where('event_type', 'comment')->first();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Другой комментарий'])->assertOk();
        $payload = NotificationPayload::make('admin.comment_created', $task, $admin->name, 'en', false, (string) $first->id);
        $this->assertSame('Первый вопрос <b>текст</b>', $payload['message']);
        $this->assertStringContainsString('Первый вопрос', NotificationPayload::push($payload)['body']);
        $this->assertStringNotContainsString('PRIVATE', json_encode(NotificationPayload::push($payload)));
        $this->assertCount(7, NotificationPayload::push($payload));
        $other = Task::factory()->create();
        $wrong = NotificationPayload::make('admin.comment_created', $other, $admin->name, 'en', false, (string) $first->id);
        $this->assertNull($wrong['message']);
        $first->update(['meta' => ['commentId' => $other->comments()->create(['text' => 'Unrelated', 'author_type' => 'admin', 'author_user_id' => $admin->id])->id]]);
        $this->assertNull(NotificationPayload::make('admin.comment_created', $task, $admin->name, 'en', false, (string) $first->id)['message']);
    }

    public function test_clarification_does_not_guess_an_unrelated_old_developer_comment(): void
    {
        $task = Task::factory()->create();
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'An unrelated old message'])->assertOk();
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'clarification'])->assertOk();
        $history = $task->history()->where('event_type', 'status')->first();
        $payload = NotificationPayload::make('admin.clarification_requested', $task, $admin->name, 'en', false, (string) $history->id);
        $this->assertNull($payload['message']);
        $this->assertSame('Open the task and answer the developer.', $payload['next']);
        $this->assertStringNotContainsString('An unrelated old message', (new ProjectActivityMail($payload))->render());
    }

    public function test_unicode_content_bounds_and_truncation_are_equivalent_in_mail_and_push(): void
    {
        $this->assertSame('Я🙂', NotificationPayload::excerpt('Я🙂', 2));
        $this->assertSame('Я🙂…', NotificationPayload::excerpt('Я🙂中', 2));
        $task = Task::factory()->create(['description' => str_repeat('Я🙂', 400)]);
        $created = NotificationPayload::make('client.task_created', $task, 'Иван', 'en', true, '0');
        $this->assertSame(str_repeat('Я🙂', 300).'…', $created['message']);
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => str_repeat('Я🙂', 1100)])->assertOk();
        $history = $task->history()->where('event_type', 'comment')->first();
        $payload = NotificationPayload::make('admin.comment_created', $task, 'Иван', 'ru', false, (string) $history->id);
        $this->assertSame(str_repeat('Я🙂', 1000).'…', $payload['message']);
        foreach ([$created, $payload] as $bounded) {
            $this->assertStringContainsString($bounded['message'], (new ProjectActivityMail($bounded))->render());
            $this->assertStringContainsString($bounded['message'], view('mail.project-activity-text', ['payload' => $bounded])->render());
        }
        $push = NotificationPayload::push($payload);
        $this->assertLessThanOrEqual(NotificationPayload::PUSH_LIMIT + 1, mb_strlen($push['body'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($push['body'], 'UTF-8'));
        $this->assertStringEndsWith('…', $push['body']);
    }

    public function test_a_comment_containing_zero_is_included_in_html_text_and_push(): void
    {
        $task = Task::factory()->create();
        $admin = User::factory()->create(['name' => 'Developer']);
        $this->actingAs($admin)->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => '0'])->assertOk();
        $history = $task->history()->where('event_type', 'comment')->first();
        $payload = NotificationPayload::make('admin.comment_created', $task, $admin->name, 'en', false, (string) $history->id);
        $this->assertSame('0', $payload['message']);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*>0<\/blockquote>/', (new ProjectActivityMail($payload))->render());
        $this->assertStringContainsString("Comment:\n0", view('mail.project-activity-text', ['payload' => $payload])->render());
        $this->assertSame('Developer: 0', NotificationPayload::push($payload)['body']);
    }
}
