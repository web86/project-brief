<?php

namespace App\Services;

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
            } throw $error;
        }
    }

    public static function storeFiles(Request $request, Task $task, array &$paths): void
    {
        $files = $request->file('attachments', []);
        if ($task->attachments()->count() + count($files) > 10) {
            throw ValidationException::withMessages(['attachments' => 'К одной идее можно добавить до 10 файлов.']);
        }
        foreach ($files as $file) {
            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs('attachments/'.$task->uuid, $name, 'local');
            if (! $path) {
                throw new \RuntimeException('Unable to store attachment');
            } $paths[] = $path;
            $original = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $original = preg_replace('/[\x00-\x1F\x7F]/u', '', $original);
            $original = mb_substr($original ?: 'file', 0, 200);
            $task->attachments()->create(['original_name' => $original, 'stored_name' => $name, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                'disk' => 'local', 'path' => $path, 'uploaded_by_type' => TaskAccess::isAdmin($request) ? 'admin' : 'client']);
            TaskAudit::record($request, $task, 'attachment', 'Добавлен файл: '.$original);
        }
    }
}
