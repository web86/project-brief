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
        $access = ProjectAccessToken::with('project')->find($request->session()->get('client_access_token_id'));
        if (! $access || ! $access->isUsable() || $access->project_id !== $request->session()->get('client_project_id')) {
            $request->session()->forget(['client_project_id', 'client_access_token_id']);
            abort(401, 'Сессия завершена. Откройте ссылку клиента ещё раз.');
        }
        $requested = $request->route('project');
        if ($requested) {
            abort_unless(($requested instanceof Project ? $requested->id === $access->project_id : $requested === $access->project->uuid), 404);
        }
        $request->attributes->set('client_project', $access->project);

        return $next($request);
    }
}
