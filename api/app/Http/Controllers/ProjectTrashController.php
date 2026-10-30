<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\ProjectLifecycle;
use App\Services\ProjectPermissions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTrashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = app(CurrentWorkspace::class)->resolve($request);
        abort_unless($member->role === 'owner' || $member->is_project_superadmin, 403);
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
            ProjectPermissions::authorize($request, $project, 'manage_trash', true);
            $lifecycle->trash($project);
        }, 3);

        return response()->json(['trashed' => true]);
    }

    public function restore(Request $request, string $projectUuid, ProjectLifecycle $lifecycle): JsonResponse
    {
        DB::transaction(function () use ($request, $projectUuid, $lifecycle): void {
            $this->lockWorkspace($request);
            $project = ProjectAccess::query($request)->onlyTrashed()->where('uuid', $projectUuid)->lockForUpdate()->firstOrFail();
            ProjectPermissions::authorize($request, $project, 'manage_trash', true);
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
            $this->consumeConfirmation($request, 'project', [$project->uuid]);
            ProjectPermissions::authorize($request, $project, 'manage_trash', true);
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
            $this->consumeConfirmation($request, 'workspace_trash', $data['projectIds']);
            foreach ($projects as $project) {
                $lifecycle->purge($project);
            }
        }, 3);
        $lifecycle->cleanupFiles();

        return response()->json(['deleted' => true]);
    }

    public function confirmDeletion(Request $request, ?string $projectUuid = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $projectUuid): JsonResponse {
            $this->lockWorkspace($request, true);
            $query = ProjectAccess::query($request)->onlyTrashed();
            $ids = $projectUuid ? [$query->where('uuid', $projectUuid)->firstOrFail()->uuid] : $query->orderBy('uuid')->pluck('uuid')->all();
            if (! $projectUuid) {
                $request->validate(['projectIds' => ['required', 'array', 'min:1'], 'projectIds.*' => ['uuid', 'distinct']]);
                $submitted = $request->input('projectIds');
                sort($submitted);
                abort_unless($submitted === $ids, 409);
            }
            $id = app(AccountSecurity::class)->challenge($request, 'project_purge', json_encode($this->confirmationScope($request, $projectUuid ? 'project' : 'workspace_trash', $ids), JSON_THROW_ON_ERROR), $request->user()->fresh());

            return response()->json(['confirmation' => $id, 'expiresIn' => 120])->header('Cache-Control', 'no-store');
        });
    }

    private function confirmationScope(Request $request, string $operation, array $ids): array
    {
        sort($ids);

        return ['workspace' => app(CurrentWorkspace::class)->resolve($request)->workspace_id, 'operation' => $operation, 'projectIds' => $ids];
    }

    private function consumeConfirmation(Request $request, string $operation, array $ids): void
    {
        $id = $request->input('confirmation');
        try {
            if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
                throw ValidationException::withMessages(['confirmation' => 'Required']);
            }
            $row = app(AccountSecurity::class)->consume($request, 'project_purge', $id);
            if (json_decode($row->options, true, flags: JSON_THROW_ON_ERROR) !== $this->confirmationScope($request, $operation, $ids)) {
                throw ValidationException::withMessages(['confirmation' => 'Scope changed']);
            }
        } catch (ValidationException) {
            throw new HttpResponseException(response()->json(['code' => 'security_action_confirmation_required', 'message' => trans('security.step_up_required')], 409)->header('Cache-Control', 'no-store'));
        }
    }

    private function lockWorkspace(Request $request, bool $ownerOnly = false): void
    {
        $member = app(CurrentWorkspace::class)->resolve($request);
        Workspace::whereKey($member->workspace_id)->lockForUpdate()->firstOrFail();
        $member = app(CurrentWorkspace::class)->resolve($request, true);
        abort_if($ownerOnly && $member->role !== 'owner', 403);
        if ($ownerOnly) {
            app(AccountSecurity::class)->requireFresh($request);
        }
        $request->attributes->remove(ProjectPermissions::class);
    }
}
