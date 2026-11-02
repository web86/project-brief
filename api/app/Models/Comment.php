<?php

namespace App\Models;

use App\Services\ClientEventVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Comment extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope('public_channel', fn ($query) => ClientEventVisibility::comments($query));
    }

    protected $fillable = ['external_agent_id', 'team_only', 'submission_key', 'submission_fingerprint', 'project_id', 'project_client_id', 'task_id', 'author_type', 'author_user_id', 'text'];

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    public function agentReport(): HasOne
    {
        return $this->hasOne(AgentReport::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ExternalAgent::class, 'external_agent_id')->withTrashed();
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function projectClient(): BelongsTo
    {
        return $this->belongsTo(ProjectClient::class);
    }

    protected function casts(): array
    {
        return ['team_only' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
