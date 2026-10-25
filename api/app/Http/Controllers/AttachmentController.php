<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachmentRequest;
use App\Http\Resources\TaskResource;
use App\Models\Attachment;
use App\Services\AttachmentStorage;
use App\Services\ProjectAccess;
use App\Services\TaskAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(AttachmentRequest $request, string $task): TaskResource
    {
        $model = AttachmentStorage::atomic(function (array &$paths) use ($request, $task) {
            $model = TaskAccess::task($request, $task, true);
            abort_unless(TaskAccess::isAdmin($request) || $model->isClientEditable(), 403);
            AttachmentStorage::storeFiles($request, $model, $paths);

            return $model;
        });

        return new TaskResource($model->load(['projectSection', 'comments.projectClient', 'attachments.projectClient', 'history.projectClient']));
    }

    public function download(Request $request, string $attachment): StreamedResponse
    {
        return $this->file($request, $attachment, 'download');
    }

    public function preview(Request $request, string $attachment): StreamedResponse
    {
        return $this->file($request, $attachment, 'preview');
    }

    public function original(Request $request, string $attachment): StreamedResponse
    {
        return $this->file($request, $attachment, 'original');
    }

    private function file(Request $request, string $uuid, string $mode): StreamedResponse
    {
        $projects = TaskAccess::isAdmin($request) ? ProjectAccess::query($request)->pluck('projects.id') : [TaskAccess::project($request)->id];
        $file = Attachment::where('uuid', $uuid)->where(function ($query) use ($request, $projects) {
            $query->whereIn('task_id', TaskAccess::query($request)->select('tasks.id'))
                ->orWhere(fn ($general) => $general->whereNull('task_id')->whereHas('comment', fn ($comment) => $comment->whereNull('task_id')->whereIn('project_id', $projects)));
        })->firstOrFail();
        if ($file->comment_id && $file->task_id) {
            abort_unless($file->comment && $file->comment->task_id === $file->task_id && $file->comment->project_id === $file->task->project_id, 404);
        }
        abort_if($mode !== 'download' && ! in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp']), 404);
        $thumbnail = $mode === 'preview' && $file->thumbnail_path;
        $path = $thumbnail ? $file->thumbnail_path : $file->path;
        abort_unless(Storage::disk($file->disk)->exists($path), 404);
        $headers = ['Content-Type' => $thumbnail ? 'image/jpeg' : $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer'];

        return $mode === 'download' ? Storage::disk($file->disk)->download($path, $file->original_name, $headers) : Storage::disk($file->disk)->response($path, $file->original_name, $headers, 'inline');
    }
}
