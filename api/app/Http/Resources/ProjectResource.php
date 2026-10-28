<?php

namespace App\Http\Resources;

use App\Services\LiveSync;
use App\Services\ProjectDiscussions;
use App\Services\ProjectFeedbacks;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
use App\Services\WorkspaceBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/admin/*');
        if (! $admin) {
            $this->resource->loadMissing('briefSections');
        }

        $client = $request->attributes->get('project_client');
        $unreadComments = $client ? app(ProjectDiscussions::class)->count($this->resource, $client) : $this->when($admin && isset($this->unread_comment_count), (int) $this->unread_comment_count);

        return ['syncCursor' => $this->when(! $admin, fn () => app(LiveSync::class)->seed($request)), 'id' => $this->uuid, 'name' => $this->title, 'website' => $this->website_url ?? '', 'description' => '', 'currency' => $this->currency,
            'permissions' => $this->when($admin, fn () => ProjectPermissions::effective($request, $this->resource)),
            'status' => $this->status, 'updatedAt' => $this->updated_at?->toISOString(),
            'branding' => $this->when($request->attributes->has('project_client'), fn () => app(WorkspaceBranding::class)->portal($this->workspace)),
            'feedback' => $this->when($admin, fn () => ['eligible' => app(ProjectFeedbacks::class)->eligible($this->resource), 'requested' => (bool) $this->feedback_requested, 'submitted' => (bool) $this->feedback_submitted]),
            'clientsCount' => $this->when($admin && isset($this->clients_count), (int) $this->clients_count),
            'teamCount' => $this->when($admin && isset($this->team_count), (int) $this->team_count),
            'sections' => $this->whenLoaded('briefSections', fn () => $this->briefSections->map(fn ($section) => ProjectStructure::sectionData($section))),
            'tasksCount' => $this->when($admin, $this->tasks_count), 'doneCount' => $this->when($admin, $this->done_count), 'unreadCommentCount' => $unreadComments, 'unreadCount' => $this->when($admin && isset($this->unread_count), (int) $this->unread_count)];
    }
}
