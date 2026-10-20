<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramConnection extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'project_client_id', 'chat_id', 'code_hash', 'code_expires_at', 'connected_at'];

    protected $hidden = ['chat_id', 'chat_hash', 'code_hash'];

    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            if ($connection->isDirty('chat_id')) {
                $connection->chat_hash = $connection->chat_id ? self::chatHash($connection->chat_id) : null;
            }
        });
    }

    public static function chatHash(string $chat): string
    {
        return hash_hmac('sha256', $chat, (string) config('app.key'));
    }

    protected function casts(): array
    {
        return ['chat_id' => 'encrypted', 'code_expires_at' => 'datetime', 'connected_at' => 'datetime'];
    }
}
