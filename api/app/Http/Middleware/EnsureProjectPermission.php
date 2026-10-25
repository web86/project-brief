<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\ProjectPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $project = $request->route('project');
        abort_unless($project instanceof Project, 404);
        ProjectPermissions::authorize($request, $project, $permission);

        return $next($request);
    }
}
