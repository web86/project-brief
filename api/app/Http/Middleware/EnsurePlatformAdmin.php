<?php

namespace App\Http\Middleware;

use App\Services\AccountSecurity;
use App\Services\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $startedAt = (int) $request->session()->get('security.started_at', $request->session()->get('security.confirmed_at', 0));
        if ($startedAt <= now()->subHours(AccountSecurity::PLATFORM_SESSION_HOURS)->timestamp || $startedAt > now()->timestamp) {
            $audit->record($user->id, 'access_denied', result: 'session_expired');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->is('api/*')
                ? response()->json(['code' => 'platform_session_expired'], 401)->header('Cache-Control', 'no-store')
                : redirect('/admin/login')->header('Cache-Control', 'no-store');
        }
        if (! $request->isMethodSafe() && ! $security->fresh($request)) {
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
