<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ClientTaskDeletion
{
    public static function delete(Request $request, string $uuid): void
    {
        $project = TaskAccess::task($request, $uuid)->project;
        $backups = [];
        $deletingStarted = false;
        try {
            DB::transaction(function () use ($request, $uuid, $project, &$backups, &$deletingStarted): void {
                ProjectStructure::lock($project);
                $task = TaskAccess::task($request, $uuid, true);
                abort_unless($task->isClientEditable(), 403);
                $seen = [];
                foreach ($task->attachments as $file) {
                    $key = $file->disk.'|'.$file->path;
                    if (isset($seen[$key]) || Attachment::where('disk', $file->disk)->where('path', $file->path)->where('task_id', '!=', $task->id)->exists()) {
                        continue;
                    }
                    $seen[$key] = true;
                    $disk = Storage::disk($file->disk);
                    if (! $disk->exists($file->path)) {
                        continue;
                    }
                    $directory = storage_path('app/private/.deletion-recovery');
                    File::ensureDirectoryExists($directory, 0700);
                    $backup = tempnam($directory, 'draft-');
                    if ($backup === false) {
                        throw new RuntimeException('Unable to stage deletion');
                    }
                    $backups[] = ['disk' => $file->disk, 'path' => $file->path, 'backup' => $backup, 'keep' => false];
                    $expectedSize = $disk->size($file->path);
                    $source = $disk->readStream($file->path);
                    $target = fopen($backup, 'wb');
                    try {
                        if (! is_resource($source) || ! is_resource($target) || stream_copy_to_stream($source, $target) !== $expectedSize || ! fflush($target)) {
                            throw new RuntimeException('Unable to back up attachment');
                        }
                    } finally {
                        if (is_resource($source)) {
                            fclose($source);
                        }
                        if (is_resource($target)) {
                            fclose($target);
                        }
                    }
                }
                $deletingStarted = true;
                foreach ($backups as $file) {
                    $disk = Storage::disk($file['disk']);
                    if (! $disk->delete($file['path']) || $disk->exists($file['path'])) {
                        throw new RuntimeException('Unable to delete attachment');
                    }
                }
                $task->delete();
                ProjectStructure::normalizeProject($project);
            });
        } catch (Throwable $error) {
            if ($deletingStarted) {
                foreach ($backups as &$file) {
                    try {
                        $disk = Storage::disk($file['disk']);
                        if (! $disk->exists($file['path'])) {
                            $source = fopen($file['backup'], 'rb');
                            try {
                                if (! is_resource($source) || ! $disk->put($file['path'], $source)) {
                                    throw new RuntimeException('Unable to restore attachment');
                                }
                            } finally {
                                if (is_resource($source)) {
                                    fclose($source);
                                }
                            }
                        }
                    } catch (Throwable $restoreError) {
                        $file['keep'] = true;
                        Log::error('Draft deletion recovery required', ['backup' => $file['backup'], 'error' => $restoreError->getMessage()]);
                    }
                }
                unset($file);
            }
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            report($error);
            throw new HttpException(503, 'Unable to delete the idea safely. Please retry later.', $error);
        } finally {
            foreach ($backups as $file) {
                if (! $file['keep'] && is_file($file['backup']) && ! unlink($file['backup'])) {
                    Log::error('Draft deletion backup cleanup failed', ['backup' => $file['backup']]);
                    throw new HttpException(503, 'Attachment cleanup failed. Please contact the developer.');
                }
            }
        }
    }
}
