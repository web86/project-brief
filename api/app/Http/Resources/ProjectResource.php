<?php

namespace App\Http\Resources;

use App\Services\ProjectDiscussions;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
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

        return ['id' => $this->uuid, 'name' => $this->title, 'website' => $this->website_url ?? '', 'description' => '', 'currency' => $this->currency,
            'permissions' => $this->when($admin, fn () => ProjectPermissions::effective($request, $this->resource)),
            'status' => $this->status, 'sections' => $this->whenLoaded('briefSections', fn () => $this->briefSections->map(fn ($section) => ProjectStructure::sectionData($section))),
            'tasksCount' => $this->when($admin, $this->tasks_count), 'doneCount' => $this->when($admin, $this->done_count), 'unreadCommentCount' => $unreadComments, 'unreadCount' => $this->when($admin && isset($this->unread_count), (int) $this->unread_count)];
    }
}
