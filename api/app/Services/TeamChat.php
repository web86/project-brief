<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TeamChat
{
    public function authorize(Request $request, Project $project, bool $lock = false): void
    {
        abort_unless(TaskAccess::isAdmin($request), 403);
        $member = app(CurrentWorkspace::class)->resolve($request, $lock);
        abort_unless($member->workspace_id === $project->workspace_id && ! $project->trashed() && $project->status === 'active', 404);
        if ($member->role !== 'owner') {
            abort_unless(ProjectMember::where('workspace_id', $member->workspace_id)->where('workspace_member_id', $member->id)->where('project_id', $project->id)->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null, 404);
        }
    }

    public function query(Project $project): Builder
    {
        return Comment::withoutGlobalScope('public_channel')->where('project_id', $project->id)->whereNull('task_id')->where('team_only', true);
    }

    public function count(Project $project, User $user): int
    {
        return app(ProjectDiscussions::class)->unread($this->query($project), $user)->count();
    }

    public function data(Comment $comment, Request $request, ?Project $project = null): array
    {
        $comment->loadMissing(['authorUser', 'attachments' => fn ($query) => $query->withoutGlobalScope('public_channel')]);
        $files = $comment->attachments;
        $projectUuid = $project?->uuid ?? Project::whereKey($comment->project_id)->value('uuid');

        return ['id' => $comment->uuid, 'sequence' => $comment->id, 'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString(),
            'own' => $comment->author_user_id === $request->user()->id, 'authorId' => $comment->author_user_id, 'authorName' => $comment->authorUser?->name ?? trans('notifications.developer'),
            'avatarUrl' => $comment->authorUser ? app(AccountProfile::class)->state($comment->authorUser)['avatarUrl'] : null,
            'attachments' => $files->map(function ($file) use ($projectUuid): array {
                $base = '/api/admin/projects/'.$projectUuid.'/team-chat/files/'.$file->uuid;

                return ['id' => $file->uuid, 'name' => $file->original_name, 'type' => $file->mime_type, 'size' => $file->size,
                    'downloadUrl' => $base.'/download', 'originalUrl' => $base.'/original', 'preview' => $file->thumbnail_path ? $base.'/preview' : null];
            })->values()];
    }
}
