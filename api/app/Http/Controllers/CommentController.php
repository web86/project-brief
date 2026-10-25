<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Http\Resources\TaskResource;
use App\Services\CommentSubmission;
use App\Services\TaskAccess;

class CommentController extends Controller
{
    public function store(CommentRequest $request, string $task): TaskResource
    {
        $model = TaskAccess::task($request, $task);
        CommentSubmission::store($request, $model->project, $model);

        return new TaskResource($model->load(['projectSection', 'comments.projectClient', 'comments.authorUser', 'comments.attachments', 'attachments.projectClient', 'history.projectClient']));
    }
}
