<?php

namespace App\Http\Middleware;

use App\Services\SupportedLocales;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ThrottleBootstrap
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('http_security.bootstrap_enabled') && in_array($request->method(), ['GET', 'HEAD'], true) && $request->is('api/csrf', 'api/session', 'api/security/status')) {
            // Deliberately use the socket peer, never an unverified forwarded header.
            $peer = $request->server('REMOTE_ADDR');
            if (is_string($peer) && filter_var($peer, FILTER_VALIDATE_IP)) {
                $limiter = new RateLimiter(Cache::store('file'));
                // Bounded cardinality even for rotating IPv6 peers; collisions share a budget.
                $key = 'http-bootstrap:'.(hexdec(substr(hash('sha256', inet_pton($peer)), 0, 4)) % 4096);
                if ($limiter->tooManyAttempts($key, max(1, (int) config('http_security.bootstrap_per_minute')))) {
                    return SecurityResponseHeaders::apply(response()->json(['code' => 'rate_limited', 'message' => trans('http_security.rate_limited', [], SupportedLocales::browser($request->getLanguages()[0] ?? null))], 429))
                        ->header('Retry-After', (string) $limiter->availableIn($key));
                }
                $limiter->hit($key, 60);
            }
        }

        return $next($request);
    }
}
