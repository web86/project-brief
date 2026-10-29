<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use App\Services\IntegrationWrites;
use App\Services\ProjectRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProjectRequestController extends Controller
{
    private function workspace(Request $request): int
    {
        $member = app(CurrentWorkspace::class)->resolve($request, DB::transactionLevel() > 0);
        abort_unless($member->role === 'owner', 403);

        return $member->workspace_id;
    }

    public function index(Request $request, ProjectRequests $requests): JsonResponse
    {
        return response()->json($requests->listing($request, $this->workspace($request)))->header('Cache-Control', 'no-store');
    }

    public function count(Request $request, ProjectRequests $requests): JsonResponse
    {
        return response()->json(['pending' => $requests->count($this->workspace($request))])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $uuid, ProjectRequests $requests): JsonResponse
    {
        return response()->json(['data' => $requests->resource($requests->find($this->workspace($request), $uuid))])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $uuid, ProjectRequests $requests): JsonResponse
    {
        return $this->decision($request, $uuid, 'approve', $requests);
    }

    public function reject(Request $request, string $uuid, ProjectRequests $requests): JsonResponse
    {
        return $this->decision($request, $uuid, 'reject', $requests);
    }

    private function decision(Request $request, string $uuid, string $action, ProjectRequests $requests): JsonResponse
    {
        $workspaceId = $this->workspace($request);
        $input = $requests->decisionInput($request, $action);
        DB::transaction(function () use ($request, $workspaceId, $uuid, $action, $input, $requests): void {
            IntegrationWrites::lockWorkspace($workspaceId);
            $this->workspace($request);
            $requests->decide($workspaceId, $uuid, $action, $input, ['type' => 'admin', 'id' => $request->user()->uuid, 'name' => $request->user()->name]);
        }, 3);
        $row = $requests->find($workspaceId, $uuid);
        $requests->deliver($row);

        return response()->json(['data' => $requests->resource($row)])->header('Cache-Control', 'no-store');
    }
}
