<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjectResource::collection(Project::withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')])->latest('id')->get());
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $project = DB::transaction(function () use ($request): Project {
            $project = Project::create($request->projectData());
            foreach ($request->validated('sections') ?? ['Главная', 'Каталог', 'Карточка товара', 'Контакты', 'Общее'] as $index => $name) {
                $project->briefSections()->create(['name' => $name, 'position' => $index + 1]);
            }
            if ($request->validated('clientName') || $request->validated('clientEmail')) {
                $project->clients()->create(['name' => $request->validated('clientName') ?: 'Клиент', 'email' => $request->validated('clientEmail')]);
            }

            return $project;
        });

        return new ProjectResource($project);
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->load('briefSections')->loadCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')]));
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->projectData());

        return $this->show($project);
    }
}
