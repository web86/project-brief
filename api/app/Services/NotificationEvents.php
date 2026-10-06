<?php

namespace App\Services;

class NotificationEvents
{
    public const CODES = ['client.task_created', 'client.comment_created', 'client.task_approved', 'admin.clarification_requested', 'admin.comment_created', 'admin.task_review', 'admin.task_done'];

    public static function defaults(): array
    {
        return array_fill_keys(self::CODES, ['email' => true, 'push' => true]);
    }

    public static function fromHistory(string $actor, string $type, ?string $old, ?string $new): ?string
    {
        if ($actor === 'client') {
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
