<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\ProjectLifecycle;
use App\Services\ProjectPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectTrashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = ProjectAccess::query($request)->onlyTrashed()->latest('deleted_at')->get()
            ->filter(fn ($project) => ProjectPermissions::effective($request, $project)['manage_trash']);

        return response()->json(['data' => $projects->map(fn ($project) => [
            'id' => $project->uuid, 'name' => $project->title, 'deletedAt' => $project->deleted_at->toISOString(),
        ])->values()])->header('Cache-Control', 'no-store');
    }

    public function trash(Request $request, string $projectUuid, ProjectLifecycle $lifecycle): JsonResponse
    {
        DB::transaction(function () use ($request, $projectUuid, $lifecycle): void {
            $this->lockWorkspace($request);
            $project = ProjectAccess::query($request)->where('uuid', $projectUuid)->lockForUpdate()->firstOrFail();
            ProjectPermissions::authorize($request, $project, 'manage_trash');
            $lifecycle->trash($project);
        }, 3);

        return response()->json(['trashed' => true]);
    }

    public function restore(Request $request, string $projectUuid, ProjectLifecycle $lifecycle): JsonResponse
    {
        DB::transaction(function () use ($request, $projectUuid, $lifecycle): void {
            $this->lockWorkspace($request);
            $project = ProjectAccess::query($request)->onlyTrashed()->where('uuid', $projectUuid)->lockForUpdate()->firstOrFail();
            ProjectPermissions::authorize($request, $project, 'manage_trash');
            $lifecycle->restore($project);
        }, 3);

        return response()->json(['restored' => true]);
    }

    public function destroy(Request $request, string $projectUuid, ProjectLifecycle $lifecycle): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $projectUuid, $lifecycle): void {
            $this->lockWorkspace($request, true);
            $project = ProjectAccess::query($request)->onlyTrashed()->where('uuid', $projectUuid)->lockForUpdate()->firstOrFail();
            $lifecycle->purge($project);
        }, 3);
        $lifecycle->cleanupFiles();

        return response()->json(['deleted' => true]);
    }

    public function empty(Request $request, ProjectLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'projectIds' => ['present', 'array'], 'projectIds.*' => ['required', 'uuid', 'distinct']]);
        DB::transaction(function () use ($request, $data, $lifecycle): void {
            $this->lockWorkspace($request, true);
            $projects = ProjectAccess::query($request)->onlyTrashed()->orderBy('id')->lockForUpdate()->get();
            abort_unless($projects->pluck('uuid')->sort()->values()->all() === collect($data['projectIds'])->sort()->values()->all(), 409);
            foreach ($projects as $project) {
                $lifecycle->purge($project);
            }
        }, 3);
        $lifecycle->cleanupFiles();

        return response()->json(['deleted' => true]);
    }

    private function lockWorkspace(Request $request, bool $ownerOnly = false): void
    {
        $member = app(CurrentWorkspace::class)->resolve($request);
        abort_if($ownerOnly && $member->role !== 'owner', 403);
        Workspace::whereKey($member->workspace_id)->lockForUpdate()->firstOrFail();
    }
}
