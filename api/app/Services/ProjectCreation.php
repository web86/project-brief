<?php

namespace App\Services;

use App\Models\Project;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

class ProjectCreation
{
    public function create(int $workspaceId, array $data, array $input, ?WorkspaceMember $member = null): Project
    {
        return DB::transaction(function () use ($workspaceId, $data, $input, $member): Project {
            $project = Project::create([...$data, 'workspace_id' => $workspaceId]);
            if ($member && $member->role !== 'owner') {
                $project->members()->create(['workspace_id' => $workspaceId, 'workspace_member_id' => $member->id]);
            }
            foreach ($input['sections'] ?? trans('interface.sections') as $index => $name) {
                $project->briefSections()->create(['name' => $name, 'position' => $index + 1]);
            }
            if (! empty($input['clientName']) || ! empty($input['clientEmail'])) {
                $project->clients()->create(['name' => ($input['clientName'] ?? null) ?: trans('interface.client'), 'email' => $input['clientEmail'] ?? null]);
            }
            $project->refresh();
            app(WebhookEvents::class)->project($project, 'project.created', array_intersect_key($project->getAttributes(), array_flip(['title', 'website_url', 'currency', 'status'])));

            return $project;
        });
    }
}
