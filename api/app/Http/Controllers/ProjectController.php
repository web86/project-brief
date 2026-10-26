<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\ProjectCreation;
use App\Services\ProjectUnread;
use App\Services\WebhookEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProjectResource::collection(app(ProjectUnread::class)->withCounts(ProjectAccess::query($request), $request->user())->withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')])->latest('id')->get());
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $member = app(CurrentWorkspace::class)->resolve($request);
        $project = app(ProjectCreation::class)->create($member->workspace_id, $request->projectData(), $request->validated(), $member);

        return new ProjectResource($project->load('briefSections'));
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        $project = app(ProjectUnread::class)->withCounts(ProjectAccess::query($request)->whereKey($project->id), $request->user())->firstOrFail();
        if (! $request->boolean('summary')) {
            $project->load('briefSections');
        }

        return new ProjectResource($project->loadCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')]));
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        DB::transaction(function () use ($request, $project): void {
            $project->fill($request->projectData());
            $changes = $project->getDirty();
            $project->save();
            app(WebhookEvents::class)->project($project, 'project.updated', $changes);
        });

        return $this->show($request, $project);
    }
}
