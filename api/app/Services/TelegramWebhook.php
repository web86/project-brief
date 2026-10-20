<?php

namespace App\Services;

use App\Models\ProjectClient;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhook
{
    public static function configured(): bool
    {
        $secret = (string) config('telegram.webhook_secret');
        if (config('telegram.webhook_transport') === 'direct') {
            return (bool) preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret);
        }
        $forward = (string) config('telegram.forward_secret');

        return config('telegram.webhook_transport') === 'deno' && preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $forward)
            && $forward !== $secret && $forward !== (string) config('telegram.bridge_secret');
    }

    public static function isPrivateStart(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! is_array($message['chat'] ?? null) || ! is_array($message['from'] ?? null)) {
            return false;
        }
        $text = $message['text'] ?? null;
        if (! is_string($text) || ! preg_match('/^\/start(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$/Du', $text, $match)) {
            return false;
        }
        if (($match[1] ?? '') !== '' && strcasecmp($match[1], (string) config('telegram.bot_username')) !== 0) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private' && is_int($message['chat']['id'] ?? null)
            && $message['chat']['id'] > 0 && ($message['from']['id'] ?? null) === $message['chat']['id'];
    }

    public function process(array $update, TelegramLinking $linking, TelegramTransport $transport): void
    {
        Log::info('telegram.webhook_received');
        if (! self::isPrivateStart($update)) {
            return;
        }
        Log::info('telegram.start_recognized');
        $reply = DB::transaction(function () use ($update, $linking): ?array {
            if (! DB::table('telegram_updates')->insertOrIgnore(['update_id' => $update['update_id'], 'created_at' => now()])) {
                return null;
            }
            $text = $update['message']['text'];
            $plain = (bool) preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s*$/Du', $text);
            $connection = $plain ? null : $linking->redeem($update);
            $known = $connection ?? TelegramConnection::where('chat_hash', TelegramConnection::chatHash((string) $update['message']['chat']['id']))->latest('connected_at')->latest('id')->first();
            $recipient = $known ? ($known->user_id ? User::find($known->user_id) : ProjectClient::find($known->project_client_id)) : null;
            $locale = $recipient?->preferred_locale ?? config('telegram.default_locale');

            return ['outcome' => $plain ? 'welcome' : ($connection ? 'connected' : 'rejected'), 'locale' => $locale === 'en' ? 'en' : 'ru', 'connection' => $connection];
        });
        if (! $reply) {
            Log::info('telegram.update_duplicate');

            return;
        }
        Log::info('telegram.start_processed', ['outcome' => $reply['outcome']]);
        if ($reply['outcome'] !== 'welcome') {
            Log::info($reply['outcome'] === 'connected' ? 'telegram.code_redeemed' : 'telegram.code_rejected');
        }
        // Symfony flushes the response before Laravel termination under FastCGI.
        app()->terminating(function () use ($update, $reply, $transport): void {
            static $attempted = false;
            if ($attempted) {
                return;
            }
            $attempted = true;
            try {
                $text = self::replyText($reply['outcome'], $reply['locale']);
                $result = $reply['connection'] ? $transport->send($reply['connection'], ['text' => $text]) : $transport->replyToStart($update, $text);
                if ($result['status'] === 'sent') {
                    Log::info('telegram.start_reply_sent', ['outcome' => $reply['outcome']]);
                } else {
                    Log::warning('telegram.start_reply_failed', ['error_code' => $result['error_code']]);
                }
            } catch (Throwable) {
                Log::warning('telegram.start_reply_failed', ['error_code' => 'telegram_unavailable']);
            }
        });
    }

    private static function replyText(string $outcome, string $locale): string
    {
        return match ($outcome) {
            'connected' => $locale === 'ru'
                ? "✅ Telegram успешно подключён к Project Brief!\n\nТеперь уведомления о событиях твоего проекта будут приходить сюда."
                : "✅ Telegram successfully connected to Project Brief!\n\nNotifications about your project will now arrive here.",
            'rejected' => $locale === 'ru'
                ? "❌ Не удалось подключить Telegram.\n\nВозможно, ссылка устарела или уже использована.\n\nВернись в Project Brief → Уведомления и создай новую ссылку подключения."
                : "❌ Could not connect Telegram.\n\nThe link may have expired or already been used.\n\nGo back to Project Brief → Notifications and create a new connection link.",
            default => $locale === 'ru'
                ? "👋 Привет! Я BriefWeb86Bot — бот уведомлений Project Brief.\n\nЧтобы получать уведомления, открой свой проект, перейди в раздел „Уведомления“ и нажми „Подключить Telegram“."
                : "👋 Hi! I'm BriefWeb86Bot, the Project Brief notification bot.\n\nTo receive notifications, open your project, go to Notifications and select Connect Telegram.",
        };
    }
}
