<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 401, 'Войдите в аккаунт администратора.');
        // "admin" remains the interface persona, not a global account role.
        if (! $request->is('api/admin/logout')) {
            $context = app(CurrentWorkspace::class);
            if (! $request->is('api/admin/account', 'api/admin/account/*', 'api/admin/avatars/*', 'api/admin/notifications', 'api/admin/notifications/*')) {
                abort_unless($context->memberships($request)->exists(), 403);
            }
            if (! $request->is('api/admin/workspaces', 'api/admin/workspaces/*/select', 'api/admin/me/locale', 'api/admin/account', 'api/admin/account/*', 'api/admin/avatars/*', 'api/admin/notifications', 'api/admin/notifications/*', 'api/admin/push/*', 'api/admin/telegram', 'api/admin/telegram/*')) {
                $context->resolve($request);
                if ($project = $request->route('project')) {
                    $request->route()->setParameter('project', ProjectAccess::project($request, $project instanceof Project ? $project->uuid : $project));
                }
            }
        }

        return $next($request);
    }
}
