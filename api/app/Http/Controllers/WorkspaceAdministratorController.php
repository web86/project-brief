<?php

namespace App\Http\Controllers;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use App\Services\DeveloperInvitations;
use App\Services\ProjectPermissions;
use App\Services\WorkspaceAgreements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceAdministratorController extends Controller
{
    public function index(Request $request, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);

        $projects = $owner->workspace->projects()->get();
        $allProjectIds = $projects->pluck('uuid');
        $members = $owner->workspace->members()->whereIn('role', ['owner', 'admin'])->with(['user' => fn ($q) => $q->withExists(['passkeys as security_passkey_configured']), 'projectMemberships' => fn ($q) => $q->where('workspace_id', $owner->workspace_id)->whereHas('project', fn ($p) => $p->where('workspace_id', $owner->workspace_id))->with('project')])->get();
        $members->each(fn ($member) => $member->setRelation('workspace', $owner->workspace));
        $acceptances = DB::table('workspace_agreement_acceptances')->where('workspace_id', $owner->workspace_id)->orderByDesc('id')->get()->unique('workspace_member_id')->keyBy('workspace_member_id');

        return response()->json([
            'projects' => $projects->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->title]),
            'members' => $members->map(fn ($m) => [
                'agreement' => app(WorkspaceAgreements::class)->status($m, $acceptances->get($m->id)), 'id' => $m->id, 'isProjectSuperadmin' => (bool) $m->is_project_superadmin, 'canCreateProjects' => ($m->role === 'owner'), 'user' => [...app(AccountProfile::class)->state($m->user), 'name' => $m->display_name ?? $m->user->name], 'role' => $m->role, 'security' => ['passkey' => (bool) $m->user->security_passkey_configured, 'totp' => $m->user->hasEnabledTwoFactorAuthentication()], 'status' => $m->status, 'joinedAt' => $m->joined_at?->toISOString(),
                'projectPermissions' => $m->projectMemberships->mapWithKeys(fn ($p) => [$p->project->uuid => ProjectPermissions::normalize($p->permissions)]),
                'projects' => $m->role === 'owner' ? $allProjectIds : $m->projectMemberships->map(fn ($p) => $p->project->uuid)]),
            'invitations' => DeveloperInvitation::where('workspace_id', $owner->workspace_id)->whereNull('accepted_at')->whereNull('revoked_at')->latest()->get()->map(fn ($i) => [
                'agreement' => app(WorkspaceAgreements::class)->invitationStatus($owner->workspace), 'id' => $i->uuid, 'email' => $i->email, 'name' => $i->name, 'status' => ! $i->sent_at ? 'draft' : ($i->expires_at->isFuture() ? 'pending' : 'expired'), 'expiresAt' => $i->expires_at->toISOString(),
                'isProjectSuperadmin' => (bool) $i->is_project_superadmin, 'canCreateProjects' => false, 'projectPermissions' => $i->project_permissions ?? [], 'sentAt' => $i->sent_at?->toISOString(), 'projects' => $projects->whereIn('id', $i->project_ids)->pluck('uuid')]),
        ])->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'name' => ['nullable', 'string', 'max:255'], 'projects' => ['sometimes', 'array'], 'projects.*' => ['required', 'uuid', 'distinct'], 'sendEmail' => ['sometimes', 'boolean'], 'generateLink' => ['sometimes', 'boolean'],
            'isProjectSuperadmin' => ['sometimes', 'boolean'],
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
        ]);
        abort_if(($data['sendEmail'] ?? true) && ($data['generateLink'] ?? false), 422);
        $this->validatePermissionProjects($data);
        $ids = $invitations->projects($owner, $data['projects'] ?? []);

        return DB::transaction(function () use ($request, $owner, $data, $ids, $invitations): JsonResponse {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $invitations->owner($request);
            $email = DeveloperInvitations::normalizeEmail($data['email']);
            $existing = WorkspaceMember::where('workspace_id', $owner->workspace_id)->active()->whereHas('user', fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$email]))->first();
            if ($existing) {
                return response()->json(['code' => 'administrator_exists', 'errors' => ['memberId' => $existing->id]], 409);
            }
            $pending = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->whereNull('accepted_at')->whereNull('revoked_at')->first();
            if ($pending) {
                return response()->json(['code' => 'administrator_invitation_exists', 'errors' => ['invitationId' => $pending->uuid]], 409);
            }
            $invitation = DeveloperInvitation::create(['workspace_id' => $owner->workspace_id, 'invited_by' => $owner->user_id, 'email' => $email, 'name' => $data['name'] ?? null, 'project_ids' => $ids, 'locale' => $owner->user->preferred_locale ?? 'ru', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDays(7), 'is_project_superadmin' => $data['isProjectSuperadmin'] ?? false, 'can_create_projects' => false, 'project_permissions' => $data['projectPermissions'] ?? null]);
            $sent = ($data['sendEmail'] ?? true) ? $invitations->deliver($invitation) : false;
            $link = ($data['generateLink'] ?? false) ? $invitations->issue($invitation) : null;
            $failed = ($data['sendEmail'] ?? true) && ! $sent;

            return response()->json(['id' => $invitation->uuid, 'link' => $link, 'expiresAt' => $invitation->expires_at->toISOString(), 'sent' => $sent, 'code' => $failed ? 'account_mail_failed' : null], $failed ? 503 : 201)->header('Cache-Control', 'no-store');
        });
    }

    public function updateInvitation(Request $request, string $invitation, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        abort_if(array_diff(array_keys($request->all()), ['name', 'projects', 'canCreateProjects', 'isProjectSuperadmin', 'projectPermissions']), 422);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'projects' => ['present', 'array'], 'projects.*' => ['required', 'uuid', 'distinct'],
            'isProjectSuperadmin' => ['sometimes', 'boolean'],
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
        ]);
        $this->validatePermissionProjects($data);
        $ids = $invitations->projects($owner, $data['projects']);
        DB::transaction(function () use ($request, $owner, $data, $ids, $invitation, $invitations): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $invitations->owner($request);
            $item = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('uuid', $invitation)->lockForUpdate()->firstOrFail();
            abort_if($item->accepted_at || $item->revoked_at, 410);
            $item->update(['name' => $data['name'] ?? $item->name, 'project_ids' => $ids, 'is_project_superadmin' => $data['isProjectSuperadmin'] ?? $item->is_project_superadmin, 'can_create_projects' => false, 'project_permissions' => $data['projectPermissions'] ?? $item->project_permissions]);
        });

        return response()->json(['saved' => true]);
    }

    public function link(Request $request, string $invitation, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);

        return DB::transaction(function () use ($request, $owner, $invitation, $invitations): JsonResponse {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            $invitations->owner($request);
            $item = DeveloperInvitation::where('workspace_id', $owner->workspace_id)->where('uuid', $invitation)->lockForUpdate()->firstOrFail();
            abort_if($item->accepted_at || $item->revoked_at, 410);
            $link = $invitations->issue($item);

            return response()->json(['link' => $link, 'expiresAt' => $item->expires_at->toISOString()])->header('Cache-Control', 'no-store');
        });
    }

    private function validatePermissionProjects(array $data): void
    {
        if (array_diff(array_keys($data['projectPermissions'] ?? []), $data['projects'] ?? [])) {
            throw ValidationException::withMessages(['projectPermissions' => 'Select the project before changing its permissions.']);
        }
    }

    public function resend(Request $request, string $invitation, DeveloperInvitations $invitations): JsonResponse
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

    public function revoke(Request $request, string $invitation, DeveloperInvitations $invitations): JsonResponse
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

    public function update(Request $request, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'projects' => ['present', 'array'], 'projects.*' => ['required', 'uuid', 'distinct'],
            'isProjectSuperadmin' => ['sometimes', 'boolean'],
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
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->whereIn('role', ['admin', 'owner'])->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_if($target->role === 'owner', 403);
            if (isset($data['status'])) {
                $target->update(['status' => $data['status']]);
                if ($data['status'] === 'inactive') {
                    DeveloperInvitation::where('workspace_id', $owner->workspace_id)->whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail($target->user->email)])->whereNull('accepted_at')->update(['revoked_at' => now()]);
                }
            }
            if (array_key_exists('name', $data)) {
                $target->update(['display_name' => $data['name']]);
            }
            if (array_key_exists('isProjectSuperadmin', $data)) {
                $target->update(['is_project_superadmin' => $data['isProjectSuperadmin']]);
                app(AccountSecurity::class)->audit($request->user(), 'project_superadmin_changed:'.$target->id);
            }
            if (array_key_exists('canCreateProjects', $data)) {
                $target->update(['can_create_projects' => false]);
            }
            ProjectMember::where('workspace_member_id', $target->id)->where('workspace_id', $owner->workspace_id)->whereHas('project')->whereNotIn('project_id', $ids)->delete();
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

    public function destroy(Request $request, string $member, DeveloperInvitations $invitations): JsonResponse
    {
        $owner = $invitations->owner($request);
        DB::transaction(function () use ($request, $owner, $member): void {
            Workspace::whereKey($owner->workspace_id)->lockForUpdate()->firstOrFail();
            app(DeveloperInvitations::class)->owner($request);
            $target = WorkspaceMember::where('workspace_id', $owner->workspace_id)->whereKey($member)->lockForUpdate()->firstOrFail();
            abort_if($target->role === 'owner', 403);
            $target->projectMemberships()->where('workspace_id', $owner->workspace_id)->delete();
            $target->delete();
            app(AccountSecurity::class)->audit($request->user(), 'workspace_administrator_deleted:'.$member);
            DeveloperInvitation::where('workspace_id', $owner->workspace_id)->whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail($target->user->email)])->whereNull('accepted_at')->update(['revoked_at' => now()]);
        });

        return response()->json(['revoked' => true]);
    }
}
