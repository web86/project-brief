<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountProfile;
use App\Services\DeveloperInvitations;
use App\Services\ProjectPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectTeamController extends Controller
{
    public function index(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $members = $owner->workspace->members()->active()->with('user')->get();
        $assigned = $project->members()->where('workspace_id', $owner->workspace_id)->pluck('workspace_member_id');
        $state = fn ($m) => ['id' => $m->id, 'role' => $m->role, 'user' => [...app(AccountProfile::class)->state($m->user), 'name' => $m->display_name ?? $m->user->name]];

        return response()->json([
            'owner' => $members->where('role', 'owner')->map($state)->values()->first(),
            'members' => $members->where('role', 'admin')->whereIn('id', $assigned)->map($state)->values(),
            'available' => $members->where('role', 'admin')->whereNotIn('id', $assigned)->map($state)->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function assign(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        abort_if(array_diff(array_keys($request->all()), ['members']), 422);
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:1000'], 'members.*' => ['required', 'integer', 'distinct']]);
        DB::transaction(function () use ($request, $project, $owner, $data, $invitations): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $invitations->owner($request);
            $members = WorkspaceMember::where('workspace_id', $owner->workspace_id)->active()->where('role', 'admin')->whereIn('id', $data['members'])->lockForUpdate()->get();
            abort_unless($members->count() === count($data['members']), 422);
            foreach ($members as $member) {
                ProjectMember::firstOrCreate(['project_id' => $project->id, 'workspace_member_id' => $member->id], ['workspace_id' => $owner->workspace_id, 'permissions' => ProjectPermissions::DEFAULTS]);
            }
        });

        return response()->json(['saved' => true]);
    }

    public function remove(Request $request, Project $project, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        abort_if($request->all(), 422);
        DB::transaction(function () use ($request, $project, $member, $owner, $invitations): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $invitations->owner($request);
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'admin', 403);
            ProjectMember::where('workspace_id', $owner->workspace_id)->where('project_id', $project->id)->where('workspace_member_id', $target->id)->delete();
        });

        return response()->json(['saved' => true]);
    }
}
