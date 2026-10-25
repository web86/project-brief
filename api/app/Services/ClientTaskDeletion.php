<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientTaskDeletion
{
    public static function delete(Request $request, string $uuid): void
    {
        $project = TaskAccess::task($request, $uuid)->project;
        DB::transaction(function () use ($request, $uuid, $project): void {
            ProjectStructure::lock($project);
            $task = TaskAccess::task($request, $uuid, true);
            abort_unless($task->status === 'new' && ! $task->client_approved, 403, 'Only unapproved new ideas can be moved to trash.');
            TaskAudit::record($request, $task, 'trashed', 'Идея перемещена в корзину');
            $task->delete();
            ProjectStructure::normalizeProject($project);
        }, 3);
    }
}
