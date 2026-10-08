<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureClientProjectAccess;
use App\Services\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __invoke(Request $request, EnsureClientProjectAccess $clients): JsonResponse
    {
        if ($request->user()?->workspaceMembers()->active()->exists()) {
            $context = app(CurrentWorkspace::class);
            $state = ['authenticated' => true, 'type' => 'admin', 'workspace' => $context->state($context->resolve($request))];
        } elseif ($access = $clients->resolve($request)) {
            $state = ['authenticated' => true, 'type' => 'client', 'project' => ['id' => $access->project->uuid]];
        } else {
            $state = ['authenticated' => false];
        }

        return response()->json($state)->header('Cache-Control', 'no-store');
    }
}
