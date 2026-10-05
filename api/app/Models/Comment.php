<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasUuids;

    protected $fillable = ['task_id', 'author_type', 'author_user_id', 'text'];

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

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
