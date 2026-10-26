<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebhookProjectFilter
{
    /** Lock selected project parents before endpoint locks, matching business writes' project → endpoint order. */
    public function input(int $workspaceId, array $data, ?object $endpoint): ?array
    {
        if ($endpoint && ! array_key_exists('projectFilter', $data) && ! array_key_exists('projectIds', $data)) {
            return null;
        }
        $mode = $data['projectFilter'] ?? $endpoint?->project_filter ?? 'all';
        $uuids = $data['projectIds'] ?? ($mode === 'selected' && $endpoint ? $this->ids($endpoint) : []);
        if (($mode === 'selected' && count($uuids) === 0) || ($mode === 'all' && count($uuids) > 0)) {
            throw ValidationException::withMessages(['projectIds' => 'Select at least one project, or use all projects with an empty list.']);
        }
        $projects = Project::where('workspace_id', $workspaceId)->whereIn('uuid', $uuids)->orderBy('id')->sharedLock()->get();
        if ($projects->count() !== count($uuids)) {
            throw ValidationException::withMessages(['projectIds' => 'One or more selected projects are unavailable.']);
        }

        return ['mode' => $mode, 'ids' => $projects->pluck('id')->sort()->values()->all()];
    }

    public function ids(object $endpoint): array
    {
        return DB::table('webhook_endpoint_projects')->join('projects', function ($join): void {
            $join->on('projects.id', '=', 'webhook_endpoint_projects.project_id')->on('projects.workspace_id', '=', 'webhook_endpoint_projects.workspace_id');
        })->whereNull('projects.deleted_at')->where('webhook_endpoint_id', $endpoint->id)->where('webhook_endpoint_projects.workspace_id', $endpoint->workspace_id)->orderBy('projects.uuid')->pluck('projects.uuid')->all();
    }

    public function matches(object $endpoint, string $type, ?string $projectUuid): bool
    {
        if ($type === 'webhook.test') {
            return $projectUuid === null;
        }
        if (! $projectUuid) {
            return false;
        }

        return Project::where('workspace_id', $endpoint->workspace_id)->where('uuid', $projectUuid)
            ->when($endpoint->project_filter !== 'all', fn ($query) => $query->whereIn('id', DB::table('webhook_endpoint_projects')->where('workspace_id', $endpoint->workspace_id)->where('webhook_endpoint_id', $endpoint->id)->select('project_id')))->exists();
    }

    public function replace(object $endpoint, array $filter): bool
    {
        $previous = DB::table('webhook_endpoint_projects')->where('webhook_endpoint_id', $endpoint->id)->where('workspace_id', $endpoint->workspace_id)->orderBy('project_id')->pluck('project_id')->map(fn ($id) => (int) $id)->all();
        if ($endpoint->project_filter === $filter['mode'] && $previous === $filter['ids']) {
            return false;
        }
        DB::table('webhook_endpoint_projects')->where('webhook_endpoint_id', $endpoint->id)->delete();
        foreach ($filter['ids'] as $id) {
            DB::table('webhook_endpoint_projects')->insert(['webhook_endpoint_id' => $endpoint->id, 'workspace_id' => $endpoint->workspace_id, 'project_id' => $id]);
        }

        return true;
    }
}
