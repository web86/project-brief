<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAccessToken extends Model
{
    protected $fillable = ['project_id', 'token_hash', 'expires_at', 'revoked_at', 'last_used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture()) && $this->project?->status === 'active';
    }
}
