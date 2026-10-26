<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        $ipKey = 'integration-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 120)) {
            return $this->limited($ipKey);
        }
        RateLimiter::hit($ipKey, 60);
        $secret = $request->bearerToken();
        $token = is_string($secret) && preg_match('/^pb_[a-f0-9]{64}$/D', $secret)
            ? DB::table('integration_tokens')->where('secret_hash', hash('sha256', $secret))->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first() : null;
        if (! $token) {
            return response()->json(['code' => 'invalid_token', 'message' => 'A valid bearer token is required.'], 401)->header('WWW-Authenticate', 'Bearer')->header('Cache-Control', 'no-store');
        }
        $workspaceKey = 'integration-workspace:'.$token->workspace_id;
        if (RateLimiter::tooManyAttempts($workspaceKey, 60)) {
            return $this->limited($workspaceKey);
        }
        RateLimiter::hit($workspaceKey, 60);
        $request->attributes->set('integration_token', $token);
        DB::table('integration_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);

        return $next($request)->header('Cache-Control', 'no-store');
    }

    private function limited(string $key): Response
    {
        return response()->json(['code' => 'rate_limited', 'message' => 'Rate limit exceeded.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key))->header('Cache-Control', 'no-store');
    }
}
