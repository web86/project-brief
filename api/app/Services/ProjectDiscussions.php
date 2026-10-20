<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProjectDiscussions
{
    public function query(Project $project): Builder
    {
        return Comment::query()->where(fn (Builder $query) => $query
            ->whereHas('task', fn (Builder $tasks) => $tasks->where('project_id', $project->id))
            ->orWhere(fn (Builder $general) => $general->whereNull('task_id')->where('project_id', $project->id)));
    }

    public function unread(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $author) => $author->where('author_type', '!=', 'admin')
            ->orWhereNull('author_user_id')->orWhere('author_user_id', '!=', $user->id))
            ->whereNotExists(fn ($reads) => $reads->selectRaw('1')->from('comment_reads')
                ->whereColumn('comment_reads.comment_id', 'comments.id')->where('comment_reads.user_id', $user->id));
    }

    public function count(Project $project, User $user): int
    {
        return $this->unread($this->query($project), $user)->count();
    }

    public function mark(Builder $query, User $user): void
    {
        // One INSERT SELECT snapshots the selected comments; concurrent newer comments remain unread.
        DB::table('comment_reads')->insertOrIgnoreUsing(['user_id', 'comment_id'],
            $this->unread($query, $user)->selectRaw('? as user_id, comments.id', [$user->id])->toBase());
    }

    public function data(Comment $comment, bool $unread = false): array
    {
        return ['id' => $comment->uuid, 'author' => $comment->author_type === 'admin' ? 'developer' : 'client',
            'authorId' => $comment->author_type === 'admin' ? $comment->author_user_id : $comment->projectClient?->uuid,
            'authorName' => $comment->author_type === 'admin' ? ($comment->authorUser?->name ?? 'Developer') : ($comment->projectClient?->name ?? 'Client'),
            'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString(), 'unread' => $unread,
            'task' => $comment->task ? ['id' => $comment->task->uuid, 'title' => $comment->task->title, 'section' => $comment->task->projectSection?->name] : null];
    }
}
