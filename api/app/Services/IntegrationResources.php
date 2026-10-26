<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;

class IntegrationResources
{
    public static function project(Project $project): array
    {
        return ['id' => $project->uuid, 'title' => $project->title, 'website' => $project->website_url, 'currency' => $project->currency, 'status' => $project->status, 'managerUrl' => '/admin/projects/'.$project->uuid.'/brief', 'sections' => $project->briefSections->map(fn ($s) => ProjectStructure::sectionData($s))->all(), 'createdAt' => $project->created_at->toISOString(), 'updatedAt' => $project->updated_at->toISOString()];
    }

    public static function task(Task $task, bool $estimates): array
    {
        return ['id' => $task->uuid, 'projectId' => $task->project->uuid, 'sectionId' => $task->projectSection?->uuid, 'number' => ProjectStructure::number($task), 'title' => $task->title, 'location' => $task->location, 'description' => $task->description, 'expectedResult' => $task->expected_result, 'priority' => $task->priority, 'status' => $task->status, 'clientApproved' => $task->client_approved, 'clientApprovedAt' => $task->client_approved_at?->toISOString(), ...($estimates ? ['estimateHours' => $task->estimate_hours, 'price' => $task->price] : [])];
    }
}
