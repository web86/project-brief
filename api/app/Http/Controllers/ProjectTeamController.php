<?php

namespace App\Http\Controllers;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountProfile;
use App\Services\DeveloperInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectTeamController extends Controller
{
    public function index(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);

        return response()->json([
            'projects' => $owner->workspace->projects()->get()->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->title]),
            'members' => $owner->workspace->members()->active()->with(['user', 'projectMemberships.project'])->get()->map(fn ($m) => [
                'id' => $m->id, 'user' => app(AccountProfile::class)->state($m->user), 'role' => $m->role, 'status' => 'active', 'joinedAt' => $m->joined_at?->toISOString(),
                'projects' => $m->role === 'owner' ? $owner->workspace->projects()->pluck('uuid') : $m->projectMemberships->map(fn ($p) => $p->project->uuid)]),
            'invitations' => DeveloperInvitation::where('workspace_id', $owner->workspace_id)->whereNull('accepted_at')->whereNull('revoked_at')->latest()->get()->map(fn ($i) => [
                'id' => $i->uuid, 'email' => $i->email, 'name' => $i->name, 'status' => $i->expires_at->isFuture() ? 'pending' : 'expired', 'expiresAt' => $i->expires_at->toISOString(),
                'projects' => $owner->workspace->projects()->whereIn('id', $i->project_ids)->pluck('uuid')]),
        ])->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'name' => ['nullable', 'string', 'max:255'], 'projects' => ['required', 'array', 'min:1'], 'projects.*' => ['required', 'uuid', 'distinct']]);
        $ids = $invitations->projects($owner, $data['projects']);
        $sent = DB::transaction(function () use ($owner, $data, $ids, $invitations): bool {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $email = mb_strtolower(trim($data['email']));
            DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $invitation = DeveloperInvitation::create(['workspace_id' => $owner->workspace_id, 'invited_by' => $owner->user_id, 'email' => $email, 'name' => $data['name'] ?? null, 'project_ids' => $ids, 'locale' => $owner->user->preferred_locale ?? 'ru', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7)]);

            return $invitations->deliver($invitation);
        });

        return response()->json(['sent' => $sent, 'code' => $sent ? null : 'account_mail_failed'], $sent ? 201 : 503);
    }

    public function resend(Request $request, Project $project, string $invitation, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $sent = DB::transaction(function () use ($owner, $invitation, $invitations): bool {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $item = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('uuid', $invitation)->lockForUpdate()->firstOrFail();
            abort_if($item->accepted_at || $item->revoked_at, 410);

            return $invitations->deliver($item);
        });

        return response()->json(['sent' => $sent, 'code' => $sent ? null : 'account_mail_failed'], $sent ? 200 : 503);
    }

    public function revoke(Request $request, Project $project, string $invitation, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        DB::transaction(function () use ($owner, $invitation): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $item = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('uuid', $invitation)->lockForUpdate()->firstOrFail();
            abort_if($item->accepted_at, 410);
            $item->forceFill(['revoked_at' => now()])->save();
        });

        return response()->json(['revoked' => true]);
    }

    public function update(Request $request, Project $project, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $data = $request->validate(['projects' => ['present', 'array'], 'projects.*' => ['required', 'uuid', 'distinct']]);
        $ids = $invitations->projects($owner, $data['projects']);
        DB::transaction(function () use ($owner, $member, $ids): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->active()->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_if($target->role === 'owner', 403);
            ProjectMember::where('workspace_member_id', $target->id)->whereNotIn('project_id', $ids)->delete();
            foreach ($ids as $id) {
                ProjectMember::firstOrCreate(['project_id' => $id, 'workspace_member_id' => $target->id], ['workspace_id' => $owner->workspace_id]);
            }
        });

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, Project $project, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        DB::transaction(function () use ($owner, $member): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_if($target->role === 'owner', 403);
            $target->projectMemberships()->delete();
            $target->forceFill(['status' => 'inactive'])->save();
            DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('email', mb_strtolower($target->user->email))->whereNull('accepted_at')->update(['revoked_at' => now()]);
        });

        return response()->json(['revoked' => true]);
    }
}
