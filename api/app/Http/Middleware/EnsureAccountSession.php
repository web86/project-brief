<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ($user = $request->user())) {
            $version = (int) $user->fresh()?->auth_session_version;
            if ((int) $request->session()->get('auth_session_version', 0) !== $version) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                abort(401, 'Session revoked.');
            }
        }

        return $next($request);
    }
}
