<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
use App\Services\TaskAccess;
use App\Services\TaskAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskTrashController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $project = TaskAccess::project($request, $project);

        return TaskResource::collection($project->tasks()->onlyTrashed()->with(['projectSection', 'comments.projectClient', 'attachments.projectClient', 'history.projectClient'])->orderByDesc('deleted_at')->get());
    }

    public function restore(Request $request, string $task): TaskResource
    {
        $project = TaskAccess::query($request)->withTrashed()->where('uuid', $task)->firstOrFail()->project;
        ProjectPermissions::authorize($request, $project, 'manage_trash');
        $model = DB::transaction(function () use ($request, $task, $project) {
            ProjectStructure::lock($project);
            $model = TaskAccess::query($request)->withTrashed()->where('uuid', $task)->lockForUpdate()->firstOrFail();
            abort_unless($model->trashed(), 409, 'The idea has already been restored.');
            $section = $model->projectSection ?? ProjectStructure::defaultSection($project);
            $items = $section->tasks()->orderBy('position')->orderBy('id')->get()->all();
            $index = max(0, min(($model->position ?? 1) - 1, count($items)));
            $model->forceFill(['project_section_id' => $section->id]);
            $model->restore();
            array_splice($items, $index, 0, [$model]);
            ProjectStructure::normalize($items);
            TaskAudit::record($request, $model, 'restored', 'Идея восстановлена из корзины');

            return $model->refresh();
        }, 3);

        return new TaskResource($model->load(['projectSection', 'comments.projectClient', 'attachments.projectClient', 'history.projectClient']));
    }
}
