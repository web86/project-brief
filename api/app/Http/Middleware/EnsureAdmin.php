<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\WorkspaceAgreements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/admin/logout')) {
            return $next($request);
        }
        abort_unless($request->user(), 401, 'Войдите в аккаунт администратора.');
        $agreementMember = null;
        // "admin" remains the interface persona, not a global account role.
        if (! $request->is('api/admin/logout')) {
            $context = app(CurrentWorkspace::class);
            if (! $request->is('api/admin/account', 'api/admin/account/*', 'api/admin/avatars/*', 'api/admin/notifications', 'api/admin/notifications/*')) {
                abort_unless($context->memberships($request)->exists(), 403);
            }
            if (! $request->is('api/admin/workspaces', 'api/admin/workspaces/*/select', 'api/admin/me/locale', 'api/admin/account', 'api/admin/account/*', 'api/admin/avatars/*', 'api/admin/notifications', 'api/admin/notifications/*', 'api/admin/push/*', 'api/admin/telegram', 'api/admin/telegram/*')) {
                $agreementMember = $context->resolve($request);
                if ($project = $request->route('project')) {
                    $request->route()->setParameter('project', ProjectAccess::project($request, $project instanceof Project ? $project->uuid : $project));
                }
            }
        }

        if (! $request->is('api/admin/agreement', 'api/admin/agreement/accept', 'api/admin/workspaces', 'api/admin/workspaces/*/select', 'api/admin/me/locale') && $request->user()->workspaceMembers()->active()->exists()) {
            $member = $agreementMember ?? app(CurrentWorkspace::class)->memberships($request)->with('workspace')->when($request->session()->has(CurrentWorkspace::SESSION_KEY), fn ($query) => $query->where('workspace_id', $request->session()->get(CurrentWorkspace::SESSION_KEY)))->orderBy('workspace_id')->first();
            if ($member && app(WorkspaceAgreements::class)->required($member)) {
                return response()->json(['code' => 'administrator_agreement_required'], 403)->header('Cache-Control', 'no-store');
            }
        }

        return $next($request);
    }
}
