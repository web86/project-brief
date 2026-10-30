<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\LiveSync;
use App\Services\ProjectFeedbacks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LiveSyncObserver
{
    public function saved(Model $model): void
    {
        if (! $model->wasRecentlyCreated && ! array_diff(array_keys($model->getChanges()), ['updated_at'])) {
            return;
        }
        $this->changed($model);
    }

    public function deleted(Model $model): void
    {
        $this->changed($model);
    }

    public function restored(Model $model): void
    {
        $this->changed($model);
    }

    private function changed(Model $model): void
    {
        $projectId = $model instanceof Project ? $model->id : $model->getAttribute('project_id');
        if (! $projectId && $model->getAttribute('task_id')) {
            $projectId = DB::table('tasks')->where('id', $model->task_id)->value('project_id');
        }
        if (! $projectId && $model->getAttribute('comment_id')) {
            $projectId = DB::table('comments')->where('id', $model->comment_id)->value('project_id');
        }
        $privateFile = $model->getAttribute('comment_id') && DB::table('comments')->where('id', $model->comment_id)->where('team_only', true)->exists();
        $workspaceId = $model instanceof Workspace ? $model->id : $model->getAttribute('workspace_id');
        if (! $workspaceId && $projectId) {
            $workspaceId = DB::table('projects')->where('id', $projectId)->value('workspace_id');
        }
        if ($workspaceId) {
            app(LiveSync::class)->record($workspaceId, $projectId, $privateFile ? 'team' : ($model instanceof Comment ? ($model->team_only ? 'team' : 'comments') : 'project'), $model->getConnection());
        }
        if ($model instanceof Task && ($model->wasRecentlyCreated || $model->wasChanged(['status', 'deleted_at']) || $model->trashed())) {
            app(ProjectFeedbacks::class)->invalidatePrompt($model);
        }
    }
}
