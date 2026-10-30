<?php

namespace App\Http\Middleware;

use App\Services\AccountSecurity;
use App\Services\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        $audit = app(PlatformAudit::class);
        if (! $user || ! $user->platform_admin) {
            $audit->record($user?->id, 'access_denied', result: $user ? 'forbidden' : 'unauthenticated');
            abort($user ? 403 : 401);
        }
        $security = app(AccountSecurity::class);
        if (! $security->enrolled($user) || ! $security->strong($request, $user)) {
            $audit->record($user->id, 'access_denied', result: 'mfa_required');

            return response()->json(['code' => 'account_security_required', 'state' => $security->state($request)], 403)->header('Cache-Control', 'no-store');
        }
        // Cross-tenant diagnostics require the same five-minute proof as sensitive settings.
        if ($request->route()?->getName() !== 'platform.enter' && ! $security->fresh($request)) {
            $audit->record($user->id, 'access_denied', result: 'step_up_required');
            $security->requireFresh($request);
        }
        $action = $request->route()?->getName() ?? 'platform.view';
        $workspace = $request->route('uuid');
        $response = $next($request);
        $audit->record($user->id, $action, $workspace ? 'workspace' : 'platform', $workspace, $response->getStatusCode() < 400 ? 'success' : 'error');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
