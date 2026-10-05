<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskAccess
{
    public static function isAdmin(Request $request): bool
    {
        return $request->is('api/admin/*');
    }

    public static function project(Request $request, ?Project $project = null): Project
    {
        return self::isAdmin($request) ? $project : $request->attributes->get('client_project');
    }

    public static function task(Request $request, string $uuid, bool $lock = false): Task
    {
        $query = Task::where('uuid', $uuid);
        if (! self::isAdmin($request)) {
            $query->where('project_id', self::project($request)->id);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
