<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\TelegramLinking;
use App\Services\TelegramTransport;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.transport' => 'deno', 'telegram.webhook_transport' => 'deno', 'telegram.bot_username' => 'BriefWeb86Bot',
            'telegram.bot_token' => null, 'telegram.webhook_secret' => str_repeat('w', 40), 'telegram.forward_secret' => str_repeat('f', 40),
            'telegram.bridge_secret' => str_repeat('b', 40), 'telegram.bridge_url' => 'https://projectbrief-telegram-bridge.web86.deno.net/telegram/send', 'telegram.default_locale' => 'ru']);
        Http::preventStrayRequests();
        Http::fake(['projectbrief-telegram-bridge.web86.deno.net/*' => Http::response(['ok' => true])]);
    }

    private function update(string $text = '/start', int $id = 100, int $chat = 12345): array
    {
        return ['update_id' => $id, 'message' => ['text' => $text, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat]]];
    }

    private function postUpdate(array $update): TestResponse
    {
        return $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Forward-Secret' => str_repeat('f', 40)]);
    }

    private function start(User $user, int $id = 100, int $chat = 12345): array
    {
        $url = app(TelegramLinking::class)->issue($user)['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $this->update('/start '.$query['start'], $id, $chat);
    }

    public function test_inbound_modes_do_not_fall_back_to_each_others_credentials(): void
    {
        $update = $this->update();
        foreach ([[], ['X-Telegram-Forward-Secret' => 'wrong'], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('w', 40)], ['Authorization' => 'Bearer '.str_repeat('b', 40)]] as $headers) {
            $this->postJson('/api/telegram/webhook', $update, $headers)->assertForbidden();
        }
        $this->assertDatabaseCount('telegram_updates', 0);
        Http::assertNothingSent();
        $this->postUpdate($update)->assertOk()->assertExactJson(['ok' => true]);
        config(['telegram.webhook_transport' => 'direct']);
        $this->postUpdate($this->update(id: 101))->assertForbidden();
        $this->postJson('/api/telegram/webhook', $this->update(id: 101), ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('w', 40)])->assertOk();
        config(['telegram.webhook_transport' => 'invalid']);
        $this->postUpdate($this->update(id: 102))->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_bad_configuration_and_invalid_or_oversized_updates_fail_without_sending(): void
    {
        foreach (['', 'short', str_repeat('w', 40), str_repeat('b', 40)] as $secret) {
            config(['telegram.forward_secret' => $secret]);
            $this->postJson('/api/telegram/webhook', $this->update(), ['X-Telegram-Forward-Secret' => $secret])->assertForbidden();
        }
        config(['telegram.forward_secret' => str_repeat('f', 40)]);
        $this->postUpdate(['message' => []])->assertUnprocessable();
        $this->postUpdate(['update_id' => -1])->assertUnprocessable();
        $this->postUpdate(['update_id' => 1, 'message' => 'invalid'])->assertUnprocessable();
        $this->postUpdate($this->update(str_repeat('я', 9000)))->assertStatus(413);
        Http::assertNothingSent();
        $this->assertDatabaseCount('telegram_updates', 0);
    }

    public function test_private_welcome_invalid_and_expired_starts_are_understandable_and_deduplicated(): void
    {
        $welcome = $this->update('/start@BriefWeb86Bot');
        $invalid = $this->update('/start invalid', 101);
        $user = User::factory()->owner()->create();
        $expired = $this->start($user, 102);
        $this->travel(11)->minutes();
        foreach ([$welcome, $invalid, $expired] as $update) {
            $this->postUpdate($update)->assertOk();
            $this->postUpdate($update)->assertOk();
        }
        Http::assertSentCount(3);
        $requests = Http::recorded();
        $this->assertStringContainsString('Привет! Я BriefWeb86Bot', $requests[0][0]['payload']['text']);
        $this->assertStringContainsString('ссылка устарела или уже использована', $requests[1][0]['payload']['text']);
        $this->assertSame($requests[1][0]['payload']['text'], $requests[2][0]['payload']['text']);
        $this->assertNull(TelegramLinking::query($user)->first()->chat_id);
        $this->assertDatabaseCount('telegram_updates', 3);
        $this->assertDatabaseCount('telegram_connections', 1);
    }

    public function test_binding_rebinding_and_known_chat_replies_use_recipient_locale_and_private_transport(): void
    {
        $user = User::factory()->owner()->create(['preferred_locale' => 'en']);
        $first = $this->start($user);
        $this->postUpdate($first)->assertOk();
        $connection = TelegramLinking::query($user)->firstOrFail();
        $this->assertSame('12345', $connection->chat_id);
        $this->assertSame(TelegramConnection::chatHash('12345'), $connection->chat_hash);
        $this->assertArrayNotHasKey('chat_hash', $connection->toArray());
        $this->postUpdate($first)->assertOk();
        $this->postUpdate($this->update('/start', 101))->assertOk();
        $this->postUpdate($this->update('/start used', 102))->assertOk();
        $rebind = $this->start($user, 103, 54321);
        $this->assertSame('12345', $connection->fresh()->chat_id);
        $this->postUpdate($rebind)->assertOk();
        $this->postUpdate($rebind)->assertOk();
        $this->assertSame('54321', $connection->fresh()->chat_id);
        Http::assertSentCount(4);
        $requests = Http::recorded();
        $this->assertStringContainsString('successfully connected', $requests[0][0]['payload']['text']);
        $this->assertStringContainsString("Hi! I'm BriefWeb86Bot", $requests[1][0]['payload']['text']);
        $this->assertStringContainsString('Could not connect', $requests[2][0]['payload']['text']);
        foreach ($requests as [$request]) {
            $this->assertSame(config('telegram.bridge_url'), $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.str_repeat('b', 40)));
            $this->assertSame('sendMessage', $request['method']);
            $this->assertArrayNotHasKey('parse_mode', $request['payload']);
            $this->assertStringNotContainsString(substr($first['message']['text'], 7), $request['payload']['text']);
        }
        app(TelegramLinking::class)->disconnect($user);
        $this->assertNull($connection->fresh()->chat_hash);
    }

    public function test_rejected_successfully_used_link_does_not_reveal_or_replace_other_identity(): void
    {
        $owner = User::factory()->owner()->create(['preferred_locale' => 'ru']);
        $start = $this->start($owner);
        $this->postUpdate($start)->assertOk();
        $start['update_id'] = 101;
        $start['message']['chat']['id'] = 54321;
        $start['message']['from']['id'] = 54321;
        $this->postUpdate($start)->assertOk();
        $this->postUpdate($start)->assertOk();
        $this->assertSame('12345', TelegramLinking::query($owner)->first()->chat_id);
        Http::assertSentCount(2);
        $reply = Http::recorded()[1][0]['payload']['text'];
        $this->assertStringContainsString('Не удалось подключить Telegram', $reply);
        $this->assertStringNotContainsString($owner->name, $reply);
        $this->assertStringNotContainsString($owner->email, $reply);
    }

    public function test_client_locale_and_unconfirmed_default_locale_are_independent_of_telegram_language(): void
    {
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $client->update(['preferred_locale' => 'en']);
        $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $url = app(TelegramLinking::class)->issue($client)['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $valid = $this->update('/start '.$query['start']);
        $valid['message']['from']['language_code'] = 'ru';
        $this->postUpdate($valid)->assertOk();
        $this->postUpdate($this->update('/start', 101))->assertOk();
        config(['telegram.default_locale' => 'en']);
        $this->postUpdate($this->update('/start', 102, 54321))->assertOk();
        Http::assertSentCount(3);
        $requests = Http::recorded();
        $this->assertStringContainsString('successfully connected', $requests[0][0]['payload']['text']);
        $this->assertStringContainsString("Hi! I'm BriefWeb86Bot", $requests[1][0]['payload']['text']);
        $this->assertStringContainsString("Hi! I'm BriefWeb86Bot", $requests[2][0]['payload']['text']);
    }

    public function test_groups_spoofed_chats_other_bots_and_arbitrary_messages_do_not_reply_or_consume_codes(): void
    {
        $user = User::factory()->owner()->create();
        $valid = $this->start($user);
        $group = $valid;
        $group['message']['chat']['type'] = 'supergroup';
        $spoof = $valid;
        $spoof['message']['from']['id'] = 54321;
        $malformed = $valid;
        $malformed['message']['from'] = 'not an object';
        $negative = $valid;
        $negative['message']['chat']['id'] = -12345;
        $negative['message']['from']['id'] = -12345;
        foreach ([$group, $spoof, $malformed, $negative, $this->update('/start@OtherBot payload'), $this->update('hello'), ['update_id' => 100], ['update_id' => 100, 'edited_message' => $valid['message']]] as $update) {
            $this->postUpdate($update)->assertOk();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('telegram_updates', 0);
        $this->assertNotNull(TelegramLinking::query($user)->first()->code_hash);
        $this->postUpdate($valid)->assertOk();
        Http::assertSentCount(1);
        $this->assertSame('12345', TelegramLinking::query($user)->first()->chat_id);
        $this->assertSame('telegram_invalid_start', app(TelegramTransport::class)->replyToStart($spoof, 'arbitrary')['error_code']);
        Http::assertSentCount(1);
    }

    public function test_new_code_cannot_be_consumed_by_an_already_processed_welcome_update_id(): void
    {
        $this->postUpdate($this->update())->assertOk();
        $user = User::factory()->owner()->create();
        $valid = $this->start($user);
        $this->postUpdate($valid)->assertOk();
        $this->assertNull(TelegramLinking::query($user)->first()->chat_id);
        $this->assertNotNull(TelegramLinking::query($user)->first()->code_hash);
        Http::assertSentCount(1);
        $valid['update_id']++;
        $this->postUpdate($valid)->assertOk();
        $this->assertSame('12345', TelegramLinking::query($user)->first()->chat_id);
        Http::assertSentCount(2);
    }

    public function test_database_failure_rolls_back_binding_and_receipt_and_returns_retryable_status(): void
    {
        $user = User::factory()->owner()->create();
        $valid = $this->start($user);
        $original = app(TelegramLinking::class);
        $this->mock(TelegramLinking::class, function ($mock) use ($original): void {
            $mock->shouldReceive('redeem')->once()->andReturnUsing(function (array $update) use ($original): void {
                $original->redeem($update);
                throw new RuntimeException('PRIVATE CODE URL SECRET');
            });
        });
        $this->postUpdate($valid)->assertStatus(503)->assertExactJson(['ok' => false]);
        $this->assertDatabaseCount('telegram_updates', 0);
        $connection = TelegramLinking::query($user)->first();
        $this->assertNull($connection->chat_id);
        $this->assertNotNull($connection->code_hash);
        Http::assertNothingSent();
        $this->app->instance(TelegramLinking::class, $original);
        $this->postUpdate($valid)->assertOk();
        $this->assertSame('12345', $connection->fresh()->chat_id);
        $this->assertDatabaseCount('telegram_updates', 1);
        Http::assertSentCount(1);
    }

    public function test_failed_confirmation_does_not_repeat_a_committed_binding_or_reply(): void
    {
        Log::spy();
        foreach ([429, 500, 'network'] as $index => $failure) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['projectbrief-telegram-bridge.web86.deno.net/*' => $failure === 'network' ? Http::failedConnection('PRIVATE SECRET') : Http::response(['ok' => false, 'error_code' => $failure, 'description' => 'PRIVATE SECRET'], $failure)]);
            $user = User::factory()->owner()->create();
            $valid = $this->start($user, 100 + $index);
            $this->postUpdate($valid)->assertOk()->assertExactJson(['ok' => true]);
            $connection = TelegramLinking::query($user)->first();
            $this->assertSame('12345', $connection->chat_id);
            $connectedAt = $connection->connected_at;
            $this->travel(1)->minutes();
            $this->postUpdate($valid)->assertOk();
            $this->assertEquals($connectedAt, $connection->fresh()->connected_at);
            $this->assertNull($connection->fresh()->code_hash);
            Http::assertSentCount(1);
        }
        Log::shouldHaveReceived('warning')->with('telegram.start_reply_failed', ['error_code' => 'telegram_rate_limited'])->once();
        Log::shouldHaveReceived('warning')->with('telegram.start_reply_failed', ['error_code' => 'telegram_unavailable'])->twice();
    }

    public function test_processing_acknowledgement_is_ready_before_any_confirmation_network_request(): void
    {
        $user = User::factory()->owner()->create();
        $update = $this->start($user);
        $request = Request::create('/api/telegram/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TELEGRAM_FORWARD_SECRET' => str_repeat('f', 40)], json_encode($update));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode($response->getContent(), true));
        $this->assertSame('12345', TelegramLinking::query($user)->first()->chat_id);
        Http::assertNothingSent();
        $kernel->terminate($request, $response);
        Http::assertSentCount(1);
        $kernel->terminate($request, $response);
        Http::assertSentCount(1);
    }

    public function test_inactive_admin_and_revoked_client_codes_are_rejected_with_one_reply(): void
    {
        $user = User::factory()->owner()->create();
        $valid = $this->start($user);
        $user->workspaceMembers()->update(['status' => 'inactive']);
        $this->postUpdate($valid)->assertOk();
        $this->postUpdate($valid)->assertOk();
        $this->assertNull(TelegramLinking::query($user)->first()->chat_id);
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $url = app(TelegramLinking::class)->issue($client)['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $project->accessTokens()->update(['revoked_at' => now()]);
        $this->postUpdate($this->update('/start '.$query['start'], 101))->assertOk();
        $this->assertNull(TelegramLinking::query($client)->first()->chat_id);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('Не удалось подключить Telegram', $request['payload']['text']);
        }
    }

    public function test_safe_logs_and_cli_reveal_no_secrets_or_private_input(): void
    {
        Log::spy();
        $logs = [];
        Log::shouldReceive('info')->andReturnUsing(function (...$args) use (&$logs): void {
            $logs[] = $args;
        });
        $user = User::factory()->owner()->create();
        $valid = $this->start($user);
        $this->postUpdate($valid)->assertOk();
        $this->postUpdate($valid)->assertOk();
        $this->postUpdate($this->update('/start PRIVATE_CODE', 101))->assertOk();
        foreach (['telegram.webhook_received', 'telegram.start_recognized', 'telegram.code_redeemed', 'telegram.code_rejected', 'telegram.update_duplicate'] as $event) {
            Log::shouldHaveReceived('info')->with($event);
        }
        Log::shouldHaveReceived('info')->with('telegram.start_reply_sent', ['outcome' => 'connected']);
        Log::shouldNotHaveReceived('warning');
        $serialized = json_encode($logs);
        foreach ([str_repeat('f', 40), str_repeat('w', 40), str_repeat('b', 40), 'PRIVATE_CODE', $valid['message']['text'], '12345', $user->email, $user->name, 'update_id', 'chat_id'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $this->artisan('telegram:check')->expectsOutput('Transport: deno')->expectsOutput('Webhook transport: deno')->expectsOutput('Webhook configured.')->assertSuccessful();
        config(['telegram.forward_secret' => 'short']);
        $this->artisan('telegram:check')->expectsOutput('Webhook not configured.')->assertFailed();
        Http::assertSentCount(2);
    }

    public function test_migration_backfills_existing_encrypted_chats_without_changing_receipts_or_codes(): void
    {
        $pending = str_repeat('a', 64);
        $connection = TelegramConnection::factory()->create(['code_hash' => hash('sha256', $pending), 'code_expires_at' => now()->addMinutes(10)]);
        $raw = $connection->getRawOriginal('chat_id');
        $expires = $connection->code_expires_at;
        DB::table('telegram_updates')->insert(['update_id' => 7, 'created_at' => now()]);
        $migration = require database_path('migrations/2026_10_20_184031_add_chat_hash_to_telegram_connections.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('telegram_connections', 'chat_hash'));
        $migration->up();
        $migration->up();
        $this->assertSame($raw, $connection->fresh()->getRawOriginal('chat_id'));
        $this->assertSame(TelegramConnection::chatHash($connection->chat_id), $connection->fresh()->chat_hash);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 7]);
        $this->assertEquals($expires, $connection->fresh()->code_expires_at);
        $this->postUpdate($this->update('/start '.$pending, 7, 54321))->assertOk();
        $this->assertSame($raw, $connection->fresh()->getRawOriginal('chat_id'));
        $this->assertSame(hash('sha256', $pending), $connection->fresh()->code_hash);
        Http::assertNothingSent();
        $this->postUpdate($this->update('/start '.$pending, 8, 54321))->assertOk();
        $this->assertSame('54321', $connection->fresh()->chat_id);
        Http::assertSentCount(1);
    }
}
