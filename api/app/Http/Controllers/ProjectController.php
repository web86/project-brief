<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjectResource::collection(Project::withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')])->latest('id')->get());
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        return new ProjectResource(Project::create($request->projectData()));
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->load('accessTokens')->loadCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')]));
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->projectData());

        return $this->show($project);
    }
}
