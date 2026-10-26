<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookEvents
{
    public const TYPES = ['project.created', 'project.updated', 'task.created', 'task.updated', 'task.status_changed', 'task.approved', 'task.client_comment_created', 'project.client_comment_created'];

    public function project(Project $project, string $type, array $changes): void
    {
        $changes = array_intersect_key($changes, array_flip(['title', 'website_url', 'currency', 'status']));
        if ($changes) {
            $this->emit($project, null, $type, $changes);
        }
    }

    public function task(Task $task, string $auditType, ?string $old = null, ?string $new = null): void
    {
        $type = ['created' => 'task.created', 'updated' => 'task.updated', 'section_moved' => 'task.updated', 'status' => 'task.status_changed', 'approved' => 'task.approved'][$auditType] ?? null;
        if (! $type || ($auditType === 'status' && $old === $new)) {
            return;
        }
        $changes = match ($auditType) {
            'status' => ['status' => ['from' => $old, 'to' => $new]],
            'approved' => ['clientApproved' => true, 'clientApprovedAt' => $task->client_approved_at?->toISOString()],
            'section_moved' => ['number' => ['from' => $old, 'to' => $new]],
            default => ['title' => $task->title, 'location' => $task->location, 'priority' => $task->priority, 'sectionId' => $task->projectSection?->uuid],
        };
        $this->emit($task->project, $task, $type, $changes);
    }

    public function clientComment(Comment $comment, Project $project, ?Task $task): void
    {
        if ($comment->author_type !== 'client') {
            return;
        }
        $this->enqueue($project->workspace_id, $task ? 'task.client_comment_created' : 'project.client_comment_created', [
            'workspaceId' => $project->workspace->uuid, 'projectId' => $project->uuid, 'taskId' => $task?->uuid,
            'commentId' => $comment->uuid, 'actor' => ['type' => 'client', 'id' => $comment->projectClient->uuid],
            'changes' => (object) [], 'origin' => 'ui',
        ]);
    }

    public function emit(Project $project, ?Task $task, string $type, array $changes): void
    {
        $request = request();
        $token = $request->attributes->get('integration_token');
        $client = $request->attributes->get('project_client');
        $actor = $token ? ['type' => 'integration', 'id' => $token->uuid] : ($client ? ['type' => 'client', 'id' => $client->uuid] : ['type' => $request->user() ? 'admin' : 'system', 'id' => $request->user()?->uuid]);
        $this->enqueue($project->workspace_id, $type, ['workspaceId' => $project->workspace->uuid, 'projectId' => $project->uuid, 'taskId' => $task?->uuid, 'actor' => $actor, 'changes' => $changes, 'origin' => $token ? 'api' : 'ui']);
    }

    public function enqueue(int $workspaceId, string $type, array $payload, ?int $onlyEndpoint = null): ?string
    {
        return DB::transaction(function () use ($workspaceId, $type, $payload, $onlyEndpoint): ?string {
            $endpoints = DB::table('webhook_endpoints')->where('workspace_id', $workspaceId)->where('enabled', true)->when($onlyEndpoint, fn ($q) => $q->where('id', $onlyEndpoint))->lockForUpdate()->get()->filter(fn ($e) => ($onlyEndpoint || in_array($type, json_decode($e->events, true), true)) && app(WebhookProjectFilter::class)->matches($e, $type, $payload['projectId'] ?? null));
            if ($endpoints->isEmpty()) {
                return null;
            }
            $uuid = (string) Str::uuid();
            $body = json_encode(['version' => 1, 'id' => $uuid, 'type' => $type, 'occurredAt' => now()->toISOString(), ...$payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($body) > 131072) {
                throw new \LogicException('Webhook event exceeds 128 KiB.');
            }
            $event = DB::table('webhook_events')->insertGetId(['uuid' => $uuid, 'workspace_id' => $workspaceId, 'type' => $type, 'body' => $body, 'created_at' => now()]);
            foreach ($endpoints as $endpoint) {
                DB::table('webhook_deliveries')->insert(['uuid' => (string) Str::uuid(), 'webhook_endpoint_id' => $endpoint->id, 'webhook_event_id' => $event, 'secret_version' => $endpoint->secret_version, 'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $uuid;
        });
    }
}
