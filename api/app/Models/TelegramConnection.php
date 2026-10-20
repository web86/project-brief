<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramConnection extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'project_client_id', 'chat_id', 'code_hash', 'code_expires_at', 'connected_at'];

    protected $hidden = ['chat_id', 'code_hash'];

    protected function casts(): array
    {
        return ['chat_id' => 'encrypted', 'code_expires_at' => 'datetime', 'connected_at' => 'datetime'];
    }
}
