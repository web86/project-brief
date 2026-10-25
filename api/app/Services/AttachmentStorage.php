<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Task;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttachmentStorage
{
    public static function atomic(Closure $callback): mixed
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($callback, &$paths) {
                return $callback($paths);
            });
        } catch (Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public static function storeFiles(Request $request, Task $task, array &$paths): void
    {
        $files = $request->file('attachments', []);
        if ($task->attachments()->whereNull('comment_id')->count() + count($files) > 10) {
            throw ValidationException::withMessages(['attachments' => 'К одной идее можно добавить до 10 файлов.']);
        }
        foreach ($files as $file) {
            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs('attachments/'.$task->uuid, $name, 'local');
            if (! $path) {
                throw new \RuntimeException('Unable to store attachment');
            }
            $paths[] = $path;
            $original = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $original = preg_replace('/[\x00-\x1F\x7F]/u', '', $original);
            $original = mb_substr($original ?: 'file', 0, 200);
            $task->attachments()->create(['original_name' => $original, 'stored_name' => $name, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                'disk' => 'local', 'path' => $path, 'uploaded_by_type' => TaskAccess::isAdmin($request) ? 'admin' : 'client', 'project_client_id' => TaskAccess::isAdmin($request) ? null : $request->attributes->get('project_client')->id]);
            TaskAudit::record($request, $task, 'attachment', 'Добавлен файл: '.$original, null, null, ['fileName' => $original]);
        }
    }

    public static function storeCommentFiles(Request $request, Comment $comment, ?Task $task, array &$paths): void
    {
        abort_if($task && ($task->project_id !== $comment->project_id || $comment->task_id !== $task->id), 422);
        foreach ($request->file('attachments', []) as $file) {
            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
            $directory = 'attachments/comments/'.$comment->uuid;
            $path = $directory.'/'.$name;
            $paths[] = $path;
            if (! $file->storeAs($directory, $name, 'local')) {
                throw new \RuntimeException('Unable to store attachment');
            }
            $thumbnail = null;
            if (in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $thumbnail = $directory.'/thumbnails/'.$name.'.jpg';
                $paths[] = $thumbnail;
                self::thumbnail($file->getPathname(), $thumbnail);
            }
            $original = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $original = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $original) ?: 'file', 0, 200);
            $comment->attachments()->create(['task_id' => $task?->id, 'original_name' => $original, 'stored_name' => $name,
                'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'disk' => 'local', 'path' => $path, 'thumbnail_path' => $thumbnail,
                'uploaded_by_type' => TaskAccess::isAdmin($request) ? 'admin' : 'client',
                'project_client_id' => TaskAccess::isAdmin($request) ? null : $request->attributes->get('project_client')->id]);
        }
    }

    private static function thumbnail(string $source, string $path): void
    {
        $image = @imagecreatefromstring(file_get_contents($source));
        if (! $image) {
            throw new \RuntimeException('Unable to decode image');
        }
        $scale = min(1, 480 / max(imagesx($image), imagesy($image)));
        $thumb = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        try {
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), imagesx($image), imagesy($image));
            ob_start();
            try {
                if (! imagejpeg($thumb, null, 80)) {
                    throw new \RuntimeException('Unable to generate thumbnail');
                }
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new \RuntimeException('Unable to store thumbnail');
            }
        } finally {
            imagedestroy($thumb);
            imagedestroy($image);
        }
    }
}
