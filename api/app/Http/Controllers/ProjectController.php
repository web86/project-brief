<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\ProjectUnread;
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
        $project = DB::transaction(function () use ($request): Project {
            $member = app(CurrentWorkspace::class)->resolve($request);
            $project = $member->workspace->projects()->create($request->projectData());
            if ($member->role !== 'owner') {
                $project->members()->create(['workspace_id' => $member->workspace_id, 'workspace_member_id' => $member->id]);
            }
            foreach ($request->validated('sections') ?? ['Главная', 'Каталог', 'Карточка товара', 'Контакты', 'Общее'] as $index => $name) {
                $project->briefSections()->create(['name' => $name, 'position' => $index + 1]);
            }
            if ($request->validated('clientName') || $request->validated('clientEmail')) {
                $project->clients()->create(['name' => $request->validated('clientName') ?: 'Клиент', 'email' => $request->validated('clientEmail')]);
            }

            return $project;
        });

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
        $project->update($request->projectData());

        return $this->show($request, $project);
    }
}
