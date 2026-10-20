<?php

namespace App\Services;

use App\Models\ProjectClient;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TelegramLinking
{
    public static function query(User|ProjectClient $recipient): Builder
    {
        return TelegramConnection::where($recipient instanceof User ? 'user_id' : 'project_client_id', $recipient->id);
    }

    public function issue(User|ProjectClient $recipient): array
    {
        $code = bin2hex(random_bytes(32));
        $expires = now()->addMinutes(10);
        // Serialize issuance and redemption for the same identity, including the first connection.
        DB::transaction(function () use ($recipient, $code, $expires): void {
            $recipient->newQuery()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            $key = $recipient instanceof User ? 'user_id' : 'project_client_id';
            TelegramConnection::updateOrCreate([$key => $recipient->id], ['code_hash' => hash('sha256', $code), 'code_expires_at' => $expires]);
        });

        return ['url' => 'https://t.me/'.config('telegram.bot_username').'?start='.$code, 'expiresAt' => $expires->toISOString()];
    }

    public function redeem(array $update): ?TelegramConnection
    {
        $message = $update['message'] ?? [];
        $text = $message['text'] ?? '';
        $chat = $message['chat'] ?? [];
        if (! is_string($text) || ! preg_match('/^\/start(?:@[a-zA-Z0-9_]+)? ([a-f0-9]{64})$/D', $text, $match) || ($chat['type'] ?? '') !== 'private' || ! is_int($chat['id'] ?? null) || $chat['id'] <= 0 || ($message['from']['id'] ?? null) !== $chat['id']) {
            return null;
        }
        $hash = hash('sha256', $match[1]);
        $candidate = TelegramConnection::where('code_hash', $hash)->first();
        if (! $candidate) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $hash, $chat, $update): ?TelegramConnection {
            $recipient = $candidate->user_id ? User::find($candidate->user_id) : ProjectClient::find($candidate->project_client_id);
            if (! $recipient) {
                return null;
            }
            $recipient = $recipient->newQuery()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            $connection = self::query($recipient)->where('code_hash', $hash)->lockForUpdate()->first();
            if (! $connection || ! $connection->code_expires_at?->isFuture() || ! $this->eligible($recipient)) {
                return null;
            }
            if (! DB::table('telegram_updates')->insertOrIgnore(['update_id' => $update['update_id'], 'created_at' => now()])) {
                return null;
            }
            $connection->update(['chat_id' => (string) $chat['id'], 'connected_at' => now(), 'code_hash' => null, 'code_expires_at' => null]);

            return $connection;
        });
    }

    private function eligible(User|ProjectClient $recipient): bool
    {
        if ($recipient instanceof User) {
            return $recipient->workspaceMembers()->active()->exists();
        }

        return $recipient->active && $recipient->project?->status === 'active' && $recipient->accessTokens()->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    public function disconnect(User|ProjectClient $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            $recipient->newQuery()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            self::query($recipient)->update(['chat_id' => null, 'connected_at' => null, 'code_hash' => null, 'code_expires_at' => null]);
        });
    }
}
