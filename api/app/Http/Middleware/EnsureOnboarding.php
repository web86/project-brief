<?php

namespace App\Http\Middleware;

use App\Services\AuthOnboarding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $row = $user ? DB::table('account_onboardings')->where('user_id', $user->id)->first() : null;
        if (! $row || $row->state === 'invited_developer') {
            return $next($request);
        }
        $safe = $request->is('api/onboarding/*', 'api/security/status', 'api/auth/*', 'auth/*', 'api/session', 'api/csrf', 'api/health', 'api/entry/branding', 'api/admin/login', 'api/admin/logout', 'api/admin/password/*', 'api/invitations/resolve', 'api/invitations/accept');
        if (! $safe && $user->email_verified_at) {
            $safe = $request->is('api/security/*', 'api/admin/account', 'api/admin/account/password', 'api/admin/account/email*', 'api/admin/agreement', 'api/admin/agreement/accept');
        }
        if (! str_starts_with($request->path(), 'api/') && ! str_starts_with($request->path(), 'auth/')) {
            if ($request->is('register', 'admin/login', 'admin/verify-email', 'admin/onboarding', 'admin/forgot-password', 'admin/reset-password') || ($user->email_verified_at && $request->is('admin/security', 'admin/agreement'))) {
                return $next($request);
            }

            return redirect(app(AuthOnboarding::class)->state($request)['nextPath'])->header('Cache-Control', 'no-store');
        }
        if (! $safe) {
            return response()->json(['code' => 'onboarding_required', 'nextPath' => app(AuthOnboarding::class)->state($request)['nextPath'], 'mutationNotStarted' => true], 403)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
