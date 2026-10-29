<?php

namespace App\Http\Middleware;

use App\Services\ApiUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MeasureApiUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1', 'api/v1/*') && config('platform.metrics_enabled')) {
            $request->attributes->set('usage.started', hrtime(true));
            $request->attributes->set('usage.hour', now('UTC')->startOfHour()->format('Y-m-d H:i:s'));
        }

        $response = $next($request);
        if ($request->attributes->has('usage.started')) {
            $request->attributes->set('usage.duration_us', max(0, (int) ((hrtime(true) - $request->attributes->get('usage.started')) / 1000)));
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('usage.started');
        if (! is_int($started) || $request->attributes->get('usage.recorded')) {
            return;
        }
        $request->attributes->set('usage.recorded', true);
        try {
            app(ApiUsage::class)->record($request, $response->getStatusCode(), $request->attributes->get('usage.duration_us', max(0, (int) ((hrtime(true) - $started) / 1000))));
        } catch (\Throwable) {
            // Optional diagnostics must never break API responses or log request secrets.
        }
    }
}
