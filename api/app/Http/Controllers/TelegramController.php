<?php

namespace App\Http\Controllers;

use App\Models\ProjectClient;
use App\Services\NotificationPayload;
use App\Services\TaskAccess;
use App\Services\TelegramLinking;
use App\Services\TelegramTransport;
use App\Services\TelegramWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramController extends Controller
{
    public function show(Request $request, TelegramTransport $transport): JsonResponse
    {
        $connection = TelegramLinking::query(TaskAccess::recipient($request))->first();

        return response()->json(['available' => $transport->available() && $this->linkConfigured(), 'connected' => (bool) $connection?->chat_id, 'connectedAt' => $connection?->connected_at?->toISOString()])->header('Cache-Control', 'no-store');
    }

    private function linkConfigured(): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{5,32}$/D', (string) config('telegram.bot_username')) && TelegramWebhook::configured();
    }

    public function connect(Request $request, TelegramLinking $linking, TelegramTransport $transport): JsonResponse
    {
        abort_if($request->all(), 422);
        abort_unless($transport->available() && $this->linkConfigured(), 422, 'Telegram is not configured.');

        return response()->json($linking->issue(TaskAccess::recipient($request)))->header('Cache-Control', 'no-store');
    }

    public function disconnect(Request $request, TelegramLinking $linking): JsonResponse
    {
        abort_if($request->all(), 422);
        $linking->disconnect(TaskAccess::recipient($request));

        return response()->json(['connected' => false])->header('Cache-Control', 'no-store');
    }

    public function test(Request $request, TelegramTransport $transport): JsonResponse
    {
        abort_if($request->all(), 422);
        $recipient = TaskAccess::recipient($request);
        $connection = TelegramLinking::query($recipient)->first();
        $locale = $recipient->preferred_locale === 'ru' ? 'ru' : 'en';
        $path = $recipient instanceof ProjectClient ? '/project/'.$recipient->project->uuid.'/notifications' : '/admin';
        $payload = NotificationPayload::test($locale, rtrim(config('app.url'), '/').$path);
        $result = $connection ? $transport->send($connection, TelegramTransport::message($payload)) : ['status' => 'skipped', 'error_code' => 'telegram_not_connected'];

        return response()->json(['status' => $result['status'], 'code' => $result['error_code']], $result['status'] === 'sent' ? 200 : 422);
    }

    public function webhook(Request $request, TelegramLinking $linking, TelegramTransport $transport, TelegramWebhook $webhook): JsonResponse
    {
        $forwarded = config('telegram.webhook_transport') === 'deno';
        $secret = (string) config($forwarded ? 'telegram.forward_secret' : 'telegram.webhook_secret');
        $header = $forwarded ? 'X-Telegram-Forward-Secret' : 'X-Telegram-Bot-Api-Secret-Token';
        abort_unless(TelegramWebhook::configured() && hash_equals($secret, (string) $request->header($header)), 403);
        abort_if(strlen($request->getContent()) > 16384, 413);
        $update = $request->validate(['update_id' => ['required', 'integer', 'min:0'], 'message' => ['sometimes', 'array']]);
        try {
            $webhook->process($update, $linking, $transport);
        } catch (Throwable) {
            Log::warning('telegram.webhook_failed', ['error_code' => 'processing_unavailable']);

            return response()->json(['ok' => false], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
