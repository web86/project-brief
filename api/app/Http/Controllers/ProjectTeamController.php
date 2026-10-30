<?php

namespace App\Http\Controllers;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
use App\Services\ProjectPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectTeamController extends Controller
{
    private function manager(Request $request, Project $project): WorkspaceMember
    {
        ProjectPermissions::authorize($request, $project, 'manage_team', DB::transactionLevel() > 0);
        if (! $request->isMethodSafe()) {
            app(AccountSecurity::class)->requireFresh($request);
        }

        return app(CurrentWorkspace::class)->resolve($request, DB::transactionLevel() > 0);
    }

    public function invite(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $manager = $this->manager($request, $project);
        abort_if(array_diff(array_keys($request->all()), ['email', 'name', 'generateLink']), 422);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'name' => ['nullable', 'string', 'max:255'], 'generateLink' => ['sometimes', 'boolean']]);

        return DB::transaction(function () use ($request, $project, $manager, $data, $invitations): JsonResponse {
            Workspace::whereKey($manager->workspace_id)->lockForUpdate()->firstOrFail();
            $this->manager($request, $project);
            $email = DeveloperInvitations::normalizeEmail($data['email']);
            $member = WorkspaceMember::where('workspace_id', $manager->workspace_id)->active()->whereHas('user', fn ($query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]))->first();
            if ($member) {
                if ($member->role !== 'owner') {
                    ProjectMember::firstOrCreate(['project_id' => $project->id, 'workspace_member_id' => $member->id], ['workspace_id' => $manager->workspace_id]);
                }

                return response()->json(['assigned' => true]);
            }
            $invitation = DeveloperInvitation::create(['workspace_id' => $manager->workspace_id, 'invited_by' => $manager->user_id, 'email' => $email, 'name' => $data['name'] ?? null,
                'project_ids' => [$project->id], 'project_scoped' => true, 'is_project_superadmin' => false, 'locale' => $manager->user->preferred_locale ?? 'ru', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7)]);
            $link = ($data['generateLink'] ?? false) ? $invitations->issue($invitation) : null;
            $sent = $link ? false : $invitations->deliver($invitation);

            return response()->json(['id' => $invitation->uuid, 'sent' => $sent, 'link' => $link], $link || $sent ? 201 : 503)->header('Cache-Control', 'no-store');
        });
    }

    public function index(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $this->manager($request, $project);
        $members = $owner->workspace->members()->active()->with(['user' => fn ($query) => $query->withExists(['passkeys as security_passkey_configured'])])->get();
        $assigned = $project->members()->where('workspace_id', $owner->workspace_id)->pluck('workspace_member_id');
        $state = fn ($m) => ['id' => $m->id, 'status' => $m->status, 'isProjectSuperadmin' => (bool) $m->is_project_superadmin, 'security' => ['totp' => $m->user->hasEnabledTwoFactorAuthentication(), 'passkey' => (bool) $m->user->security_passkey_configured], 'role' => $m->role, 'user' => [...app(AccountProfile::class)->state($m->user), 'name' => $m->display_name ?? $m->user->name]];

        return response()->json([
            'owner' => $members->where('role', 'owner')->map($state)->values()->first(),
            'members' => $members->where('role', 'admin')->whereIn('id', $assigned)->map($state)->values(),
            'available' => $members->where('role', 'admin')->whereNotIn('id', $assigned)->map($state)->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function assign(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $this->manager($request, $project);
        abort_if(array_diff(array_keys($request->all()), ['members']), 422);
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:1000'], 'members.*' => ['required', 'integer', 'distinct']]);
        DB::transaction(function () use ($request, $project, $owner, $data): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $this->manager($request, $project);
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
        $owner = $this->manager($request, $project);
        abort_if($request->all(), 422);
        DB::transaction(function () use ($request, $project, $member, $owner): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $this->manager($request, $project);
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'admin', 403);
            ProjectMember::where('workspace_id', $owner->workspace_id)->where('project_id', $project->id)->where('workspace_member_id', $target->id)->delete();
        });

        return response()->json(['saved' => true]);
    }
}
