<?php

namespace App\Http\Middleware;

use App\Services\DemoEnvironment;
use App\Services\DemoIntegrationPreview;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DemoReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoEnvironment::enabled()) {
            abort_if($request->is('demo', 'demo/*', 'api/demo', 'api/demo/*'), 404);

            return $next($request);
        }
        DemoEnvironment::assertIsolated();
        if (DemoEnvironment::readOnly()) {
            DB::connection()->beforeExecuting(function (string $query): void {
                if (DemoEnvironment::readOnly() && ! preg_match('/^\\s*(SELECT|SHOW|EXPLAIN)\\b/i', $query)) {
                    throw new \RuntimeException('Demo database is read only.');
                }
            });
            // Explicitly allow only browser reads. No bearer API, SSO handoffs, access links,
            // credential challenges, private documents, platform tools or future API families.
            $read = $request->isMethod('GET') || $request->isMethod('HEAD');
            $allowed = $request->is('api/demo/status', 'api/demo/enter/developer', 'api/demo/enter/client', 'api/session', 'api/csrf', 'api/entry/branding', 'api/security/status', 'api/admin/*', 'api/client/*', 'api/push/*/status', 'api/notifications/*', 'api/telegram/*', 'api/workspaces/*/branding');
            if (! $read || $request->is('api/*') && ! $allowed || $request->is('platform', 'platform/*', 'api/platform/*', 'access/*', 'client-sso/*', 'api/admin/resource-selection*', 'api/admin/account*', 'api/admin/settings/agreement', 'api/admin/agreement')) {
                return DemoEnvironment::blocked();
            }
        }
        $response = $next($request);
        if ($response instanceof JsonResponse && $request->is('api/admin/*')) {
            $response = DemoIntegrationPreview::decorate($response, $request->path());
        }
        $response->headers->set('X-ProjectBrief-Demo', DemoEnvironment::readOnly() ? 'view-only' : 'local-qa');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
