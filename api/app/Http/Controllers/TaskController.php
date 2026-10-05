<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Services\AttachmentStorage;
use App\Services\TaskAccess;
use App\Services\TaskAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request, ?Project $project = null): AnonymousResourceCollection
    {
        return TaskResource::collection(TaskAccess::project($request, $project)->tasks()->with(['comments', 'attachments', 'history'])->orderByDesc('id')->get());
    }

    public function store(TaskRequest $request, ?Project $project = null): TaskResource
    {
        $task = AttachmentStorage::atomic(function (array &$paths) use ($request, $project) {
            $task = TaskAccess::project($request, $project)->tasks()->create($request->taskData());
            TaskAudit::record($request, $task, 'created', (TaskAccess::isAdmin($request) ? 'Разработчик' : 'Клиент').' добавил новую идею');

            AttachmentStorage::storeFiles($request, $task, $paths);

            return $task;
        });

        return new TaskResource($task->refresh()->load(['comments', 'attachments', 'history']));
    }

    public function show(Request $request, string $task): TaskResource
    {
        return new TaskResource(TaskAccess::task($request, $task)->load(['comments', 'attachments', 'history']));
    }

    public function update(TaskRequest $request, string $task): TaskResource
    {
        DB::transaction(function () use ($request, $task) {
            $model = TaskAccess::task($request, $task, true);
            abort_unless(TaskAccess::isAdmin($request) || in_array($model->status, ['new', 'clarification']), 403, 'Эта идея уже в работе. Обсудите изменения в комментариях.');
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
                $type = match ($field) {
                    'estimate_hours' => 'estimate',default => $field
                };
                $text = match ($field) {
                    'status' => 'Статус изменён: '.TaskAudit::STATUSES[$old].' → '.TaskAudit::STATUSES[$new],
                    'estimate_hours' => 'Разработчик обновил оценку в часах','price' => 'Разработчик обновил стоимость',default => 'Разработчик обновил технические заметки'
                };
                TaskAudit::record($request, $model, $type, $text, $field === 'developer_notes' ? null : (is_null($old) ? null : (string) $old), $field === 'developer_notes' ? null : (is_null($new) ? null : (string) $new));
            }
            if (array_intersect(array_keys($dirty), ['title', 'location', 'section', 'description', 'expected_result', 'priority'])) {
                TaskAudit::record($request, $model, 'updated', 'Описание идеи обновлено');
            }
            $model->save();
        });

        return $this->show($request, $task);
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
