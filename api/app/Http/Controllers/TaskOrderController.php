<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Services\ProjectStructure;
use App\Services\TaskAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskOrderController extends Controller
{
    public function place(Request $request, string $task): AnonymousResourceCollection
    {
        $data = $request->validate([
            'sectionId' => ['required', 'uuid'],
            'beforeTaskId' => ['nullable', 'uuid', 'prohibits:afterTaskId'],
            'afterTaskId' => ['nullable', 'uuid', 'prohibits:beforeTaskId'],
        ]);

        $model = ProjectStructure::placeTask($request, $task, $data['sectionId'], $data['beforeTaskId'] ?? null, $data['afterTaskId'] ?? null);

        return TaskResource::collection(TaskAccess::query($request)->where('project_id', $model->project_id)->with(['projectSection', 'comments.projectClient', 'attachments.projectClient', 'history.projectClient'])->orderBy('project_section_id')->orderBy('position')->orderBy('id')->get());
    }

    public function move(Request $request, string $task): TaskResource
    {
        $data = $request->validate(['sectionId' => ['required', 'uuid']]);

        return new TaskResource(ProjectStructure::moveTask($request, $task, $data['sectionId'])->load(['projectSection', 'comments.projectClient', 'attachments', 'history.projectClient']));
    }

    public function reorder(Request $request, string $task): TaskResource
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);

        return new TaskResource(ProjectStructure::reorderTask($request, $task, $data['direction'])->load(['projectSection', 'comments.projectClient', 'attachments', 'history.projectClient']));
    }
}
