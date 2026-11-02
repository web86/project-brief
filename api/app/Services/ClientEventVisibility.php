<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\TaskHistory;
use Illuminate\Database\Eloquent\Builder;

/** The client contract is an allowlist. Internal audit data is never serialized. */
class ClientEventVisibility
{
    public const HISTORY_TYPES = ['created', 'updated', 'comment', 'approved', 'status', 'attachment', 'section_moved', 'reordered', 'restored'];

    public static function historyVisible(object $event): bool
    {
        $meta = is_array($event->meta) ? $event->meta : (json_decode($event->meta ?? '{}', true) ?? []);
        if (! in_array($event->event_type, self::HISTORY_TYPES, true)
            || ! in_array($event->actor_type, ['admin', 'client', 'system'], true)
            || ($meta['teamOnly'] ?? false)) {
            return false;
        }
        foreach (['agentId', 'agentName', 'assignmentId', 'runId', 'credentialId', 'agentReport', 'external_agent_id'] as $key) {
            if (array_key_exists($key, $meta)) {
                return false;
            }
        }
        if ($event->event_type === 'comment' && isset($meta['commentId'])) {
            return Comment::whereKey($meta['commentId'])->when(isset($event->task_id), fn ($q) => $q->where('task_id', $event->task_id))->exists();
        }

        return true;
    }

    public static function comments(Builder $query): Builder
    {
        return $query->where('comments.team_only', false)->whereNull('comments.external_agent_id')->whereIn('comments.author_type', ['admin', 'client']);
    }

    public static function publicValue(string $type, ?string $value): ?string
    {
        return match ($type) {
            'status', 'approved' => array_key_exists($value ?? '', TaskAudit::STATUSES) ? $value : null,
            'section_moved', 'reordered' => preg_match('/^\\d+(?:\\.\\d+)*$/D', $value ?? '') ? $value : null,
            default => null,
        };
    }

    public static function historyData(TaskHistory $event): array
    {
        return ['id' => (string) $event->id, 'type' => $event->event_type,
            'actorType' => $event->actor_type === 'admin' ? 'developer' : ($event->actor_type === 'client' ? 'client' : 'system'),
            'actorName' => $event->actor_type === 'client' ? $event->projectClient?->name : null,
            'oldValue' => self::publicValue($event->event_type, $event->old_value),
            'newValue' => self::publicValue($event->event_type, $event->new_value),
            'text' => trans('interface.task_changed'), 'createdAt' => $event->created_at->toISOString()];
    }
}
