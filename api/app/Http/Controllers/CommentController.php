<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Http\Resources\TaskResource;
use App\Services\TaskAccess;
use App\Services\TaskAudit;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function store(CommentRequest $request, string $task): TaskResource
    {
        $model = DB::transaction(function () use ($request, $task) {
            $model = TaskAccess::task($request, $task, true);
            $admin = TaskAccess::isAdmin($request);
            $comment = $model->comments()->create(['text' => $request->validated('text'), 'author_type' => $admin ? 'admin' : 'client', 'author_user_id' => $admin ? $request->user()->id : null, 'project_client_id' => $admin ? null : $request->attributes->get('project_client')->id]);
            TaskAudit::record($request, $model, 'comment', ($admin ? 'Разработчик' : 'Клиент').' добавил комментарий', metadata: ['commentId' => $comment->id]);

            return $model;
        });

        return new TaskResource($model->load(['projectSection', 'comments.projectClient', 'attachments.projectClient', 'history.projectClient']));
    }
}
