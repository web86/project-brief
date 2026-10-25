<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use HasUuids;

    protected $fillable = ['thumbnail_path', 'project_client_id', 'task_id', 'comment_id', 'uploaded_by_type', 'original_name', 'stored_name', 'mime_type', 'size', 'disk', 'path'];

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

    public function data(string $prefix): array
    {
        $base = '/api/'.$prefix.'/attachments/'.$this->uuid;

        return ['id' => $this->uuid, 'name' => $this->original_name, 'size' => $this->size, 'type' => $this->mime_type,
            'preview' => in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true) ? $base.'/preview' : null,
            'originalUrl' => $base.'/original', 'downloadUrl' => $base.'/download', 'uploadedByName' => $this->projectClient?->name];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
