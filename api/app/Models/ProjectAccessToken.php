<?php

namespace App\Models;

use App\Services\ClientSso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAccessToken extends Model
{
    protected $fillable = ['project_id', 'project_client_id', 'token_hash', 'expires_at', 'revoked_at', 'last_used_at', 'client_sso_handoff_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectClient(): BelongsTo
    {
        return $this->belongsTo(ProjectClient::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            if (! ProjectClient::whereKey($token->project_client_id)->where('project_id', $token->project_id)->exists()) {
                throw new \InvalidArgumentException('New access tokens must belong to a client of the same project.');
            }
        });
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture()) && $this->project?->status === 'active'
            && $this->projectClient?->active && $this->projectClient->project_id === $this->project_id
            && (! $this->client_sso_handoff_id || app(ClientSso::class)->sessionUsable($this));
    }
}
