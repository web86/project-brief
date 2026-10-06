<?php

namespace App\Services;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushTransport
{
    public function available(): bool
    {
        $vapid = config('project_notifications.vapid');

        return ! empty($vapid['subject']) && ! empty($vapid['public_key']) && ! empty($vapid['private_key']);
    }

    /** @return array{status:string,error_code:?string} */
    public function send(PushSubscription $subscription, array $payload): array
    {
        if (! PushEndpoint::valid($subscription->endpoint)) {
            return ['status' => 'failed', 'error_code' => 'invalid_endpoint'];
        }
        $keys = config('project_notifications.vapid');
        $sender = new WebPush(['VAPID' => ['subject' => $keys['subject'], 'publicKey' => $keys['public_key'], 'privateKey' => $keys['private_key']]], ['TTL' => 3600], new Client(['handler' => Http::timeout(10)->buildHandlerStack(), 'timeout' => 10, 'connect_timeout' => 5, 'allow_redirects' => false]));
        $report = $sender->sendOneNotification(Subscription::create(['endpoint' => $subscription->endpoint, 'publicKey' => $subscription->public_key, 'authToken' => $subscription->auth_token, 'contentEncoding' => $subscription->content_encoding ?? 'aes128gcm']), json_encode($payload, JSON_THROW_ON_ERROR));
        if ($report->isSubscriptionExpired()) {
            $subscription->delete();

            return ['status' => 'skipped', 'error_code' => 'subscription_expired'];
        }
        if (! $report->isSuccess()) {
            return ['status' => 'failed', 'error_code' => 'push_provider_failed'];
        }
        $subscription->update(['last_used_at' => now()]);

        return ['status' => 'sent', 'error_code' => null];
    }
}
