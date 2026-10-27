<?php

namespace App\Services;

use App\Exceptions\AccountSecurityFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountSecurityDiagnostics
{
    public function render(Throwable $exception, Request $request): JsonResponse
    {
        $status = match (true) {
            $exception instanceof AccountSecurityFailure => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof ValidationException => 422,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $stage = $request->attributes->get('security.stage', 'http');
        $code = $exception instanceof AccountSecurityFailure ? $exception->diagnosticCode : match ($status) {
            400 => 'SEC_REQUEST_INVALID', 401 => 'SEC_SESSION_EXPIRED', 403 => 'SEC_FORBIDDEN',
            404 => 'SEC_NOT_FOUND', 419 => 'SEC_CSRF_EXPIRED', 422 => $exception instanceof ValidationException && isset($exception->errors()['code']) ? 'SEC_FACTOR_INVALID' : 'SEC_FIELDS_INVALID',
            423 => 'security_step_up_required', 429 => 'SEC_RATE_LIMITED', 503 => 'PK_UNAVAILABLE',
            409 => 'SEC_CONFLICT', default => $stage === 'persist' ? 'PK_PERSIST_FAILED' : 'SEC_SERVER_ERROR',
        };
        $id = (string) Str::uuid();
        // Never attach exceptions: SQL bindings and serializer errors can contain credentials.
        $context = ['diagnostic_id' => $id, 'code' => $code, 'stage' => $stage, 'status' => $status, 'exception_type' => $exception::class,
            'session_cookie_present' => $request->hasCookie(config('session.cookie')),
            'request_secure' => $request->isSecure(),
            'forwarded_https' => $request->header('X-Forwarded-Proto') === 'https',
            'request_origin_matches' => $request->header('Origin') === config('fortify.passkeys.allowed_origins.0'),
        ];
        if ($exception instanceof QueryException) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            if (preg_match('/^[A-Z0-9]{5}$/D', $sqlState)) {
                $context['sqlstate'] = $sqlState;
            }
            if (is_numeric($exception->errorInfo[1] ?? null)) {
                $context['driver_code'] = (int) $exception->errorInfo[1];
            }
        }
        Log::warning('account_security_failure', $context);

        return response()->json([
            'message' => trans('security.'.($code === 'security_step_up_required' ? 'step_up_required' : 'operation_failed')),
            ...($status === 423 && $request->attributes->get('security.mutation_not_started') === true ? ['mutationNotStarted' => true] : []),
            'code' => $code, 'diagnosticId' => $id, 'stage' => $stage,
        ], $status)->withHeaders($exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [])
            ->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }
}
