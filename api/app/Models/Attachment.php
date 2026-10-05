<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use HasUuids;

    protected $fillable = ['project_client_id', 'task_id', 'comment_id', 'uploaded_by_type', 'original_name', 'stored_name', 'mime_type', 'size', 'disk', 'path'];

    public function projectClient(): BelongsTo
    {
        return $this->belongsTo(ProjectClient::class);
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
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
