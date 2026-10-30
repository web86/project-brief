<?php

namespace App\Services;

use App\Events\ProjectNotificationRequested;
use App\Http\Requests\CommentRequest;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;

class CommentSubmission
{
    public static function store(CommentRequest $request, Project $project, ?Task $task = null, bool $teamOnly = false): Comment
    {
        return AttachmentStorage::atomic(function (array &$paths) use ($request, $project, $task, $teamOnly) {
            // Serialize retries in this project before checking the unique submission key.
            if ($teamOnly) {
                app(TeamChat::class)->authorize($request, $project, true);
            }
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($task) {
                $task = TaskAccess::task($request, $task->uuid, true);
            }
            abort_if($task && $task->project_id !== $project->id, 422);
            abort_if($teamOnly && $project->status !== 'active', 404);
            $admin = TaskAccess::isAdmin($request);
            $recipient = TaskAccess::recipient($request);
            $actor = $admin ? 'admin' : 'client';
            $text = trim($request->validated('text') ?? '');
            $key = $request->validated('submissionId') ? hash('sha256', implode(':', [$project->id, $task?->id, $teamOnly ? 'team' : 'public', $actor, $recipient->id, $request->validated('submissionId')])) : null;
            $fingerprint = hash('sha256', json_encode([$text, array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getPathname())], $request->file('attachments', []))], JSON_THROW_ON_ERROR));
            if ($key && ($existing = Comment::withoutGlobalScope('public_channel')->where('team_only', $teamOnly)->where('submission_key', $key)->first())) {
                abort_unless(hash_equals($existing->submission_fingerprint, $fingerprint), 409, 'Submission already used for another comment.');

                return $existing;
            }
            $comment = Comment::create(['team_only' => $teamOnly, 'project_id' => $project->id, 'task_id' => $task?->id, 'text' => $text,
                'author_type' => $actor, 'author_user_id' => $admin ? $recipient->id : null, 'project_client_id' => $admin ? null : $recipient->id,
                'submission_key' => $key, 'submission_fingerprint' => $fingerprint]);
            AttachmentStorage::storeCommentFiles($request, $comment, $task, $paths);
            if ($task) {
                TaskAudit::record($request, $task, 'comment', ($admin ? 'Разработчик' : 'Клиент').' добавил комментарий', metadata: ['commentId' => $comment->id]);
            } else {
                event(new ProjectNotificationRequested($comment->id, $teamOnly ? 'team.message_created' : $actor.'.project_comment_created', null, $actor, $recipient->id, $recipient->name, $comment->id));
            }

            if (! $teamOnly) {
                app(WebhookEvents::class)->clientComment($comment, $project, $task);
            }

            return $comment;
        });
    }
}
