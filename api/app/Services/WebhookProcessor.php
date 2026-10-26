<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebhookProcessor
{
    public const MAX_ATTEMPTS = 6;

    public function process(int $limit = 20, int $seconds = 45, ?Closure $report = null): int
    {
        $owner = bin2hex(random_bytes(16));
        DB::table('integration_locks')->insertOrIgnore(['name' => 'webhooks', 'owner' => '', 'expires_at' => now()->subDay()]);
        if (! DB::table('integration_locks')->where('name', 'webhooks')->where('expires_at', '<=', now())->update(['owner' => $owner, 'expires_at' => now()->addSeconds(90)])) {
            return 0;
        }
        $deadline = microtime(true) + min(45, max(1, $seconds));
        $count = 0;
        try {
            while ($count < min(50, max(1, $limit)) && microtime(true) < $deadline) {
                $delivery = DB::table('webhook_deliveries')->whereIn('status', ['pending', 'processing'])->where('available_at', '<=', now())->orderBy('id')->first();
                if (! $delivery) {
                    break;
                }
                $outcome = $this->deliver($delivery->id, $owner);
                if ($outcome === false) {
                    break;
                }
                if (is_array($outcome) && $report) {
                    $report($outcome);
                }
                $count++;
            }
        } finally {
            DB::table('integration_locks')->where('name', 'webhooks')->where('owner', $owner)->update(['expires_at' => now()]);
        }

        return $count;
    }

    private function deliver(int $id, string $owner): bool|array
    {
        $delivery = DB::table('webhook_deliveries')->find($id);
        if (! $delivery) {
            return true;
        }

        return DB::transaction(function () use ($delivery, $owner): bool|array {
            $lease = DB::table('integration_locks')->where('name', 'webhooks')->lockForUpdate()->first();
            if (! $lease || $lease->owner !== $owner) {
                return false;
            }
            DB::table('integration_locks')->where('name', 'webhooks')->update(['expires_at' => now()->addSeconds(90)]);
            $endpoint = DB::table('webhook_endpoints')->where('id', $delivery->webhook_endpoint_id)->lockForUpdate()->first();
            if (! $endpoint) {
                return true;
            }
            if (! $endpoint->enabled || $endpoint->secret_version !== $delivery->secret_version) {
                DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'cancelled', 'error_code' => 'endpoint_changed', 'updated_at' => now()]);

                return true;
            }
            $event = DB::table('webhook_events')->find($delivery->webhook_event_id);
            $payload = json_decode($event->body, true, 512, JSON_THROW_ON_ERROR);
            if ($event->workspace_id !== $endpoint->workspace_id || ! app(WebhookProjectFilter::class)->matches($endpoint, $event->type, $payload['projectId'] ?? null)) {
                DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'cancelled', 'error_code' => 'project_filtered', 'updated_at' => now()]);

                return true;
            }
            $attempt = $delivery->attempts + 1;
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => 'processing', 'available_at' => now()->addSeconds(90), 'attempts' => $attempt]);
            $started = microtime(true);
            $httpStatus = null;
            $code = null;
            $retry = false;
            try {
                $target = app(WebhookDestination::class)->resolve($endpoint->url);
                if (! extension_loaded('curl')) {
                    throw new \RuntimeException('curl_required');
                }
                $timestamp = (string) now()->timestamp;
                $signature = hash_hmac('sha256', $timestamp.'.'.$event->body, Crypt::decryptString($endpoint->secret));
                $response = Http::withOptions(['allow_redirects' => false, 'verify' => true, 'decode_content' => false, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$target['host'].':443:'.(str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'])]], 'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 16384) {
                        throw new \RuntimeException('response_too_large');
                    }
                }, 'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 16384) {
                        throw new \RuntimeException('response_too_large');
                    }
                }])->connectTimeout(3)->timeout(8)->withHeaders(['Accept-Encoding' => 'identity', 'X-ProjectBrief-Event' => $event->type, 'X-ProjectBrief-Delivery' => $delivery->uuid, 'X-ProjectBrief-Timestamp' => $timestamp, 'X-ProjectBrief-Signature' => 'sha256='.$signature])->withBody($event->body, 'application/json')->post($endpoint->url);
                $httpStatus = $response->status();
                $retry = $httpStatus === 429 || $httpStatus >= 500;
                $code = $response->successful() ? null : ($retry ? 'remote_transient' : 'remote_rejected');
            } catch (ValidationException) {
                $code = 'unsafe_destination';
            } catch (Throwable) {
                $code = 'transport_error';
                $retry = true;
            }
            $status = $code === null ? 'succeeded' : ($retry && $attempt < self::MAX_ATTEMPTS ? 'pending' : 'failed');
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['status' => $status, 'http_status' => $httpStatus, 'error_code' => $code, 'available_at' => now()->addSeconds(min(3600, 30 * (2 ** ($attempt - 1)))), 'updated_at' => now()]);
            DB::table('webhook_attempts')->insert(['webhook_delivery_id' => $delivery->id, 'attempt' => $attempt, 'status' => $status, 'http_status' => $httpStatus, 'error_code' => $code, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'created_at' => now()]);

            return ['delivery' => $delivery->uuid, 'status' => $status, 'attempt' => $attempt, 'httpStatus' => $httpStatus, 'code' => $code];
        });
    }
}
