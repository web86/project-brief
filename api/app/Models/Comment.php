<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    use HasUuids;

    protected $fillable = ['project_client_id', 'task_id', 'author_type', 'author_user_id', 'text'];

    public function projectClient(): BelongsTo
    {
        return $this->belongsTo(ProjectClient::class);
    }

    protected function casts(): array
    {
        return [];
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
