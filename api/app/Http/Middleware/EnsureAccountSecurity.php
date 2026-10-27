<?php

namespace App\Http\Middleware;

use App\Services\AccountSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/admin/*', 'api/invitations/accept') && ! $request->is('api/admin/login', 'api/admin/logout', 'api/admin/password/forgot', 'api/admin/password/reset')) {
            $security = app(AccountSecurity::class);
            $state = $security->state($request);
            if (in_array($state, ['pending_2fa', 'enrollment_required'], true)) {
                return response()->json(['code' => 'account_security_required', 'state' => $state, 'message' => trans('security.'.$state)], 403)->header('Cache-Control', 'no-store');
            }
        }
        $response = $next($request);
        if ($request->is('api/security/*')) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
