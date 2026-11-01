<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAgentAssignment extends Model
{
    use HasUuids;

    protected $attributes = ['state' => 'pending'];

    protected $fillable = ['workspace_id', 'task_id', 'external_agent_id', 'state', 'revoked_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ExternalAgent::class, 'external_agent_id')->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
