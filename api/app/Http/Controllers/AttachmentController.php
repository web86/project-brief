<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachmentRequest;
use App\Http\Resources\TaskResource;
use App\Models\Attachment;
use App\Services\AttachmentStorage;
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
            AttachmentStorage::storeFiles($request, $model, $paths);

            return $model;
        });

        return new TaskResource($model->load(['projectSection', 'comments.projectClient', 'attachments.projectClient', 'history.projectClient']));
    }

    public function download(Request $request, string $attachment): StreamedResponse
    {
        return $this->file($request, $attachment, false);
    }

    public function preview(Request $request, string $attachment): StreamedResponse
    {
        return $this->file($request, $attachment, true);
    }

    private function file(Request $request, string $uuid, bool $preview): StreamedResponse
    {
        $query = Attachment::where('uuid', $uuid);
        if (! TaskAccess::isAdmin($request)) {
            $query->whereHas('task', fn ($q) => $q->where('project_id', TaskAccess::project($request)->id));
        }
        $file = $query->firstOrFail();
        abort_if($preview && ! in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp']), 404);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);
        $headers = ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer'];

        return $preview ? Storage::disk($file->disk)->response($file->path, $file->original_name, $headers, 'inline') : Storage::disk($file->disk)->download($file->path, $file->original_name, $headers);
    }
}
