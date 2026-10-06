<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Str;

class NotificationPayload
{
    public static function make(string $event, Task $task, string $actor, string $locale, bool $admin, string $eventId): array
    {
        $project = $task->project;
        $number = ProjectStructure::number($task) ?? '';
        $eventText = trans('notifications.events.'.$event, [], $locale);
        $url = rtrim(config('app.url'), '/').($admin ? '/admin/projects/'.$project->uuid.'/brief/task/' : '/project/'.$project->uuid.'/task/').$task->uuid;

        return ['title' => Str::limit($eventText.' · '.$project->title, 120), 'body' => Str::limit(trim($number.' '.$task->title), 150),
            'url' => $url, 'tag' => 'projectbrief-'.$eventId, 'event' => $event, 'project_uuid' => $project->uuid, 'task_uuid' => $task->uuid,
            'project' => $project->title, 'task_title' => $task->title, 'number' => $number, 'actor' => $actor, 'locale' => $locale,
            'heading' => $eventText, 'subject' => '[ProjectBrief] '.$eventText.' — '.Str::limit($project->title, 80),
            'open' => trans('notifications.open', [], $locale), 'access' => trans('notifications.access', [], $locale),
            'by' => trans('notifications.by', ['name' => $actor], $locale)];
    }

    public static function push(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['title', 'body', 'url', 'tag', 'event', 'project_uuid', 'task_uuid']));
    }

    public static function test(string $locale, string $url): array
    {
        return ['title' => trans('notifications.test_title', [], $locale), 'body' => trans('notifications.test_body', [], $locale), 'url' => $url, 'tag' => 'projectbrief-test', 'event' => 'test',
            'project' => 'ProjectBrief', 'task_title' => '', 'number' => '', 'actor' => '', 'locale' => $locale, 'heading' => trans('notifications.test_title', [], $locale),
            'subject' => '[ProjectBrief] '.trans('notifications.test_title', [], $locale), 'open' => trans('notifications.open', [], $locale), 'access' => trans('notifications.access',[],$locale), 'by' => ''];
    }
}
