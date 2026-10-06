<?php

namespace App\Services;

use App\Events\ProjectNotificationRequested;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskAudit
{
    public const STATUSES = ['new' => 'Новая идея', 'clarification' => 'Нужно уточнить', 'approved' => 'Согласовано', 'in_progress' => 'В работе', 'review' => 'На проверке', 'done' => 'Готово'];

    public static function record(Request $request, Task $task, string $type, string $text, ?string $old = null, ?string $new = null, array $metadata = []): void
    {
        if (in_array($type, ['section_moved', 'reordered']) && $old !== null && $new !== null) {
            $text .= ': '.$old.' → '.$new;
        }
        $client = TaskAccess::isAdmin($request) ? null : $request->attributes->get('project_client');
        if ($client) {
            $text = $client->name.': '.strtr($text, ['Клиент добавил новую идею' => 'добавлена новая идея', 'Клиент добавил комментарий' => 'добавлен комментарий', 'Клиент согласовал задачу' => 'задача согласована']);
        }
        $history = $task->history()->create(['actor_type' => TaskAccess::isAdmin($request) ? 'admin' : 'client', 'actor_user_id' => TaskAccess::isAdmin($request) ? $request->user()->id : null,
            'project_client_id' => $client?->id, 'event_type' => $type, 'old_value' => $old, 'new_value' => $new, 'meta' => ['text' => $text, ...$metadata], 'created_at' => now()]);
        $task->touch();
        $actor = TaskAccess::isAdmin($request) ? 'admin' : 'client';
        $event = NotificationEvents::fromHistory($actor, $type, $old, $new);
        if ($event) {
            event(new ProjectNotificationRequested($history->id, $event, $task->id, $actor,
                $actor === 'admin' ? $request->user()->id : $client?->id,
                $actor === 'admin' ? $request->user()->name : ($client?->name ?? '')));
        }
    }
}
