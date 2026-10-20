<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\Task;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\NotificationEvents;
use App\Services\NotificationPayload;
use App\Services\ProjectNotificationService;
use App\Services\TelegramLinking;
use App\Services\TelegramTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN = '123456:abcdefghijklmnopqrstuvwxyzABCDEF';

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.transport' => 'direct', 'telegram.bot_token' => self::TOKEN, 'telegram.bot_username' => 'ProjectBriefTestBot', 'telegram.webhook_secret' => str_repeat('s', 40), 'app.url' => 'https://brief.example.test']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function webhook(string $url, int $id = 1, int $chat = 12345): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return ['update_id' => $id, 'message' => ['text' => '/start '.$query['start'], 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat]]];
    }

    private function postWebhook(array $update): TestResponse
    {
        return $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 40)]);
    }

    private function enter(Project $project, ProjectClient $client): void
    {
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    public function test_admin_linking_is_expiring_single_use_secret_verified_and_disconnect_revokes_pending_codes(): void
    {
        $admin = User::factory()->owner()->create(['preferred_locale' => 'ru']);
        $this->actingAs($admin);
        $this->getJson('/api/admin/telegram')->assertOk()->assertJsonPath('connected', false)->assertDontSee(self::TOKEN);
        $url = $this->postJson('/api/admin/telegram/connect')->assertOk()->json('url');
        $connection = TelegramLinking::query($admin)->firstOrFail();
        $this->assertStringNotContainsString($connection->code_hash, $url);
        $update = $this->webhook($url);
        $this->postJson('/api/telegram/webhook', $update)->assertForbidden();
        $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        $this->postWebhook($update)->assertOk();
        $this->getJson('/api/admin/telegram')->assertJsonPath('connected', true)->assertDontSee('12345')->assertDontSee(self::TOKEN);
        $this->assertSame('12345', $connection->fresh()->chat_id);
        $this->assertNotSame('12345', DB::table('telegram_connections')->value('chat_id'));
        Http::assertSentCount(1);
        $this->postWebhook($update)->assertOk();
        $this->postWebhook($this->webhook($url, 2, 99999))->assertOk();
        Http::assertSentCount(2);
        $pending = $this->postJson('/api/admin/telegram/connect')->json('url');
        $this->deleteJson('/api/admin/telegram')->assertOk();
        $this->postWebhook($this->webhook($pending, 3))->assertOk();
        $this->assertNull($connection->fresh()->chat_id);
        $this->assertNull($connection->fresh()->code_hash);
    }

    public function test_rotating_and_expired_codes_and_replayed_update_ids_cannot_rebind(): void
    {
        $user = User::factory()->owner()->create();
        $linking = app(TelegramLinking::class);
        $old = $linking->issue($user)['url'];
        $new = $linking->issue($user)['url'];
        $this->postWebhook($this->webhook($old))->assertOk();
        $this->assertNull(TelegramLinking::query($user)->first()->chat_id);
        $this->postWebhook($this->webhook($new, 2))->assertOk();
        $rebind = $linking->issue($user)['url'];
        $this->postWebhook($this->webhook($rebind, 1, 54321))->assertOk();
        $this->assertSame('12345', TelegramLinking::query($user)->first()->chat_id);
        $this->postWebhook($this->webhook($rebind, 3, 54321))->assertOk();
        $this->assertSame('54321', TelegramLinking::query($user)->first()->chat_id);
        $expired = $linking->issue($user)['url'];
        $this->travel(11)->minutes();
        $this->postWebhook($this->webhook($expired, 4, 77777))->assertOk();
        $this->assertSame('54321', TelegramLinking::query($user)->first()->chat_id);
        Http::assertSentCount(4);
    }

    public function test_client_links_are_personal_and_refuse_arbitrary_chat_ids_groups_and_revoked_access(): void
    {
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $other = ProjectClient::factory()->for($project)->create();
        $this->enter($project, $client);
        $this->postJson('/api/client/telegram/connect', ['chat_id' => 99999])->assertUnprocessable();
        $this->postJson('/api/client/telegram/test', ['chat_id' => 99999])->assertUnprocessable();
        $url = $this->postJson('/api/client/telegram/connect')->assertOk()->json('url');
        $group = $this->webhook($url);
        $group['message']['chat']['type'] = 'group';
        $this->postWebhook($group)->assertOk();
        $spoof = $this->webhook($url);
        $spoof['message']['from']['id'] = 44444;
        $this->postWebhook($spoof)->assertOk();
        $this->assertNull(TelegramLinking::query($client)->first()->chat_id);
        $this->postWebhook($this->webhook($url))->assertOk();
        $this->assertFalse(TelegramLinking::query($other)->exists());
        $this->postJson('/api/client/telegram/test')->assertOk();
        $url = $this->postJson('/api/client/telegram/connect')->json('url');
        $project->accessTokens()->update(['revoked_at' => now()]);
        $this->postWebhook($this->webhook($url, 2, 99999))->assertOk();
        $this->assertSame('12345', TelegramLinking::query($client)->first()->chat_id);
        $this->postJson('/api/client/telegram/test')->assertUnauthorized();
        Http::assertSentCount(3);
    }

    public function test_direct_and_deno_use_only_expected_endpoints_and_separate_secrets_without_retry(): void
    {
        $connection = TelegramConnection::factory()->create();
        $transport = app(TelegramTransport::class);
        $payload = ['text' => 'Synthetic test'];
        $this->assertSame('sent', $transport->send($connection, $payload)['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage' && $request['chat_id'] === $connection->chat_id);
        config(['telegram.transport' => 'deno', 'telegram.bot_token' => null, 'telegram.bridge_url' => 'https://telegram-brief.deno.dev/telegram/send', 'telegram.bridge_secret' => str_repeat('b', 40)]);
        Http::fake(['telegram-brief.deno.dev/*' => Http::response(['ok' => true])]);
        $this->assertSame('sent', $transport->send($connection, $payload)['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://telegram-brief.deno.dev/telegram/send' && $request->hasHeader('Authorization', 'Bearer '.str_repeat('b', 40)) && $request['method'] === 'sendMessage' && ! str_contains($request->body(), self::TOKEN));
        $this->assertSame('sent', $transport->diagnose()['status']);
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body());

            return ($body->method ?? null) === 'getMe' && is_object($body->payload) && get_object_vars($body->payload) === [];
        });
        foreach (['http://telegram-brief.deno.dev/telegram/send', 'https://evil.test/telegram/send', 'https://telegram-brief.deno.dev/ai', 'https://telegram-brief.deno.dev/telegram/send?target=evil', 'https://telegram-brief.deno.dev:443/telegram/send'] as $url) {
            config(['telegram.bridge_url' => $url]);
            $this->assertSame('telegram_unconfigured', $transport->send($connection, $payload)['error_code']);
        }
    }

    public function test_telegram_errors_are_bounded_sanitized_and_blocked_chat_is_disconnected(): void
    {
        $connection = TelegramConnection::factory()->create();
        foreach ([429 => 'telegram_rate_limited', 401 => 'telegram_unauthorized', 400 => 'telegram_chat_unavailable', 500 => 'telegram_unavailable', 403 => 'telegram_blocked'] as $status => $code) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => $status, 'description' => self::TOKEN.' PRIVATE_MESSAGE'], $status)]);
            $result = app(TelegramTransport::class)->send($connection, ['text' => 'Synthetic']);
            $this->assertSame(['status' => 'failed', 'error_code' => $code], $result);
            Http::assertSentCount(1);
        }
        $this->assertNull($connection->fresh()->chat_id);
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => Http::failedConnection(self::TOKEN)]);
        $connection->refresh()->update(['chat_id' => '12345']);
        $this->assertSame('telegram_unavailable', app(TelegramTransport::class)->send($connection, ['text' => 'Synthetic'])['error_code']);
        Http::assertSentCount(1);
    }

    public function test_task_delivery_checks_membership_personal_preferences_locale_and_deduplication(): void
    {
        $task = Task::factory()->create(['title' => 'Public task <tag>', 'developer_notes' => 'PRIVATE_NOTE', 'price' => 93841, 'estimate_hours' => 6432]);
        $project = $task->project;
        $service = app(ProjectNotificationService::class);
        $service->settings($project)->update(['events' => ['admin.comment_created' => ['email' => false, 'push' => false, 'telegram' => true]]]);
        $recipients = [];
        foreach (['ru', 'en'] as $index => $locale) {
            $client = ProjectClient::factory()->for($project)->create(['preferred_locale' => $locale]);
            $this->enter($project, $client);
            TelegramConnection::factory()->create(['user_id' => null, 'project_client_id' => $client->id, 'chat_id' => (string) (12340 + $index)]);
            $recipients[] = $client;
        }
        $foreign = ProjectClient::factory()->create();
        TelegramConnection::factory()->create(['user_id' => null, 'project_client_id' => $foreign->id]);
        $comment = $task->comments()->create(['author_type' => 'admin', 'text' => 'Public <b>comment</b> & details']);
        $history = $task->history()->create(['actor_type' => 'admin', 'event_type' => 'comment', 'meta' => ['commentId' => $comment->id]]);
        $event = new ProjectNotificationRequested($history->id, 'admin.comment_created', $task->id, 'admin', null, 'Developer');
        $service->notify($event);
        $service->notify($event);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        foreach ($requests as [$request]) {
            $text = $request['text'];
            $this->assertStringContainsString('&lt;b&gt;comment&lt;/b&gt; &amp; details', $text);
            $this->assertStringNotContainsString('PRIVATE_NOTE', $text);
            $this->assertStringNotContainsString('93841', $text);
            $this->assertStringNotContainsString($task->uuid, $text);
            $this->assertTrue($request['link_preview_options']['is_disabled']);
            $this->assertStringContainsString('/project/'.$project->uuid.'/task/'.$task->uuid, $request['reply_markup']['inline_keyboard'][0][0]['url']);
        }
        $this->assertStringContainsString('Комментарий разработчика', $requests[0][0]['text']);
        $this->assertStringContainsString('New developer comment', $requests[1][0]['text']);
        $recipients[0]->update(['notification_preferences' => ['telegram' => false]]);
        $recipients[1]->accessTokens()->update(['revoked_at' => now()]);
        $service->notify(new ProjectNotificationRequested($history->id + 100, 'admin.comment_created', $task->id, 'admin', null, 'Developer'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_general_discussion_events_use_project_links_and_self_notifications_are_excluded(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $client = $project->clients()->first();
        TelegramConnection::factory()->create(['user_id' => $owner->id, 'chat_id' => '77777']);
        $service = app(ProjectNotificationService::class);
        $service->settings($project)->update(['events' => ['client.project_comment_created' => ['email' => false, 'push' => false, 'telegram' => true]]]);
        $comment = Comment::create(['project_id' => $project->id, 'author_type' => 'client', 'project_client_id' => $client->id, 'text' => 'General question']);
        $event = new ProjectNotificationRequested($comment->id, 'client.project_comment_created', null, 'client', $client->id, $client->name, $comment->id);
        $service->notify($event);
        $service->notify($event);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['reply_markup']['inline_keyboard'][0][0]['url'], '/discussions#comment-') && str_contains($request['text'], 'General question'));
        $client->update(['email' => $owner->email]);
        $other = Comment::create(['project_id' => $project->id, 'author_type' => 'client', 'project_client_id' => $client->id, 'text' => 'Own identity']);
        $service->notify(new ProjectNotificationRequested($other->id, 'client.project_comment_created', null, 'client', $client->id, $client->name, $other->id));
        Http::assertSentCount(1);
        $owner->workspaceMembers()->update(['status' => 'inactive']);
        $client->update(['email' => 'different@example.test']);
        $service->notify(new ProjectNotificationRequested($other->id, 'client.project_comment_created', null, 'client', $client->id, $client->name, $other->id));
        Http::assertSentCount(1);
    }

    public function test_existing_event_settings_are_preserved_and_telegram_defaults_to_off(): void
    {
        $project = Project::factory()->create();
        $service = app(ProjectNotificationService::class);
        $service->settings($project)->update(['events' => ['client.task_created' => ['email' => false, 'push' => true]]]);
        $this->actingAs($this->ownerFor($project));
        $root = '/api/admin/projects/'.$project->uuid.'/notifications';
        $settings = $this->getJson($root)->assertOk()->json('events');
        foreach ($settings as $preferences) {
            $this->assertFalse($preferences['telegram']);
        }
        $this->assertFalse($settings['client.task_created']['email']);
        $settings['client.task_created']['telegram'] = true;
        $this->patchJson($root, ['events' => $settings])->assertOk();
        $legacy = array_intersect_key($settings, array_flip(NotificationEvents::TASK_CODES));
        foreach ($legacy as &$event) {
            unset($event['telegram']);
        }
        unset($event);
        $this->patchJson($root, ['events' => $legacy])->assertOk();
        $this->assertTrue($service->settings($project)->preferences()['client.task_created']['telegram']);
    }

    public function test_message_bounds_escape_html_and_cli_diagnostic_does_not_send_messages(): void
    {
        $payload = NotificationPayload::test('ru', 'https://brief.example.test/project/example');
        $payload['message'] = str_repeat('<>&', 1500);
        $payload['actor'] = '<script>alert(1)</script>';
        $message = TelegramTransport::message($payload);
        $this->assertLessThan(4096, mb_strlen(html_entity_decode(strip_tags($message['text']))));
        $this->assertStringNotContainsString('<script>', $message['text']);
        $this->artisan('telegram:check')->assertSuccessful();
        Http::assertNothingSent();
        $this->artisan('telegram:check --live')->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getMe') && $request->method() === 'GET');
        Http::assertSentCount(1);
    }
}
