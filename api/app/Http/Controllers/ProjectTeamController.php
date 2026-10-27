<?php

namespace App\Http\Controllers;

use App\Models\DeveloperInvitation;
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
use Illuminate\Validation\ValidationException;

class ProjectTeamController extends Controller
{
    public function index(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);

        $projects = $owner->workspace->projects()->get();
        $allProjectIds = $projects->pluck('uuid');

        return response()->json([
            'projects' => $projects->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->title]),
            'members' => $owner->workspace->members()->active()->with(['user' => fn ($q) => $q->withExists(['passkeys as security_passkey_configured']), 'projectMemberships' => fn ($q) => $q->whereHas('project')->with('project')])->get()->map(fn ($m) => [
                'id' => $m->id, 'canCreateProjects' => ProjectPermissions::canCreateProjects($m), 'user' => app(AccountProfile::class)->state($m->user), 'role' => $m->role, 'security' => ['passkey' => (bool) $m->user->security_passkey_configured, 'totp' => $m->user->hasEnabledTwoFactorAuthentication()], 'status' => 'active', 'joinedAt' => $m->joined_at?->toISOString(),
                'projectPermissions' => $m->projectMemberships->mapWithKeys(fn ($p) => [$p->project->uuid => ProjectPermissions::normalize($p->permissions)]),
                'projects' => $m->role === 'owner' ? $allProjectIds : $m->projectMemberships->map(fn ($p) => $p->project->uuid)]),
            'invitations' => DeveloperInvitation::where('workspace_id', $owner->workspace_id)->whereNull('accepted_at')->whereNull('revoked_at')->latest()->get()->map(fn ($i) => [
                'id' => $i->uuid, 'email' => $i->email, 'name' => $i->name, 'status' => $i->expires_at->isFuture() ? 'pending' : 'expired', 'expiresAt' => $i->expires_at->toISOString(),
                'projects' => $projects->whereIn('id', $i->project_ids)->pluck('uuid')]),
        ])->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, Project $project, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'name' => ['nullable', 'string', 'max:255'], 'projects' => ['required', 'array', 'min:1'], 'projects.*' => ['required', 'uuid', 'distinct']]);
        $ids = $invitations->projects($owner, $data['projects']);
        $sent = DB::transaction(function () use ($request, $owner, $data, $ids, $invitations): bool {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            app(DeveloperInvitations::class)->owner($request);
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
        $sent = DB::transaction(function () use ($request, $owner, $invitation, $invitations): bool {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            app(DeveloperInvitations::class)->owner($request);
            $item = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('uuid', $invitation)->lockForUpdate()->firstOrFail();
            abort_if($item->accepted_at || $item->revoked_at, 410);

            return $invitations->deliver($item);
        });

        return response()->json(['sent' => $sent, 'code' => $sent ? null : 'account_mail_failed'], $sent ? 200 : 503);
    }

    public function revoke(Request $request, Project $project, string $invitation, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        DB::transaction(function () use ($request, $owner, $invitation): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            app(DeveloperInvitations::class)->owner($request);
            $item = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('uuid', $invitation)->lockForUpdate()->firstOrFail();
            abort_if($item->accepted_at, 410);
            $item->forceFill(['revoked_at' => now()])->save();
        });

        return response()->json(['revoked' => true]);
    }

    public function update(Request $request, Project $project, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $data = $request->validate([
            'projects' => ['present', 'array'], 'projects.*' => ['required', 'uuid', 'distinct'],
            'canCreateProjects' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('The option must be a boolean.');
                }
            }],
            'projectPermissions' => ['sometimes', 'array'],
            'projectPermissions.*' => ['required', 'array:'.implode(',', array_keys(ProjectPermissions::DEFAULTS))],
            'projectPermissions.*.*' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('Permissions must be booleans.');
                }
            }],
            'role' => ['prohibited'], 'permissions' => ['prohibited'],
        ]);
        if (array_diff(array_keys($data['projectPermissions'] ?? []), $data['projects'])) {
            throw ValidationException::withMessages(['projectPermissions' => 'Select the project before changing its permissions.']);
        }
        $ids = $invitations->projects($owner, $data['projects']);
        DB::transaction(function () use ($request, $owner, $member, $ids, $data): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            app(DeveloperInvitations::class)->owner($request);
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->active()->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_if($target->role === 'owner', 403);
            if (array_key_exists('canCreateProjects', $data)) {
                $target->update(['can_create_projects' => $data['canCreateProjects']]);
            }
            ProjectMember::where('workspace_member_id', $target->id)->whereHas('project')->whereNotIn('project_id', $ids)->delete();
            foreach ($ids as $id) {
                $assignment = ProjectMember::firstOrCreate(['project_id' => $id, 'workspace_member_id' => $target->id], ['workspace_id' => $owner->workspace_id]);
                $uuid = Project::whereKey($id)->value('uuid');
                if (array_key_exists($uuid, $data['projectPermissions'] ?? [])) {
                    $assignment->update(['permissions' => ProjectPermissions::normalize($data['projectPermissions'][$uuid])]);
                }
            }
        });

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, Project $project, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        DB::transaction(function () use ($request, $owner, $member): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            app(DeveloperInvitations::class)->owner($request);
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_if($target->role === 'owner', 403);
            $target->projectMemberships()->delete();
            $target->forceFill(['status' => 'inactive', 'can_create_projects' => false])->save();
            DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('email', mb_strtolower($target->user->email))->whereNull('accepted_at')->update(['revoked_at' => now()]);
        });

        return response()->json(['revoked' => true]);
    }
}
