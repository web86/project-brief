<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientProjectAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $access = ProjectAccessToken::with('project', 'projectClient')->find($request->session()->get('client_access_token_id'));
        if (! $access || ! $access->isUsable() || $access->project_id !== $request->session()->get('client_project_id') ||
            ($request->session()->has('project_client_id') && $access->project_client_id !== $request->session()->get('project_client_id'))) {
            $request->session()->forget(['client_project_id', 'project_client_id', 'client_access_token_id']);
            abort(401, 'Сессия завершена. Откройте ссылку клиента ещё раз.');
        }
        $requested = $request->route('project');
        if ($requested) {
            abort_unless(($requested instanceof Project ? $requested->id === $access->project_id : $requested === $access->project->uuid), 404);
        }
        $request->attributes->set('client_project', $access->project);
        // Upgrade an existing signed session only after validating its migrated token/project.
        $request->session()->put('project_client_id', $access->project_client_id);
        $request->attributes->set('project_client', $access->projectClient);

        return $next($request);
    }
}
