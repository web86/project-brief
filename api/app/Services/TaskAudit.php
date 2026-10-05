<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Http\Request;

class TaskAudit
{
    public const STATUSES = ['new' => 'Новая идея', 'clarification' => 'Нужно уточнить', 'approved' => 'Согласовано', 'in_progress' => 'В работе', 'review' => 'На проверке', 'done' => 'Готово'];

    public static function record(Request $request, Task $task, string $type, string $text, ?string $old = null, ?string $new = null): void
    {
        $client = TaskAccess::isAdmin($request) ? null : $request->attributes->get('project_client');
        if ($client) {
            $text = $client->name.': '.strtr($text, ['Клиент добавил новую идею' => 'добавлена новая идея', 'Клиент добавил комментарий' => 'добавлен комментарий', 'Клиент согласовал задачу' => 'задача согласована']);
        }
        $task->history()->create(['actor_type' => TaskAccess::isAdmin($request) ? 'admin' : 'client', 'actor_user_id' => TaskAccess::isAdmin($request) ? $request->user()->id : null,
            'project_client_id' => $client?->id, 'event_type' => $type, 'old_value' => $old, 'new_value' => $new, 'meta' => ['text' => $text], 'created_at' => now()]);
        $task->touch();
    }
}
