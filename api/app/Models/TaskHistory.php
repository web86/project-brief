<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskHistory extends Model
{
    protected $table = 'task_history';

    public $timestamps = false;

    protected $fillable = ['task_id', 'actor_type', 'actor_user_id', 'event_type', 'old_value', 'new_value', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
