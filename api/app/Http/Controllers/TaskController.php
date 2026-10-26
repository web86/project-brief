<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Services\AttachmentStorage;
use App\Services\ClientTaskDeletion;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
use App\Services\TaskAccess;
use App\Services\TaskAudit;
use App\Services\TaskCreation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request, ?Project $project = null): AnonymousResourceCollection
    {
        return TaskResource::collection(TaskAccess::project($request, $project)->tasks()->with(['projectSection', 'comments.projectClient', 'comments.authorUser', 'attachments.projectClient', 'history.projectClient'])->orderBy('project_section_id')->orderBy('position')->orderBy('id')->get());
    }

    public function store(TaskRequest $request, ?Project $project = null): TaskResource
    {
        $task = AttachmentStorage::atomic(function (array &$paths) use ($request, $project) {
            $owner = TaskAccess::project($request, $project);
            ProjectStructure::lock($owner);
            $sectionName = $request->validated('section') ?? $request->validated('location');
            $this->authorizeSectionCreation($request, $owner, $sectionName);
            $task = app(TaskCreation::class)->create($request, $owner, $request->taskData(), $sectionName);

            AttachmentStorage::storeFiles($request, $task, $paths);

            return $task;
        });

        return new TaskResource($task->refresh()->load(['projectSection', 'comments.projectClient', 'comments.authorUser', 'attachments.projectClient', 'history.projectClient']));
    }

    public function show(Request $request, string $task): TaskResource
    {
        return new TaskResource(TaskAccess::task($request, $task)->load(['projectSection', 'comments.projectClient', 'comments.authorUser', 'attachments.projectClient', 'history.projectClient']));
    }

    public function update(TaskRequest $request, string $task): TaskResource
    {
        $owner = TaskAccess::task($request, $task)->project;
        DB::transaction(function () use ($request, $task, $owner) {
            ProjectStructure::lock($owner);
            $model = TaskAccess::task($request, $task, true);
            abort_unless(TaskAccess::isAdmin($request) || $model->isClientEditable(), 403, 'Эта идея уже в работе. Обсудите изменения в комментариях.');
            $name = $request->validated('section') ?? (! TaskAccess::isAdmin($request) ? $request->validated('location') : null);
            $sectionChanged = false;
            if ($name !== null) {
                $this->authorizeSectionCreation($request, $owner, $name);
                $section = ProjectStructure::namedSection($owner, $name);
                if ($section->id !== $model->project_section_id) {
                    $model->forceFill(['project_section_id' => $section->id, 'position' => ($section->tasks()->max('position') ?? 0) + 1]);
                    $sectionChanged = true;
                }
            }
            $model->fill($request->taskData());
            $dirty = $model->getDirty();
            $original = $model->getRawOriginal();
            $model->save();
            foreach (['status', 'estimate_hours', 'price', 'developer_notes'] as $field) {
                if (! array_key_exists($field, $dirty)) {
                    continue;
                }
                $old = $original[$field];
                $new = $dirty[$field];
                if (in_array($field, ['estimate_hours', 'price'])) {
                    $old = $old === null ? null : (string) (float) $old;
                    $new = $new === null ? null : (string) (float) $new;
                }
                $type = match ($field) {
                    'estimate_hours' => 'estimate',default => $field
                };
                $text = match ($field) {
                    'status' => 'Статус изменён: '.TaskAudit::STATUSES[$old].' → '.TaskAudit::STATUSES[$new],
                    'estimate_hours' => 'Разработчик обновил оценку в часах','price' => 'Разработчик обновил стоимость',default => 'Разработчик обновил технические заметки'
                };
                TaskAudit::record($request, $model, $type, $text, $field === 'developer_notes' ? null : (is_null($old) ? null : (string) $old), $field === 'developer_notes' ? null : (is_null($new) ? null : (string) $new));
            }
            if ($sectionChanged) {
                ProjectStructure::normalizeProject($owner);
            }
            if ($sectionChanged || array_intersect(array_keys($dirty), ['title', 'location', 'section', 'description', 'expected_result', 'priority'])) {
                TaskAudit::record($request, $model, 'updated', 'Описание идеи обновлено');
            }
            $model->save();
        });

        return $this->show($request, $task);
    }

    public function destroy(Request $request, string $task): JsonResponse
    {
        ClientTaskDeletion::delete($request, $task);

        return response()->json(['deleted' => true]);
    }

    private function authorizeSectionCreation(Request $request, Project $project, string $name): void
    {
        if (! TaskAccess::isAdmin($request)) {
            return;
        }
        $name = trim($name);
        $existing = $project->briefSections()->where('name', $name)->get()->contains(fn ($section) => trim($section->name) === $name);
        if (! $existing) {
            if (trim($name) === 'Общее' && ! $project->briefSections()->exists()) {
                return;
            }
            ProjectPermissions::authorize($request, $project, 'manage_structure');
        }
    }

    public function approve(Request $request, string $task): TaskResource
    {
        abort_if(count($request->all()) > 0, 422, 'Согласование не принимает дополнительные поля.');
        DB::transaction(function () use ($request, $task) {
            $model = TaskAccess::task($request, $task, true);
            if (! $model->client_approved) {
                $model->update(['client_approved' => true, 'client_approved_at' => now()]);
                TaskAudit::record($request, $model, 'approved', 'Клиент согласовал задачу');
            }
        });

        return $this->show($request, $task);
    }
}
