<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Mail\VerifyNotificationEmail;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\NotificationEvents;
use App\Services\ProjectNotificationService;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CentralizedNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'brief@example.test', 'telegram.transport' => 'deno', 'telegram.bridge_url' => 'https://test-telegram.deno.dev/telegram/send', 'telegram.bridge_secret' => str_repeat('b', 40), 'app.url' => 'https://brief.example.test']);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['test-telegram.deno.dev/*' => Http::response(['ok' => true])]);
    }

    private function rules(bool $enabled = true): array
    {
        return array_fill_keys(NotificationEvents::CODES, ['enabled' => $enabled, 'recipients' => ['mode' => 'all', 'ids' => []]]);
    }

    private function convert(Project $project, ?array $rules = null): void
    {
        app(ProjectNotificationService::class)->settings($project)->update(['delivery_mode' => 'personal', 'event_rules' => $rules ?? $this->rules()]);
    }

    private function notify(Task $task, int $history, string $event = 'client.task_created', ?int $actor = null): void
    {
        $client = str_starts_with($event, 'client.');
        app(ProjectNotificationService::class)->notify(new ProjectNotificationRequested($history, $event, $task->id, $client ? 'client' : 'admin', $actor ?? ($client ? $task->project->clients()->first()->id : null), 'Test author'));
    }

    private function token(): string
    {
        $mail = Mail::sent(VerifyNotificationEmail::class)->last();
        $this->assertNotNull($mail);
        $this->assertStringContainsString('#verify=', $mail->verificationUrl);

        return substr(parse_url($mail->verificationUrl, PHP_URL_FRAGMENT), 7);
    }

    public function test_user_preferences_do_not_depend_on_selected_workspace_or_other_users(): void
    {
        $first = Project::factory()->create();
        $other = Project::factory()->create();
        $user = User::factory()->owner($first->workspace)->owner($other->workspace)->create();
        $colleague = User::factory()->owner($first->workspace)->create();
        $connection = TelegramConnection::factory()->create(['user_id' => $user->id]);
        PushSubscription::factory()->count(2)->create(['user_id' => $user->id]);
        $this->actingAs($user)->withSession(['workspace_id' => 999999])->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('preferences.email', true)->assertJsonPath('email', $user->email);
        $this->patchJson('/api/admin/notifications', ['preferences' => ['email' => false, 'push' => false, 'telegram' => false]])->assertOk();
        $this->withSession(['workspace_id' => $other->workspace_id])->getJson('/api/admin/notifications')->assertJsonPath('preferences.telegram', false);
        $this->assertSame($connection->getRawOriginal('chat_id'), $connection->fresh()->getRawOriginal('chat_id'));
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->actingAs($colleague)->getJson('/api/admin/notifications')->assertJsonPath('preferences.email', true);
        $this->assertNull($colleague->fresh()->notification_preferences);
    }

    public function test_guests_clients_and_foreign_identity_inputs_cannot_use_personal_admin_settings(): void
    {
        $this->getJson('/api/admin/notifications')->assertUnauthorized();
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $link = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'client')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $link->id])->getJson('/api/admin/notifications')->assertUnauthorized();
        $user = User::factory()->owner()->create();
        $this->actingAs($user)->patchJson('/api/admin/notifications', ['user_id' => 999, 'preferences' => ['email' => false, 'push' => false, 'telegram' => false]])->assertUnprocessable();
        $this->patchJson('/api/admin/notifications', ['preferences' => ['email' => true, 'push' => true, 'telegram' => true, 'user_id' => 999]])->assertUnprocessable();
        $this->postJson('/api/admin/notifications/test', ['channel' => 'email', 'email' => 'foreign@example.test'])->assertUnprocessable();
        $this->assertNull($user->fresh()->notification_preferences);
        Mail::assertNothingSent();
    }

    public function test_conflicting_legacy_addresses_are_retained_until_the_user_explicitly_unifies_email(): void
    {
        $first = Project::factory()->create();
        $other = Project::factory()->create();
        $user = User::factory()->owner($first->workspace)->owner($other->workspace)->create();
        $service = app(ProjectNotificationService::class);
        $service->settings($first)->update(['notification_email' => 'first@example.test']);
        $service->settings($other)->update(['notification_email' => 'second@example.test']);
        $foreign = Project::factory()->create();
        User::factory()->owner($foreign->workspace)->create();
        $service->settings($foreign)->update(['notification_email' => 'private@example.test']);
        $response = $this->actingAs($user)->getJson('/api/admin/notifications')->assertOk()->assertJsonCount(2, 'legacyEmails')->assertJsonPath('emailUnified', false)->assertDontSee('private@example.test');
        $this->assertEqualsCanonicalizing(['first@example.test', 'second@example.test'], array_column($response->json('legacyEmails'), 'email'));
        $this->assertSame('first@example.test', $service->adminEmail($first));
        $this->assertSame('second@example.test', $service->adminEmail($other));
        $this->patchJson('/api/admin/notifications', ['email' => $user->email])->assertOk()->assertJsonPath('emailUnified', true);
        $this->assertSame($user->email, $service->adminEmail($first));
        $this->assertSame($user->email, $service->adminEmail($other));
        $this->assertDatabaseHas('project_notification_settings', ['project_id' => $first->id, 'notification_email' => 'first@example.test']);
        $this->assertDatabaseHas('project_notification_settings', ['project_id' => $other->id, 'notification_email' => 'second@example.test']);
        Mail::assertNothingSent();
    }

    public function test_custom_email_is_single_use_owner_bound_verified_and_separate_from_login_email(): void
    {
        $user = User::factory()->owner()->create(['preferred_locale' => 'en']);
        $this->actingAs($user)->patchJson('/api/admin/notifications', ['email' => 'verified@example.test'])->assertOk()->assertJsonPath('email', $user->email)->assertJsonPath('pendingEmail', 'verified@example.test');
        $token = $this->token();
        $this->assertSame(hash('sha256', $token), $user->fresh()->notification_email_token_hash);
        $this->getJson('/api/admin/notifications')->assertDontSee($token)->assertDontSee(hash('sha256', $token));
        Mail::assertSent(VerifyNotificationEmail::class, fn ($mail) => $mail->hasTo('verified@example.test') && $mail->language === 'en' && ! str_contains($mail->verificationUrl, '?'));
        $other = User::factory()->owner()->create();
        $this->actingAs($other)->postJson('/api/admin/notifications/email/verify', ['token' => $token])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/admin/notifications/email/verify', ['token' => $token])->assertOk()->assertJsonPath('email', 'verified@example.test')->assertJsonPath('pendingEmail', null);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertNull($user->fresh()->notification_email_token_hash);
        $this->postJson('/api/admin/notifications/email/verify', ['token' => $token])->assertUnprocessable();
        $this->postJson('/api/admin/notifications/test', ['channel' => 'email'])->assertOk();
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo('verified@example.test') && str_ends_with($mail->payload['url'], '/admin/settings/notifications'));
    }

    public function test_expired_rotated_and_cancelled_verification_cannot_replace_current_email(): void
    {
        $user = User::factory()->owner()->create();
        $user->forceFill(['notification_email' => 'current@example.test', 'notification_email_resolved_at' => now()])->save();
        $this->actingAs($user)->patchJson('/api/admin/notifications', ['email' => 'one@example.test'])->assertOk();
        $first = $this->token();
        $this->patchJson('/api/admin/notifications', ['email' => 'two@example.test'])->assertOk();
        $second = $this->token();
        $this->postJson('/api/admin/notifications/email/verify', ['token' => $first])->assertUnprocessable();
        $this->travel(31)->minutes();
        $this->postJson('/api/admin/notifications/email/verify', ['token' => $second])->assertUnprocessable();
        $this->deleteJson('/api/admin/notifications/email/pending')->assertOk()->assertJsonPath('pendingEmail', null)->assertJsonPath('email', 'current@example.test');
        config(['mail.default' => 'log']);
        $this->patchJson('/api/admin/notifications', ['email' => 'three@example.test'])->assertUnprocessable()->assertJsonPath('code', 'smtp_unconfigured');
        $this->assertSame('current@example.test', $user->fresh()->notificationEmail());
        $this->assertNull($user->fresh()->pending_notification_email);
    }

    public function test_verification_send_failure_is_sanitized_and_preserves_active_email(): void
    {
        $user = User::factory()->owner()->create();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP_PASSWORD PRIVATE_MESSAGE'));
        $this->actingAs($user)->patchJson('/api/admin/notifications', ['email' => 'new@example.test'])->assertUnprocessable()->assertJsonPath('code', 'email_delivery_failed')->assertDontSee('SMTP_PASSWORD');
        $this->assertSame($user->email, $user->fresh()->notificationEmail());
    }

    public function test_project_semantics_require_confirmation_and_legacy_api_cannot_downgrade_them(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->owner($project->workspace)->create();
        $setting = app(ProjectNotificationService::class)->settings($project);
        $events = NotificationEvents::defaults();
        $events['client.task_created'] = ['email' => false, 'push' => false, 'telegram' => false];
        $setting->update(['notification_email' => 'legacy@example.test', 'events' => $events]);
        $root = '/api/admin/projects/'.$project->uuid.'/notifications';
        $response = $this->actingAs($user)->getJson($root)->assertOk()->assertJsonPath('deliveryMode', 'legacy');
        $this->assertFalse($response->json('eventRules')['client.task_created']['enabled']);
        $this->patchJson($root, ['deliveryMode' => 'personal', 'eventRules' => $this->rules()])->assertUnprocessable();
        $this->assertSame('legacy', $setting->fresh()->delivery_mode);
        $this->patchJson($root, ['deliveryMode' => 'personal', 'eventRules' => $this->rules(), 'confirmMigration' => true])->assertOk()->assertJsonPath('deliveryMode', 'personal');
        $this->assertSame($events, $setting->fresh()->events);
        $this->assertSame('legacy@example.test', $setting->fresh()->notification_email);
        $this->patchJson($root, ['events' => $events])->assertConflict();
    }

    public function test_recipient_validation_rejects_outsiders_wrong_persona_and_revoked_clients(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->owner($project->workspace)->create();
        $foreign = User::factory()->owner()->create();
        $unassigned = User::factory()->memberOf($project->workspace)->create();
        $client = $project->clients()->first();
        $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'access')]);
        $root = '/api/admin/projects/'.$project->uuid.'/notifications';
        $this->actingAs($user)->getJson($root)->assertOk()->assertJsonCount(1, 'recipientOptions.team')->assertJsonCount(1, 'recipientOptions.clients');
        foreach ([$foreign->id, $unassigned->id, $client->uuid] as $outsider) {
            $rules = $this->rules();
            $rules['client.task_created']['recipients'] = ['mode' => 'selected', 'ids' => [$outsider]];
            $this->patchJson($root, ['deliveryMode' => 'personal', 'eventRules' => $rules, 'confirmMigration' => true])->assertUnprocessable();
        }
        $rules = $this->rules();
        $rules['admin.task_done']['recipients'] = ['mode' => 'selected', 'ids' => [$client->uuid]];
        $this->patchJson($root, ['deliveryMode' => 'personal', 'eventRules' => $rules, 'confirmMigration' => true])->assertOk();
        $project->accessTokens()->update(['revoked_at' => now()]);
        $this->patchJson($root, ['deliveryMode' => 'personal', 'eventRules' => $rules])->assertUnprocessable();
        $other = Project::factory()->create();
        $this->getJson('/api/admin/projects/'.$other->uuid.'/notifications')->assertNotFound();
    }

    public function test_selected_team_personal_channels_access_and_dedup_gate_all_delivery(): void
    {
        $task = Task::factory()->create();
        $project = $task->project;
        $owner = User::factory()->owner($project->workspace)->create();
        $manager = User::factory()->memberOf($project->workspace)->create();
        $foreign = User::factory()->owner()->create();
        $membership = $manager->workspaceMembers()->first();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $membership->id]);
        foreach ([$owner, $manager, $foreign] as $person) {
            TelegramConnection::factory()->create(['user_id' => $person->id]);
            PushSubscription::factory()->create(['user_id' => $person->id]);
        }
        $push = $this->mock(WebPushTransport::class);
        $push->shouldReceive('available')->andReturn(true);
        $push->shouldReceive('send')->once()->withArgs(fn ($subscription, $payload) => $subscription->user_id === $manager->id)->andReturn(['status' => 'sent', 'error_code' => null]);
        $rules = $this->rules();
        $rules['client.task_created']['recipients'] = ['mode' => 'selected', 'ids' => [$manager->id, $foreign->id]];
        $this->convert($project, $rules);
        $this->notify($task, 100);
        $this->notify($task, 100);
        Mail::assertSentCount(1);
        Mail::assertSent(ProjectActivityMail::class, fn ($mail) => $mail->hasTo($manager->email));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('notification_deliveries', 3);
        $manager->forceFill(['notification_preferences' => ['email' => false, 'push' => false, 'telegram' => false]])->save();
        $this->notify($task, 101);
        Mail::assertSentCount(1);
        Http::assertSentCount(1);
        $manager->forceFill(['notification_preferences' => null])->save();
        $membership->update(['status' => 'inactive']);
        $this->notify($task, 102);
        Mail::assertSentCount(1);
        Http::assertSentCount(1);
    }

    public function test_no_sudden_legacy_telegram_activation_and_event_disable_affects_only_one_project(): void
    {
        $first = Task::factory()->create();
        $other = Task::factory()->create();
        $user = User::factory()->owner($first->project->workspace)->owner($other->project->workspace)->create();
        TelegramConnection::factory()->create(['user_id' => $user->id]);
        $this->notify($first, 1);
        Http::assertNothingSent();
        $rules = $this->rules();
        $rules['client.task_created']['enabled'] = false;
        $this->convert($first->project, $rules);
        $this->convert($other->project);
        $this->notify($first, 2);
        $this->notify($other, 3);
        Mail::assertSentCount(2);
        Http::assertSentCount(1);
        $this->assertDatabaseMissing('notification_deliveries', ['project_id' => $first->project_id, 'channel' => 'telegram']);
    }

    public function test_selected_clients_keep_personal_channels_self_exclusion_and_access_checks(): void
    {
        $task = Task::factory()->create();
        $project = $task->project;
        $first = $project->clients()->first();
        $first->update(['email' => 'first@example.test']);
        $second = ProjectClient::factory()->for($project)->create();
        foreach ([$first, $second] as $client) {
            $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', (string) $client->id)]);
            TelegramConnection::factory()->create(['user_id' => null, 'project_client_id' => $client->id]);
        }
        $rules = $this->rules();
        $rules['admin.task_done']['recipients'] = ['mode' => 'selected', 'ids' => [$first->uuid]];
        $this->convert($project, $rules);
        $first->update(['notification_preferences' => ['email' => false, 'push' => false, 'telegram' => true]]);
        $this->notify($task, 1, 'admin.task_done');
        Mail::assertNothingSent();
        Http::assertSentCount(1);
        $first->update(['notification_preferences' => ['email' => true, 'push' => false, 'telegram' => false]]);
        $same = User::factory()->owner($project->workspace)->create(['email' => $first->email]);
        $this->notify($task, 2, 'admin.task_done', $same->id);
        Mail::assertNothingSent();
        Http::assertSentCount(1);
        $project->accessTokens()->where('project_client_id', $first->id)->update(['revoked_at' => now()]);
        $this->notify($task, 3, 'admin.task_done');
        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_channel_tests_cannot_override_preferences_or_target_foreign_push_devices(): void
    {
        $user = User::factory()->owner()->create();
        $device = PushSubscription::factory()->create();
        $this->actingAs($user)->postJson('/api/admin/notifications/test', ['channel' => 'push', 'endpoint' => $device->endpoint])->assertUnprocessable()->assertJsonPath('code', 'device_not_subscribed');
        $this->patchJson('/api/admin/notifications', ['preferences' => ['email' => false, 'push' => false, 'telegram' => false]])->assertOk();
        foreach (['email', 'push'] as $channel) {
            $this->postJson('/api/admin/notifications/test', ['channel' => $channel])->assertUnprocessable()->assertJsonPath('code', 'channel_disabled');
        }
        $this->postJson('/api/admin/telegram/test')->assertUnprocessable()->assertJsonPath('code', 'channel_disabled');
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_additive_migration_keeps_legacy_values_devices_telegram_and_receipts(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->owner($project->workspace)->create();
        $connection = TelegramConnection::factory()->create(['user_id' => $user->id]);
        $device = PushSubscription::factory()->create(['user_id' => $user->id]);
        $settings = app(ProjectNotificationService::class)->settings($project);
        $events = array_fill_keys(NotificationEvents::CODES, ['email' => false, 'push' => true, 'telegram' => false]);
        $settings->update(['notification_email' => 'legacy@example.test', 'events' => $events]);
        DB::table('telegram_updates')->insert(['update_id' => 123, 'created_at' => now()]);
        $migration = require database_path('migrations/2026_10_20_201536_centralize_notification_preferences.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'notification_preferences'));
        $migration->up();
        $this->assertSame('legacy', $settings->fresh()->delivery_mode);
        $this->assertSame($events, $settings->fresh()->preferences());
        $this->assertSame('legacy@example.test', $settings->fresh()->notification_email);
        $this->assertSame($connection->getRawOriginal('chat_id'), $connection->fresh()->getRawOriginal('chat_id'));
        $this->assertSame($device->getRawOriginal('endpoint'), $device->fresh()->getRawOriginal('endpoint'));
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 123]);
        $this->assertNull($user->fresh()->notification_preferences);
    }
}
