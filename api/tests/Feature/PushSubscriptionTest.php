<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function keys(): array
    {
        $keys = VAPID::createVapidKeys();
        config(['project_notifications.vapid' => ['subject' => 'mailto:brief@example.test', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);

        return $keys;
    }

    private function payload(string $suffix = 'one'): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => ['p256dh' => $this->keys()['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]];
    }

    private function client(Project $project): void
    {
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $project->uuid)]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    public function test_authenticated_subscription_stores_encrypted_keys_and_allows_multiple_devices(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $one = $this->payload('mac');
        $two = $this->payload('phone');
        $this->postJson('/api/admin/push/subscriptions', $one)->assertOk()->assertExactJson(['subscribed' => true]);
        $this->postJson('/api/admin/push/subscriptions', $two)->assertOk();
        $this->postJson('/api/admin/push/subscriptions', $one)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);
        $raw = DB::table('push_subscriptions')->first();
        $this->assertNotSame($one['endpoint'], $raw->endpoint);
        $this->assertNotSame($one['keys']['auth'], $raw->auth_token);
        $response = $this->getJson('/api/admin/push/status?endpointHash='.hash('sha256', $one['endpoint']))->assertOk()->assertJsonPath('subscribed', true);
        $this->assertSame(['available', 'publicKey', 'subscribed'], array_keys($response->json()));
        $this->assertStringNotContainsString(config('project_notifications.vapid.private_key'), $response->getContent());
        $this->deleteJson('/api/admin/push/subscriptions', ['endpoint' => $one['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame($two['endpoint'], PushSubscription::first()->endpoint);
    }

    public function test_client_owner_is_derived_from_session_and_other_client_records_are_isolated(): void
    {
        $one = Project::factory()->create();
        $two = Project::factory()->create();
        $this->client($one);
        $payload = $this->payload();
        $this->postJson('/api/client/push/subscriptions', [...$payload, 'project_client_id' => $two->clients()->first()->id])->assertUnprocessable();
        $this->postJson('/api/client/push/subscriptions', $payload)->assertOk();
        $this->assertSame($one->clients()->first()->id, PushSubscription::first()->project_client_id);
        $this->client($two);
        $this->getJson('/api/client/push/status?endpointHash='.hash('sha256', $payload['endpoint']))->assertOk()->assertJsonPath('subscribed', false);
        $this->postJson('/api/client/push/subscriptions', $payload)->assertConflict();
        $this->deleteJson('/api/client/push/subscriptions', ['endpoint' => $payload['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->getJson('/api/admin/push/status')->assertUnauthorized();
        $this->getJson('/api/admin/projects/'.$one->uuid.'/notifications')->assertUnauthorized();
    }

    public function test_admin_without_client_session_cannot_use_client_subscription_api(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/client/push/subscriptions', $this->payload())->assertUnauthorized();
        $this->getJson('/api/client/push/status')->assertUnauthorized();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_unauthenticated_and_unconfigured_requests_are_rejected_without_records(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/admin/push/subscriptions', $payload)->assertUnauthorized();
        $this->deleteJson('/api/client/push/subscriptions', ['endpoint' => $payload['endpoint']])->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        config(['project_notifications.vapid.private_key' => null]);
        $this->postJson('/api/admin/push/subscriptions', $payload)->assertUnprocessable()->assertJsonPath('code', 'push_unconfigured');
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public static function invalidEndpoints(): array
    {
        return [['http://fcm.googleapis.com/push'], ['https://127.0.0.1/push'], ['https://fcm.googleapis.com.evil.test/push'], ['https://user:secret@fcm.googleapis.com/push'], ['https://fcm.googleapis.com:8443/push'], ['https://example.test/push']];
    }

    #[DataProvider('invalidEndpoints')]
    public function test_non_push_or_insecure_endpoints_are_rejected_with_422(string $endpoint): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $payload['endpoint'] = $endpoint;
        $this->postJson('/api/admin/push/subscriptions', $payload)->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public static function providerStatuses(): array
    {
        return [[201, 'sent', false], [404, 'skipped', true], [410, 'skipped', true], [503, 'failed', false]];
    }

    #[DataProvider('providerStatuses')]
    public function test_real_transport_handles_expired_and_temporary_responses(int $httpStatus, string $status, bool $deleted): void
    {
        $payload = $this->payload();
        $subscription = PushSubscription::factory()->create(['endpoint' => $payload['endpoint'], 'public_key' => $payload['keys']['p256dh'], 'auth_token' => $payload['keys']['auth']]);
        Http::preventStrayRequests();
        Http::fake(['https://fcm.googleapis.com/*' => Http::response('', $httpStatus)]);
        $result = app(WebPushTransport::class)->send($subscription, ['title' => 'Test', 'body' => 'Safe', 'url' => 'https://brief.example.test/']);
        $this->assertSame($status, $result['status']);
        $this->assertSame(! $deleted, PushSubscription::whereKey($subscription->id)->exists());
        Http::assertSentCount(1);
    }

    public function test_push_test_is_limited_to_current_admin_browser(): void
    {
        $this->keys();
        $admin = User::factory()->create();
        $project = Project::factory()->create();
        $own = PushSubscription::factory()->create(['user_id' => $admin->id]);
        $other = PushSubscription::factory()->create();
        $sender = $this->mock(WebPushTransport::class);
        $sender->shouldReceive('available')->andReturn(true);
        $sender->shouldReceive('send')->once()->withArgs(fn ($sub, $payload) => $sub->id === $own->id && $payload['event'] === 'test')->andReturn(['status' => 'sent', 'error_code' => null]);
        $path = '/api/admin/projects/'.$project->uuid.'/notifications/test';
        $this->actingAs($admin);
        $this->postJson($path, ['channel' => 'push', 'endpoint' => $other->endpoint])->assertUnprocessable()->assertJsonPath('code', 'device_not_subscribed');
        $this->postJson($path, ['channel' => 'push', 'endpoint' => $own->endpoint])->assertOk()->assertJsonPath('status', 'sent');
    }
}
