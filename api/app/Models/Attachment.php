<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasUuids;

    protected $fillable = ['task_id', 'comment_id', 'uploaded_by_type', 'original_name', 'stored_name', 'mime_type', 'size', 'disk', 'path'];

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

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
