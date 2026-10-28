<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectUnread
{
    public const EVENTS = ['created', 'comment', 'approved'];

    private function relevant(): QueryBuilder
    {
        return DB::table('task_history')->join('tasks', 'tasks.id', '=', 'task_history.task_id')
            ->whereNull('tasks.deleted_at')->where('task_history.actor_type', 'client')->whereIn('task_history.event_type', self::EVENTS);
    }

    public function withCounts(Builder $projects, User $user): Builder
    {
        if ($projects->getQuery()->columns === null) {
            $projects->select('projects.*');
        }

        return $projects->addSelect(['unread_comment_count' => app(ProjectDiscussions::class)->unread(Comment::query(), $user)
            ->where(fn (Builder $comments) => $comments->whereHas('task', fn (Builder $tasks) => $tasks->whereColumn('tasks.project_id', 'projects.id'))
                ->orWhere(fn (Builder $general) => $general->whereNull('task_id')->whereColumn('comments.project_id', 'projects.id')))
            ->selectRaw('COUNT(*)'), 'unread_count' => $this->relevant()->selectRaw('COUNT(*)')
            ->whereColumn('tasks.project_id', 'projects.id')
            ->whereRaw('task_history.id > COALESCE((SELECT last_seen_history_id FROM project_reads WHERE project_reads.project_id = projects.id AND project_reads.user_id = ?), 0)', [$user->id])]);
    }

    /** @return array{unreadCount: int} */
    public function state(Request $request, Project $project): array
    {
        $authorized = ProjectAccess::project($request, $project->uuid);
        $result = $this->withCounts(ProjectAccess::query($request)->whereKey($authorized->id), $request->user())->firstOrFail();

        return ['unreadCount' => (int) $result->unread_count];
    }

    /** @return array{unreadCount: int} */
    public function mark(Request $request, Project $project): array
    {
        $project = ProjectAccess::project($request, $project->uuid);
        DB::transaction(function () use ($request, $project): void {
            $maximum = (int) ($this->relevant()->where('tasks.project_id', $project->id)->max('task_history.id') ?? 0);
            $key = ['user_id' => $request->user()->id, 'project_id' => $project->id];
            $inserted = DB::table('project_reads')->insertOrIgnore([...$key, 'last_seen_history_id' => $maximum, 'created_at' => now(), 'updated_at' => now()]);
            // Conditional update is atomic and never lowers a marker under concurrent requests/deletion.
            $changed = DB::table('project_reads')->where($key)->where('last_seen_history_id', '<', $maximum)
                ->update(['last_seen_history_id' => $maximum, 'updated_at' => now()]);
            if ($changed || $inserted && $maximum > 0) {
                app(LiveSync::class)->record($project->workspace_id, $project->id, 'read');
            }
        });

        return $this->state($request, $project);
    }
}
