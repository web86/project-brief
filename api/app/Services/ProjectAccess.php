<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Plans\EffectiveParticipation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectAccess
{
    public static function forMember(WorkspaceMember $member): Builder
    {
        $query = Project::query()->where('workspace_id', $member->workspace_id);
        if ($member->status !== 'active' || ! in_array($member->role, ['owner', 'admin'], true)) {
            return $query->whereRaw('1 = 0');
        }
        if ($member->role !== 'owner') {
            $activity = app(EffectiveParticipation::class);
            $membership = DB::table('workspace_members')->where('id', $member->id)->where('workspace_id', $member->workspace_id);
            $query->whereExists($activity->excludeHeld($membership, $member->workspace_id, 'workspace_member', 'workspace_members.id'))
                ->whereHas('members', fn ($query) => $query->where('workspace_member_id', $member->id));
            $activity->excludeHeld($query->getQuery(), $member->workspace_id, 'project', 'projects.id');
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
