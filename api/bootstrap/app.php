<?php

use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureClientProjectAccess;
use App\Http\Middleware\EnsureProjectPermission;
use App\Http\Middleware\SetRequestLocale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAdmin::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureProjectPermission::class);
        $middleware->web(append: [EnsureAccountSession::class, SetRequestLocale::class]);
        $middleware->alias(['admin' => EnsureAdmin::class, 'client' => EnsureClientProjectAccess::class, 'project.permission' => EnsureProjectPermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }
            $status = $e instanceof ValidationException ? 422 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof ModelNotFoundException ? 404 : 500));
            $code = [403 => 'insufficient_scope', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'idempotency_conflict', 413 => 'payload_too_large', 415 => 'invalid_content_type', 422 => 'validation_failed', 429 => 'rate_limited'][$status] ?? 'internal_error';

            return response()->json(['code' => $code, 'message' => $status === 500 ? 'Internal error.' : ($status === 422 ? 'Check request fields and Idempotency-Key.' : Response::$statusTexts[$status]), ...($e instanceof ValidationException ? ['errors' => $e->errors()] : [])], $status)->withHeaders($e instanceof HttpExceptionInterface ? $e->getHeaders() : [])->header('Cache-Control', 'no-store');
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'invitation_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
