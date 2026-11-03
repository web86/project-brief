<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Task;
use App\Models\TaskAgentAssignment;

class AgentChat
{
    public static function messages(TaskAgentAssignment $assignment, ?string $before = null, bool $files = true, bool $agentApi = false): array
    {
        $messages = AgentRuns::conversation($assignment, $before, $agentApi);
        $comments = Comment::withoutGlobalScope('public_channel')->where('task_id', $assignment->task_id)->where('project_id', $assignment->task->project_id)->where('team_only', true)->whereIn('uuid', array_column($messages, 'commentId'))->with(['attachments' => fn ($q) => $q->withoutGlobalScope('public_channel'), 'agentReport'])->get()->keyBy('uuid');

        return array_map(function (array $message) use ($comments, $assignment, $files, $agentApi): array {
            $comment = $comments->get($message['commentId']);
            $attachments = $files && $comment ? $comment->attachments->map(function ($file) use ($assignment, $message, $agentApi): array {
                if (! $agentApi) {
                    return $file->data('admin');
                }

                return ['id' => $file->uuid, 'name' => $file->original_name, 'size' => $file->size, 'type' => $file->mime_type,
                    'downloadUrl' => '/api/v1/agent/tasks/'.$assignment->task->uuid.'/runs/'.$message['runId'].'/attachments/'.$file->uuid.'/download'];
            })->values()->all() : [];

            return [...$message, 'attachments' => $attachments, 'report' => $comment?->agentReport?->data()];
        }, $messages);
    }

    public static function legacy(Task $task, ?string $before = null): array
    {
        $query = Comment::withoutGlobalScope('public_channel')->where('task_id', $task->id)->where('author_type', 'agent')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('agent_messages')->whereColumn('agent_messages.comment_id', 'comments.id'));
        if ($before) {
            $id = (clone $query)->where('uuid', $before)->value('id');
            abort_unless($id, 404);
            $query->where('id', '<', $id);
        }
        $comments = $query->with(['agent', 'agentReport', 'attachments' => fn ($q) => $q->withoutGlobalScope('public_channel')])->orderByDesc('id')->limit(200)->get();
        $messages = $comments->reverse()->values()->map(fn ($comment) => ['id' => $comment->uuid, 'commentId' => $comment->uuid, 'teamOnly' => (bool) $comment->team_only, 'author' => 'agent', 'agentId' => $comment->agent?->uuid, 'agentName' => $comment->agent?->name,
            'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString(), 'attachments' => $comment->attachments->map(fn ($file) => $file->data('admin'))->values(), 'report' => $comment->agentReport?->data()])->all();

        return ['data' => $messages, 'nextCursor' => count($messages) === 200 ? $messages[0]['id'] : null];
    }
}
