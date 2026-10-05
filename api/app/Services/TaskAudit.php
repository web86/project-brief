<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Http\Request;

class TaskAudit
{
    public const STATUSES = ['new' => 'Новая идея', 'clarification' => 'Нужно уточнить', 'approved' => 'Согласовано', 'in_progress' => 'В работе', 'review' => 'На проверке', 'done' => 'Готово'];

    public static function record(Request $request, Task $task, string $type, string $text, ?string $old = null, ?string $new = null): void
    {
        $task->history()->create(['actor_type' => TaskAccess::isAdmin($request) ? 'admin' : 'client', 'actor_user_id' => TaskAccess::isAdmin($request) ? $request->user()->id : null,
            'event_type' => $type, 'old_value' => $old, 'new_value' => $new, 'meta' => ['text' => $text], 'created_at' => now()]);
        $task->touch();
    }
}
