<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskCreation
{
    /** Caller owns authorization and the transaction; all entry points share ordering and audit creation. */
    public function create(Request $request, Project $project, array $data, string $sectionName): Task
    {
        ProjectStructure::lock($project);
        $task = $project->tasks()->create($data);
        ProjectStructure::assignNewTask($project, $task, $sectionName);
        if ($request->attributes->has('integration_token')) {
            $task->history()->create(['actor_type' => 'admin', 'event_type' => 'created', 'meta' => ['text' => 'Integration added an idea', 'origin' => 'api', 'integrationId' => $request->attributes->get('integration_token')->uuid], 'created_at' => now()]);
            app(WebhookEvents::class)->task($task, 'created');
        } else {
            TaskAudit::record($request, $task, 'created', (TaskAccess::isAdmin($request) ? 'Разработчик' : 'Клиент').' добавил новую идею');
        }

        return $task;
    }
}
