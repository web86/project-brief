<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskHistory extends Model
{
    protected $table = 'task_history';

    public $timestamps = false;

    protected $fillable = ['project_client_id', 'task_id', 'actor_type', 'actor_user_id', 'event_type', 'old_value', 'new_value', 'meta', 'created_at'];

    public function projectClient(): BelongsTo
    {
        return $this->belongsTo(ProjectClient::class);
    }

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
