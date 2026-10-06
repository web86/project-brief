<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'user_agent', 'user_id', 'project_client_id'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'public_key' => 'encrypted', 'auth_token' => 'encrypted', 'last_used_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            if (($subscription->user_id !== null) === ($subscription->project_client_id !== null)) {
                throw new \InvalidArgumentException('A subscription must have exactly one owner.');
            }
            $subscription->endpoint_hash = hash('sha256', $subscription->endpoint);
        });
    }
}
