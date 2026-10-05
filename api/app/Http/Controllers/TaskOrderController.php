<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Services\ProjectStructure;
use Illuminate\Http\Request;

class TaskOrderController extends Controller
{
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
