<?php

use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureClientProjectAccess;
use App\Http\Middleware\EnsureProjectPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAdmin::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureProjectPermission::class);
        $middleware->web(append: [EnsureAccountSession::class]);
        $middleware->alias(['admin' => EnsureAdmin::class, 'client' => EnsureClientProjectAccess::class, 'project.permission' => EnsureProjectPermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'invitation_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
