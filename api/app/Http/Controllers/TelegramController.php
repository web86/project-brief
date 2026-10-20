<?php

namespace App\Http\Controllers;

use App\Models\ProjectClient;
use App\Models\User;
use App\Services\NotificationPayload;
use App\Services\TaskAccess;
use App\Services\TelegramLinking;
use App\Services\TelegramTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function show(Request $request, TelegramTransport $transport): JsonResponse
    {
        $connection = TelegramLinking::query(TaskAccess::recipient($request))->first();

        return response()->json(['available' => $transport->available() && $this->linkConfigured(), 'connected' => (bool) $connection?->chat_id, 'connectedAt' => $connection?->connected_at?->toISOString()])->header('Cache-Control', 'no-store');
    }

    private function linkConfigured(): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{5,32}$/D', (string) config('telegram.bot_username')) && strlen((string) config('telegram.webhook_secret')) >= 32;
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

    public function webhook(Request $request, TelegramLinking $linking, TelegramTransport $transport): JsonResponse
    {
        $secret = (string) config('telegram.webhook_secret');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($request->getContent()) > 16384, 413);
        $update = $request->validate(['update_id' => ['required', 'integer', 'min:0'], 'message' => ['sometimes', 'array']]);
        $connection = $linking->redeem($update);
        if ($connection) {
            $recipient = $connection->user_id ? User::find($connection->user_id) : ProjectClient::find($connection->project_client_id);
            $transport->send($connection, ['text' => $recipient?->preferred_locale === 'ru' ? '✅ Telegram подключён. Уведомления будут приходить сюда.' : '✅ Telegram connected. Notifications will arrive here.']);
        }

        return response()->json(['ok' => true]);
    }
}
