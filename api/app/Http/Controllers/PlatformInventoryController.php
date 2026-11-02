<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Plans\WorkspaceImpact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformInventoryController extends Controller
{
    public function index(Request $request, WorkspaceImpact $impact): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['after', 'limit', 'versionId']), 422);
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'versionId' => ['sometimes', 'uuid']]);

        return response()->json($impact->inventory((int) ($data['after'] ?? 0), (int) ($data['limit'] ?? 25), $data['versionId'] ?? null));
    }

    public function show(Request $request, Workspace $workspace, WorkspaceImpact $impact): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['versionId']), 422);
        $data = $request->validate(['versionId' => ['sometimes', 'uuid']]);

        return response()->json(['data' => $impact->preview($workspace->id, $data['versionId'] ?? null)]);
    }
}
