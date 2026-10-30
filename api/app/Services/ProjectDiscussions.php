<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectClient;
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

    public function unread(Builder $query, User|ProjectClient $user): Builder
    {
        $client = $user instanceof ProjectClient;
        $table = $client ? 'client_comment_reads' : 'comment_reads';
        $key = $client ? 'project_client_id' : 'user_id';
        $authorKey = $client ? 'project_client_id' : 'author_user_id';

        return $query->where(fn (Builder $author) => $author->where('author_type', '!=', $client ? 'client' : 'admin')
            ->orWhereNull($authorKey)->orWhere($authorKey, '!=', $user->id))
            ->whereNotExists(fn ($reads) => $reads->selectRaw('1')->from($table)
                ->whereColumn($table.'.comment_id', 'comments.id')->where($table.'.'.$key, $user->id));
    }

    public function count(Project $project, User|ProjectClient $user): int
    {
        return $this->unread($this->query($project)->when($user instanceof User, fn ($query) => $query->withoutGlobalScope('public_channel')), $user)->count();
    }

    /** @return array{general: int, tasks: int} */
    public function counts(Project $project, User|ProjectClient $user): array
    {
        $counts = $this->unread($this->query($project)->when($user instanceof User, fn ($query) => $query->withoutGlobalScope('public_channel')), $user)->toBase()
            ->selectRaw('COUNT(CASE WHEN comments.task_id IS NULL THEN 1 END) AS general, COUNT(CASE WHEN comments.task_id IS NOT NULL THEN 1 END) AS tasks')->first();

        return ['general' => (int) $counts->general, 'tasks' => (int) $counts->tasks];
    }

    public function mark(Builder $query, User|ProjectClient $user): int
    {
        // One INSERT SELECT snapshots the selected comments; concurrent newer comments remain unread.
        $client = $user instanceof ProjectClient;
        $key = $client ? 'project_client_id' : 'user_id';

        return DB::table($client ? 'client_comment_reads' : 'comment_reads')->insertOrIgnoreUsing([$key, 'comment_id'],
            $this->unread($query, $user)->selectRaw('? as '.$key.', comments.id', [$user->id])->toBase());
    }

    public function data(Comment $comment, bool $unread = false): array
    {
        return [
            ...(request()->is('api/admin/*') && $comment->author_type === 'admin' && $comment->authorUser ? ['avatarUrl' => app(AccountProfile::class)->state($comment->authorUser)['avatarUrl']] : []),
            'id' => $comment->uuid, 'author' => $comment->author_type === 'admin' ? 'developer' : 'client',
            'authorId' => $comment->author_type === 'admin' ? $comment->author_user_id : $comment->projectClient?->uuid,
            'authorName' => $comment->author_type === 'admin' ? $comment->authorUser?->name : $comment->projectClient?->name,
            'attachments' => $comment->attachments->map(fn ($file) => $file->data(TaskAccess::isAdmin(request()) ? 'admin' : 'client'))->values(), 'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString(), 'unread' => $unread,
            'task' => $comment->task ? ['id' => $comment->task->uuid, 'title' => $comment->task->title, 'section' => $comment->task->projectSection?->name] : null];
    }
}
