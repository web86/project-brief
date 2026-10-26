<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProjectLifecycle
{
    /** The caller holds the workspace and project locks inside a transaction. */
    public function trash(Project $project): void
    {
        $this->lockEndpoints($project);
        $project->forceFill(['status_before_trash' => $project->status, 'status' => 'inactive'])->save();
        $project->delete();
        DB::table('webhook_deliveries')->whereIn('webhook_event_id', $this->events($project)->select('id'))
            ->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => 'project_trashed', 'updated_at' => now()]);
    }

    public function restore(Project $project): void
    {
        $project->forceFill(['status' => $project->status_before_trash ?? 'inactive', 'status_before_trash' => null]);
        $project->restore();
    }

    public function purge(Project $project): void
    {
        abort_unless($project->trashed(), 409);
        $this->lockEndpoints($project);
        $taskIds = Task::withTrashed()->where('project_id', $project->id)->select('id');
        $commentIds = DB::table('comments')->where('project_id', $project->id)->orWhereIn('task_id', $taskIds)->select('id');
        Attachment::where(fn ($query) => $query->whereIn('task_id', $taskIds)->orWhereIn('comment_id', $commentIds))->orderBy('id')->chunkById(200, function ($files): void {
            foreach ($files as $file) {
                foreach (array_filter([$file->path, $file->thumbnail_path]) as $path) {
                    DB::table('project_file_deletions')->insert(['disk' => $file->disk, 'path' => $path]);
                }
                $file->delete();
            }
        });
        $this->events($project)->delete();
        DB::table('integration_requests')->where('workspace_id', $project->workspace_id)
            ->where(fn ($q) => $q->where('response->data->id', $project->uuid)->orWhere('response->data->projectId', $project->uuid))
            ->update(['response' => json_encode(['message' => 'Resource unavailable.']), 'status' => 410]);
        $project->members()->delete();
        DeveloperInvitation::where('workspace_id', $project->workspace_id)->orderBy('id')->chunkById(200, function ($invitations) use ($project): void {
            foreach ($invitations as $invitation) {
                $ids = array_values(array_filter($invitation->project_ids, fn ($id) => (int) $id !== $project->id));
                if ($ids !== $invitation->project_ids) {
                    $invitation->forceFill(['project_ids' => $ids, ...($ids === [] && ! $invitation->accepted_at ? ['revoked_at' => now()] : [])])->save();
                }
            }
        });
        $project->forceDelete();
    }

    private function events(Project $project): Builder
    {
        return DB::table('webhook_events')->where('workspace_id', $project->workspace_id)->where('body->projectId', $project->uuid);
    }

    private function lockEndpoints(Project $project): void
    {
        DB::table('webhook_endpoints')->where('workspace_id', $project->workspace_id)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** Durable entries survive process/storage failures; retries are idempotent. */
    public function cleanupFiles(): int
    {
        $failed = 0;
        DB::table('project_file_deletions')->orderBy('id')->chunkById(200, function ($files) use (&$failed): void {
            foreach ($files as $file) {
                try {
                    $disk = Storage::disk($file->disk);
                    if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
                        $failed++;

                        continue;
                    }
                    DB::table('project_file_deletions')->where('id', $file->id)->delete();
                } catch (Throwable) {
                    $failed++;
                }
            }
        });

        return $failed;
    }
}
