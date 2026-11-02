<?php

namespace App\Http\Middleware;

use App\Services\DemoEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** CLI-only reads are bounded by the reviewed route inventory, not public HTTP quotas. */
class DemoStaticExportThrottle
{
    public function handle(Request $request, Closure $next, mixed ...$limits): Response
    {
        abort_unless(app()->runningInConsole() && $request->isMethod('GET') && DemoEnvironment::readOnly(), 403);

        return $next($request);
    }
}
