<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;

class NotificationPayload
{
    /** Bounds are Unicode characters, with an ellipsis appended when truncated. */
    public const COMMENT_LIMIT = 2000;

    public const DESCRIPTION_LIMIT = 600;

    public const PUSH_LIMIT = 150;

    public static function excerpt(string $text, int $limit): string
    {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8').'…' : $text;
    }

    private static function comment(Task $task, string $event, string $eventId, string $locale): ?string
    {
        $history = $task->history()->whereKey($eventId)->first();
        if (! $history || NotificationEvents::fromHistory($history->actor_type, $history->event_type, $history->old_value, $history->new_value) !== $event || $history->event_type !== 'comment') {
            return null;
        }
        // The ID is recorded server-side in the same transaction as the comment and audit.
        $comment = $task->comments()->whereKey($history->meta['commentId'] ?? null)
            ->where('author_type', $history->actor_type)
            ->where('author_user_id', $history->actor_user_id)
            ->where('project_client_id', $history->project_client_id)->first();

        return $comment ? self::excerpt(self::commentText($comment, $locale), self::COMMENT_LIMIT) : null;
    }

    public static function commentText(Comment $comment, string $locale): string
    {
        if (trim($comment->text) !== '') {
            return $comment->text;
        }
        $files = $comment->attachments;
        $image = $files->count() === 1 && str_starts_with($files->first()->mime_type, 'image/');
        $actor = trans('notifications.'.($comment->author_type === 'admin' ? 'developer' : 'client'), [], $locale);

        return $image ? trans('notifications.attached_image', ['actor' => $actor], $locale)
            : trans_choice('notifications.attached_files', $files->count(), ['actor' => $actor, 'count' => $files->count()], $locale);
    }

    public static function make(string $event, Task $task, string $actor, string $locale, bool $admin, string $eventId): array
    {
        $project = $task->project;
        $number = ProjectStructure::number($task) ?? '';
        $eventText = trans('notifications.events.'.$event, [], $locale);
        $url = rtrim(config('app.url'), '/').($admin ? '/admin/projects/'.$project->uuid.'/brief/task/' : '/project/'.$project->uuid.'/task/').$task->uuid;
        if (str_ends_with($event, '.comment_created')) {
            $history = $task->history()->whereKey($eventId)->first();
            $comment = $task->comments()->whereKey($history?->meta['commentId'] ?? null)->first();
            if ($comment) {
                $url .= '#comment-'.$comment->uuid;
            }
        }
        $history = $event === 'client.task_status_changed' ? $task->history()->whereKey($eventId)->first() : null;
        $message = match ($event) {
            'client.task_status_changed' => $history ? trans('exports.statuses.'.$history->old_value, [], $locale).' → '.trans('exports.statuses.'.$history->new_value, [], $locale) : null,
            'client.task_created' => self::excerpt($task->description ?? '', self::DESCRIPTION_LIMIT),
            'client.comment_created', 'admin.comment_created' => self::comment($task, $event, $eventId, $locale),
            default => null,
        };
        $body = $message !== null && $message !== '' && str_ends_with($event, '.comment_created')
            ? self::excerpt($actor, 60).': '.preg_replace('/\s+/u', ' ', $message)
            : trim('#'.$number.' '.$task->title);

        return ['title' => self::excerpt($eventText.' · '.$project->title, 120), 'body' => self::excerpt($body, self::PUSH_LIMIT),
            'url' => $url, 'tag' => 'projectbrief-'.$eventId, 'event' => $event, 'project_uuid' => $project->uuid, 'task_uuid' => $task->uuid,
            'project' => $project->title, 'task_title' => $task->title, 'number' => $number, 'actor' => $actor, 'locale' => $locale,
            'heading' => $eventText, 'subject' => '[ProjectBrief] '.$eventText.' — '.self::excerpt($project->title, 80),
            'explanation' => trans('notifications.explanations.'.$event, ['name' => $actor, 'project' => $project->title], $locale),
            'next' => trans('notifications.next.'.$event, [], $locale),
            'message' => $message, 'message_label' => trans($event === 'client.task_status_changed' ? 'notifications.status_change' : ($event === 'client.task_created' ? 'notifications.description' : 'notifications.comment'), [], $locale),
            'project_label' => trans('notifications.project', [], $locale), 'task_label' => trans('notifications.task', [], $locale),
            'open' => trans('notifications.open', [], $locale), 'access' => trans('notifications.access', [], $locale)];
    }

    public static function discussion(string $event, Comment $comment, string $actor, string $locale, bool $admin): array
    {
        $project = $comment->project_id ? Project::findOrFail($comment->project_id) : null;
        $url = rtrim(config('app.url'), '/').($admin ? '/admin/projects/' : '/project/').$project->uuid.'/discussions#comment-'.$comment->uuid;
        $payload = self::test($locale, $url);
        $heading = trans('notifications.discussion_heading', [], $locale);

        return array_replace($payload, ['title' => self::excerpt($heading.' · '.$project->title, 120), 'heading' => $heading,
            'subject' => '[ProjectBrief] '.$heading.' — '.self::excerpt($project->title, 80), 'project' => $project->title,
            'actor' => $actor, 'event' => $event, 'tag' => 'projectbrief-comment-'.$comment->id,
            'project_uuid' => $project->uuid, 'body' => self::excerpt($actor.': '.self::commentText($comment, $locale), self::PUSH_LIMIT),
            'message' => self::excerpt(self::commentText($comment, $locale), self::COMMENT_LIMIT), 'message_label' => trans('notifications.comment', [], $locale),
            'explanation' => trans('notifications.discussion_explanation', ['name' => $actor], $locale),
            'open' => trans('notifications.open_discussion', [], $locale)]);
    }

    public static function team(Comment $comment, Project $project, string $locale): array
    {
        $heading = trans('notifications.team_heading', [], $locale);
        $body = trans('notifications.team_explanation', [], $locale);
        $payload = self::test($locale, rtrim(config('app.url'), '/').'/admin/projects/'.$project->uuid.'/discussions#team-'.$comment->uuid);

        return array_replace($payload, ['title' => $heading, 'heading' => $heading, 'subject' => '[ProjectBrief] '.$heading,
            'body' => $body, 'explanation' => $body, 'project' => $project->title, 'project_uuid' => $project->uuid,
            'event' => 'team.message_created', 'tag' => 'projectbrief-team-'.$comment->id]);
    }

    public static function push(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['title', 'body', 'url', 'tag', 'event', 'project_uuid', 'task_uuid']));
    }

    public static function test(string $locale, string $url): array
    {
        return ['title' => trans('notifications.test_title', [], $locale), 'body' => trans('notifications.test_body', [], $locale), 'url' => $url, 'tag' => 'projectbrief-test', 'event' => 'test',
            'project' => 'ProjectBrief', 'task_title' => '', 'number' => '', 'actor' => '', 'locale' => $locale, 'heading' => trans('notifications.test_title', [], $locale),
            'subject' => '[ProjectBrief] '.trans('notifications.test_title', [], $locale), 'explanation' => trans('notifications.test_body', [], $locale),
            'next' => '', 'message' => null, 'message_label' => '', 'project_label' => trans('notifications.project', [], $locale), 'task_label' => trans('notifications.task', [], $locale),
            'open' => trans('notifications.open_project', [], $locale), 'access' => trans('notifications.access', [], $locale)];
    }
}
