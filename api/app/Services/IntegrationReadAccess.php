<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ShadowGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class IntegrationReadAccess
{
    public static function scope(Request $request, string $scope): void
    {
        abort_unless(self::hasScope($request, $scope), 403, 'Required scope: '.$scope);
        if (! str_starts_with($scope, 'agent:')) {
            app(ShadowGuard::class)->observe($request->attributes->get('integration_token')->workspace_id, $scope === 'clients:sso' ? 'sso.use' : 'api.read');
        }
    }

    public static function hasScope(Request $request, string $scope): bool
    {
        return in_array($scope, json_decode($request->attributes->get('integration_token')->scopes, true), true);
    }

    public static function project(Request $request, string $uuid): Project
    {
        return app(EffectiveParticipation::class)->visibleProjects($request->attributes->get('integration_token')->workspace_id)->where('uuid', $uuid)->firstOrFail();
    }

    public static function comments(Project $project): Builder
    {
        return app(ProjectDiscussions::class)->query($project)
            ->where(fn (Builder $query) => $query->where('comments.project_id', $project->id)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('comments.project_id')->whereNotNull('task_id')))
            ->whereIn('author_type', ['client', 'admin']);
    }

    public static function comment(Project $project, string $uuid): Comment
    {
        return self::comments($project)->where('comments.uuid', $uuid)->firstOrFail();
    }

    public static function task(Project $project, string $uuid): Task
    {
        return $project->tasks()->where('uuid', $uuid)->firstOrFail();
    }

    public static function attachments(Comment $comment): Builder
    {
        return Attachment::where('comment_id', $comment->id)->when($comment->task_id,
            fn (Builder $query) => $query->where('task_id', $comment->task_id),
            fn (Builder $query) => $query->whereNull('task_id'))->orderBy('id');
    }
}
