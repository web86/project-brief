<?php

namespace App\Http\Middleware;

use App\Services\AgentAudit;
use App\Services\AgentCredentials;
use App\Services\IntegrationWrites;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ExecutionBarrier;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
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
        $request->attributes->set('integration_token', $token);
        $workspaceKey = 'integration-workspace:'.$token->workspace_id;
        if (RateLimiter::tooManyAttempts($workspaceKey, 60)) {
            return $this->limited($workspaceKey);
        }
        RateLimiter::hit($workspaceKey, 60);
        $barrier = app(ExecutionBarrier::class);
        $barrier->catchUp($token->workspace_id);
        if (AgentCredentials::isAgent($token)) {
            return $this->agent($request, $next, $token);
        }
        $barrier->require($token->workspace_id, $request->is('api/v1/client-sso/handoffs') ? 'sso' : 'api');
        if ($request->is('api/v1/project-requests', 'api/v1/project-requests/*', 'api/v1/projects/provision')) {
            app(EffectiveParticipation::class)->requireGrowthAvailable($token->workspace_id);
        }

        DB::table('integration_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function agent(Request $request, Closure $next, object $token): Response
    {
        return DB::transaction(function () use ($request, $next, $token): Response {
            IntegrationWrites::lockWorkspace($token->workspace_id);
            $current = DB::table('integration_tokens')->where('id', $token->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
            if (! $current) {
                return response()->json(['code' => 'invalid_token', 'message' => 'A valid bearer token is required.'], 401)->header('Cache-Control', 'no-store');
            }
            $request->attributes->set('integration_token', $current);
            try {
                abort_unless($request->is('api/v1/agent/*'), 403);
                AgentCredentials::identity($request, $current);
                app(ExecutionBarrier::class)->require($current->workspace_id, 'agent');
                $key = 'agent-token:'.$token->id;
                if (RateLimiter::tooManyAttempts($key, 60)) {
                    $response = $this->limited($key);
                } else {
                    RateLimiter::hit($key, 60);
                    DB::table('integration_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
                    $response = $next($request);
                }
            } catch (\Throwable $error) {
                $handler = app(ExceptionHandler::class);
                $handler->report($error);
                $response = $handler->render($request, $error);
            }
            AgentAudit::request($request, $response->getStatusCode());
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }, 3);
    }

    private function limited(string $key): Response
    {
        return response()->json(['code' => 'rate_limited', 'message' => 'Rate limit exceeded.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key))->header('Cache-Control', 'no-store');
    }
}
