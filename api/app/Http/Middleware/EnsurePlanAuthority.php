<?php

namespace App\Http\Middleware;

use App\Services\AccountSecurity;
use App\Services\Plans\PlanAuthority;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EnsurePlanAuthority
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return DB::transaction(function () use ($request, $next): Response {
                app(PlanAuthority::class)->requirePrimary($request->user());
                $user = $request->user()->fresh();
                $security = app(AccountSecurity::class);
                abort_unless($user->email_verified_at && $security->enrolled($user) && $security->strong($request, $user) && ! $user->security_recovery_expires_at, 403);
                if ($request->route()?->getName() !== 'platform.plans.enter') {
                    $security->requireFresh($request);
                }

                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }

                return $response;
            }, 3);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (Throwable $e) {
            return self::failure($e);
        }
    }

    public static function failure(Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }
        $status = match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof ModelNotFoundException => 404,
            $e instanceof ValidationException, $e instanceof \InvalidArgumentException, $e instanceof \TypeError, $e instanceof \JsonException => 422,
            default => 500,
        };
        if ($status === 500) {
            report($e);
        }
        $code = [403 => 'plan_authority_required', 404 => 'plan_not_found', 409 => 'plan_revision_conflict', 422 => 'plan_configuration_invalid', 428 => 'plan_revision_required', 503 => 'plan_operation_unavailable'][$status] ?? 'plan_request_failed';

        if ($status === 409 && $e->getMessage() === 'Plan has active configuration dependencies.') {
            $code = 'plan_dependencies';
        }

        return response()->json(['code' => $code, 'message' => $code], $status)->header('Cache-Control', 'no-store');
    }
}
