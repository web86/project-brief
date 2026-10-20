<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TaskAccess
{
    public static function recipient(Request $request): User|ProjectClient
    {
        return $request->attributes->get('project_client') ?? $request->user();
    }

    public static function isAdmin(Request $request): bool
    {
        return $request->is('api/admin/*');
    }

    public static function project(Request $request, ?Project $project = null): Project
    {
        return self::isAdmin($request) ? ProjectAccess::project($request, $project?->uuid ?? '') : $request->attributes->get('client_project');
    }

    public static function query(Request $request): Builder
    {
        return self::isAdmin($request)
            ? Task::query()->whereIn('project_id', ProjectAccess::query($request)->select('projects.id'))
            : Task::query()->where('project_id', self::project($request)->id);
    }

    public static function task(Request $request, string $uuid, bool $lock = false): Task
    {
        $query = self::query($request)->where('uuid', $uuid);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
