<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentAccess;
use App\Services\AgentPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentReadController extends Controller
{
    private function project(Request $request, string $uuid): Project
    {
        $project = AgentAccess::projects(AgentAccess::identity($request))->where('uuid', $uuid)->firstOrFail();
        $request->attributes->set('agent_read_project', $project);

        return $project;
    }

    private function page(Request $request, Builder $query, callable $data): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $page = $query->orderBy('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map($data), 'meta' => ['page' => $page->currentPage(), 'perPage' => 25, 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    private function taskData(Task $task): array
    {
        return ['id' => $task->uuid, 'projectId' => $task->project->uuid, 'title' => $task->title, 'description' => $task->description, 'expectedResult' => $task->expected_result, 'location' => $task->location, 'priority' => $task->priority, 'taskStatus' => $task->status, 'updatedAt' => $task->updated_at->toISOString()];
    }

    public function tasks(Request $request, string $project): JsonResponse
    {
        AgentAccess::scope($request, 'agent:projects:tasks:read');

        return $this->page($request, $this->project($request, $project)->tasks()->with('project')->getQuery(), fn ($task) => $this->taskData($task));
    }

    public function task(Request $request, string $project, string $task): JsonResponse
    {
        AgentAccess::scope($request, 'agent:projects:tasks:read');

        $model = $this->project($request, $project)->tasks()->where('uuid', $task)->firstOrFail();
        $request->attributes->set('agent_read_task', $model);

        return response()->json(['data' => $this->taskData($model)]);
    }

    private function taskComments(Request $request, Task $task): Builder
    {
        AgentAccess::scope($request, 'agent:comments:read');
        $internal = AgentAccess::tokenAllows($request, 'agent:team-chat:read');

        return Comment::withoutGlobalScope('public_channel')->where(fn ($project) => $project->where('project_id', $task->project_id)->orWhereNull('project_id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('agent_messages')->whereColumn('agent_messages.comment_id', 'comments.id'))->where('task_id', $task->id)->when(! $internal, fn ($q) => $q->where('team_only', false));
    }

    private function commentData(Comment $comment): array
    {
        return ['id' => $comment->uuid, 'text' => $comment->text, 'authorType' => $comment->author_type, 'teamOnly' => $comment->team_only, 'createdAt' => $comment->created_at->toISOString()];
    }

    public function comments(Request $request, string $task): JsonResponse
    {
        return $this->page($request, $this->taskComments($request, AgentAccess::accessibleTask($request, $task)), fn ($comment) => $this->commentData($comment));
    }

    private function discussions(Request $request, Project $project, bool $team): Builder
    {
        AgentAccess::scope($request, $team ? 'agent:team-chat:read' : 'agent:discussions:read');

        return Comment::withoutGlobalScope('public_channel')->where('project_id', $project->id)->whereNull('task_id')->where('team_only', $team);
    }

    public function discussionIndex(Request $request, string $project, string $channel): JsonResponse
    {
        return $this->page($request, $this->discussions($request, $this->project($request, $project), $channel === 'team-chat'), fn ($comment) => $this->commentData($comment));
    }

    private function fileData(Attachment $file): array
    {
        return ['id' => $file->uuid, 'name' => $file->original_name, 'size' => $file->size, 'type' => $file->mime_type, 'downloadUrl' => '/api/v1/agent/attachments/'.$file->uuid.'/download'];
    }

    private function taskFiles(Request $request, Task $task): Builder
    {
        $agent = AgentAccess::scope($request, 'agent:attachments:read');
        abort_unless(AgentPermissions::allows($agent, 'taskAttachments'), 403);
        $commentIds = AgentAccess::tokenAllows($request, 'agent:comments:read') ? $this->taskComments($request, $task)->select('id') : [];

        return Attachment::withoutGlobalScope('public_channel')->where('task_id', $task->id)->where(fn ($q) => $q->whereNull('comment_id')->orWhereIn('comment_id', $commentIds));
    }

    public function attachments(Request $request, string $task): JsonResponse
    {
        return $this->page($request, $this->taskFiles($request, AgentAccess::accessibleTask($request, $task)), fn ($file) => $this->fileData($file));
    }

    private function discussionFiles(Request $request, string $uuid): Builder
    {
        $agent = AgentAccess::scope($request, 'agent:attachments:read');
        abort_unless(AgentPermissions::allows($agent, 'discussionAttachments'), 403);
        $comment = Comment::withoutGlobalScope('public_channel')->whereNull('task_id')->where('uuid', $uuid)->firstOrFail();
        $project = AgentAccess::projects($agent)->whereKey($comment->project_id)->firstOrFail();
        $this->discussions($request, $project, $comment->team_only)->whereKey($comment->id)->firstOrFail();

        return Attachment::withoutGlobalScope('public_channel')->whereNull('task_id')->where('comment_id', $comment->id);
    }

    public function discussionAttachments(Request $request, string $comment): JsonResponse
    {
        return $this->page($request, $this->discussionFiles($request, $comment), fn ($file) => $this->fileData($file));
    }

    public function download(Request $request, string $attachment): StreamedResponse
    {
        AgentAccess::scope($request, 'agent:attachments:read');
        $file = Attachment::withoutGlobalScope('public_channel')->where('uuid', $attachment)->firstOrFail();
        if ($file->task_id) {
            $task = Task::whereKey($file->task_id)->firstOrFail();
            $this->taskFiles($request, AgentAccess::accessibleTask($request, $task->uuid))->whereKey($file->id)->firstOrFail();
            if ($file->comment_id) {
                $this->taskComments($request, $task)->whereKey($file->comment_id)->firstOrFail();
            }
        } else {
            $comment = Comment::withoutGlobalScope('public_channel')->whereKey($file->comment_id)->firstOrFail();
            $this->discussionFiles($request, $comment->uuid)->whereKey($file->id)->firstOrFail();
        }
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer']);
    }
}
