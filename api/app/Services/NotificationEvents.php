<?php

namespace App\Services;

class NotificationEvents
{
    public const TASK_CODES = ['client.task_created', 'client.comment_created', 'client.task_approved', 'client.task_status_changed', 'admin.clarification_requested', 'admin.comment_created', 'admin.task_review', 'admin.task_done'];

    public const CODES = [...self::TASK_CODES, 'client.project_comment_created', 'admin.project_comment_created', 'team.message_created'];

    public static function toTeam(string $event): bool
    {
        return str_starts_with($event, 'client.') || $event === 'team.message_created';
    }

    public static function defaults(): array
    {
        return array_fill_keys(self::CODES, ['email' => true, 'push' => true, 'telegram' => false]);
    }

    public static function fromHistory(string $actor, string $type, ?string $old, ?string $new): ?string
    {
        if ($actor === 'client') {
            if ($type === 'status' && $old !== $new && in_array($new, ['approved', 'done'], true)) {
                return 'client.task_status_changed';
            }

            return ['created' => 'client.task_created', 'comment' => 'client.comment_created', 'approved' => 'client.task_approved'][$type] ?? null;
        }
        if ($type === 'comment') {
            return 'admin.comment_created';
        }
        if ($type !== 'status' || $old === $new) {
            return null;
        }

        return ['clarification' => 'admin.clarification_requested', 'review' => 'admin.task_review', 'done' => 'admin.task_done'][$new] ?? null;
    }
}
