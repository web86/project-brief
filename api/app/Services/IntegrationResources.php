<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;

class IntegrationResources
{
    public static function attachment(Attachment $file): array
    {
        return ['id' => $file->uuid, 'name' => $file->original_name, 'mimeType' => $file->mime_type, 'size' => $file->size];
    }

    public static function comment(Comment $comment, Project $project): array
    {
        $comment->loadMissing(['task', 'projectClient', 'attachments']);

        return ['id' => $comment->uuid, 'projectId' => $project->uuid, 'taskId' => $comment->task?->uuid,
            'author' => ['type' => $comment->author_type, 'id' => $comment->author_type === 'client' ? $comment->projectClient?->uuid : null],
            'text' => $comment->text, 'createdAt' => $comment->created_at->toISOString(),
            'attachments' => $comment->attachments->filter(fn ($file) => $file->task_id === $comment->task_id)->map(fn ($file) => self::attachment($file))->values()->all()];
    }

    public static function project(Project $project): array
    {
        return ['id' => $project->uuid, 'title' => $project->title, 'description' => $project->description, 'language' => $project->language, 'website' => $project->website_url, 'currency' => $project->currency, 'status' => $project->status, 'managerUrl' => '/admin/projects/'.$project->uuid.'/brief', 'sections' => $project->briefSections->map(fn ($s) => ProjectStructure::sectionData($s))->all(), 'createdAt' => $project->created_at->toISOString(), 'updatedAt' => $project->updated_at->toISOString()];
    }

    public static function task(Task $task, bool $estimates): array
    {
        return ['id' => $task->uuid, 'projectId' => $task->project->uuid, 'sectionId' => $task->projectSection?->uuid, 'number' => ProjectStructure::number($task), 'title' => $task->title, 'location' => $task->location, 'description' => $task->description, 'expectedResult' => $task->expected_result, 'priority' => $task->priority, 'status' => $task->status, 'clientApproved' => $task->client_approved, 'clientApprovedAt' => $task->client_approved_at?->toISOString(), ...($estimates ? ['estimateHours' => $task->estimate_hours, 'price' => $task->price] : [])];
    }
}
