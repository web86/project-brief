<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProjectAccess
{
    public static function forMember(WorkspaceMember $member): Builder
    {
        $query = Project::query()->where('workspace_id', $member->workspace_id);
        if ($member->status !== 'active' || ! in_array($member->role, ['owner', 'admin'], true)) {
            return $query->whereRaw('1 = 0');
        }
        if ($member->role !== 'owner') {
            $query->whereHas('members', fn ($query) => $query->where('workspace_member_id', $member->id));
        }

        return $query;
    }

    public static function query(Request $request): Builder
    {
        return self::forMember(app(CurrentWorkspace::class)->resolve($request));
    }

    public static function project(Request $request, string $uuid): Project
    {
        return self::query($request)->where('uuid', $uuid)->firstOrFail();
    }

    public static function memberships(Project $project): Builder
    {
        return WorkspaceMember::query()->active()->where('workspace_id', $project->workspace_id)
            ->where(fn ($query) => $query->where('role', 'owner')->orWhere(fn ($query) => $query->where('role', 'admin')
                ->whereHas('projectMemberships', fn ($query) => $query->where('project_id', $project->id))));
    }

    public static function recipients(Project $project): Builder
    {
        return User::query()->whereIn('id', self::memberships($project)->select('user_id'))->orderBy('id');
    }
}
