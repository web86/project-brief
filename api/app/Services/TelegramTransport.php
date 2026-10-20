<?php

namespace App\Services;

use App\Models\TelegramConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramTransport
{
    public function available(): bool
    {
        if (config('telegram.transport') === 'direct') {
            return (bool) preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/D', (string) config('telegram.bot_token'));
        }

        return config('telegram.transport') === 'deno' && $this->bridgeUrl() !== null && strlen((string) config('telegram.bridge_secret')) >= 32;
    }

    private function bridgeUrl(): ?string
    {
        $url = (string) config('telegram.bridge_url');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.deno\.(?:net|dev)$/D', $parts['host'] ?? '') || ($parts['path'] ?? '') !== '/telegram/send' || array_intersect(array_keys($parts), ['user', 'pass', 'port', 'query', 'fragment'])) {
            return null;
        }

        return $url;
    }

    public function send(TelegramConnection $connection, array $payload): array
    {
        if (! $connection->chat_id) {
            return ['status' => 'skipped', 'error_code' => 'telegram_not_connected'];
        }
        $chat = $connection->chat_id;
        $result = $this->request('sendMessage', [...$payload, 'chat_id' => $chat]);
        if ($result['error_code'] === 'telegram_blocked') {
            // Never disconnect a newer binding created while the network request was in flight.
            TelegramConnection::whereKey($connection->id)->where('chat_id', $connection->getRawOriginal('chat_id'))
                ->update(['chat_id' => null, 'chat_hash' => null, 'connected_at' => null]);
        }

        return $result;
    }

    public function diagnose(): array
    {
        return $this->request('getMe', []);
    }

    /** Only called internally after authentication and an atomic receipt claim. */
    public function replyToStart(array $update, string $text): array
    {
        if (! TelegramWebhook::isPrivateStart($update)) {
            return ['status' => 'skipped', 'error_code' => 'telegram_invalid_start'];
        }

        return $this->request('sendMessage', ['chat_id' => (string) $update['message']['chat']['id'], 'text' => $text]);
    }

    private function request(string $method, array $payload): array
    {
        if (! $this->available()) {
            return ['status' => 'skipped', 'error_code' => 'telegram_unconfigured'];
        }
        try {
            $http = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->acceptJson();
            if (config('telegram.transport') === 'direct') {
                $url = 'https://api.telegram.org/bot'.config('telegram.bot_token').'/'.$method;
                $response = $method === 'getMe' ? $http->get($url) : $http->post($url, $payload);
            } else {
                $response = $http->withToken(config('telegram.bridge_secret'))->post($this->bridgeUrl(), ['method' => $method, 'payload' => $payload ?: (object) []]);
            }
            if ($response->successful() && $response->json('ok') === true) {
                return ['status' => 'sent', 'error_code' => null];
            }
            $code = (int) ($response->json('error_code') ?: $response->status());

            return ['status' => 'failed', 'error_code' => match ($code) {
                429 => 'telegram_rate_limited', 403 => 'telegram_blocked', 401 => 'telegram_unauthorized',
                400 => 'telegram_chat_unavailable', default => 'telegram_unavailable',
            }];
        } catch (Throwable) {
            // Exceptions can include the bot token in the URL. Never log or return them.
            return ['status' => 'failed', 'error_code' => 'telegram_unavailable'];
        }
    }

    public static function message(array $payload): array
    {
        $escape = fn (string $text, int $limit): string => htmlspecialchars(NotificationPayload::excerpt($text, $limit), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $icon = str_contains($payload['event'], 'comment') ? '💬' : (str_contains($payload['event'], 'done') ? '✅' : '🔔');
        $lines = [$icon.' <b>'.$escape($payload['heading'], 100).'</b>', $escape($payload['project'], 150)];
        if ($payload['task_title'] !== '') {
            $lines[] = $escape('#'.$payload['number'].' '.$payload['task_title'], 220);
        }
        if ($payload['actor'] !== '') {
            $lines[] = $escape($payload['actor'], 80);
        }
        if ($payload['message']) {
            $lines[] = $escape($payload['message'], 1200);
        }

        return ['text' => implode("\n", $lines), 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [[['text' => $payload['open'], 'url' => $payload['url']]]]]];
    }
}
