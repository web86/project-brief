<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request, CurrentWorkspace $context): JsonResponse
    {
        return response()->json(['data' => $context->memberships($request)->with('workspace')->orderBy('workspace_id')->get()->map(fn ($member) => $context->state($member))])->header('Cache-Control', 'no-store');
    }

    public function select(Request $request, string $workspace, CurrentWorkspace $context): JsonResponse
    {
        return response()->json(['data' => $context->state($context->select($request, $workspace))])->header('Cache-Control', 'no-store');
    }
}
