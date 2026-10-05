<?php

namespace App\Http\Resources;

use App\Services\ProjectStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/admin/*');

        return ['id' => $this->uuid, 'name' => $this->title, 'website' => $this->website_url ?? '', 'description' => '', 'currency' => $this->currency,
            'status' => $this->status, 'sections' => $this->briefSections->map(fn ($section) => ProjectStructure::sectionData($section)),
            'tasksCount' => $this->when($admin, $this->tasks_count), 'doneCount' => $this->when($admin, $this->done_count)];
    }
}
