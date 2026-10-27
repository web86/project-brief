<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMember;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class ProjectPermissions
{
    public const DEFAULTS = [
        'manage_structure' => true,
        'manage_clients' => false,
        'manage_project_settings' => false,
        'manage_project_notifications' => false,
        'export_project' => false,
        'manage_trash' => false,
    ];

    public static function canCreateProjects(WorkspaceMember $member): bool
    {
        return $member->status === 'active' && ($member->role === 'owner' || ($member->role === 'admin' && $member->can_create_projects));
    }

    public static function normalize(?array $permissions): array
    {
        return array_replace(self::DEFAULTS,
            array_intersect_key(array_map(fn ($value) => $value === true, $permissions ?? []), self::DEFAULTS));
    }

    public static function effective(Request $request, Project $project, bool $lock = false): array
    {
        $context = $request->attributes->get(self::class);
        if ($context === null || $lock) {
            $member = app(CurrentWorkspace::class)->resolve($request, $lock);
            $context = [
                'workspace' => $member->workspace_id,
                'owner' => $member->role === 'owner',
                'projects' => $member->role === 'owner' ? [] : ProjectMember::where('workspace_id', $member->workspace_id)
                    ->where('workspace_member_id', $member->id)->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('project_id'),
            ];
            $request->attributes->set(self::class, $context);
        }
        abort_unless($context['workspace'] === $project->workspace_id, 404);
        if ($context['owner']) {
            return array_fill_keys(array_keys(self::DEFAULTS), true);
        }
        $assignment = $context['projects'][$project->id] ?? null;
        abort_unless($assignment, 404);

        return self::normalize($assignment->permissions);
    }

    public static function authorize(Request $request, Project $project, string $permission, bool $lock = false): void
    {
        if (! (self::effective($request, $project, $lock)[$permission] ?? false)) {
            throw new HttpResponseException(response()->json(['code' => 'project_permission_denied', 'message' => 'Ask the workspace owner for access.'], 403));
        }
    }
}
