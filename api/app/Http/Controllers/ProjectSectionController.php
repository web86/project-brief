<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectSection;
use App\Services\ProjectStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectSectionController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['data' => $project->briefSections()->get()->map(fn ($section) => [...ProjectStructure::sectionData($section), 'tasksCount' => $section->tasks()->count()])]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:2048', 'regex:/\S/u']]);
        DB::transaction(function () use ($project, $data): void {
            ProjectStructure::lock($project);
            ProjectStructure::normalizeProject($project);
            $project->briefSections()->create(['name' => trim($data['name']), 'position' => ($project->briefSections()->max('position') ?? 0) + 1]);
        }, 3);

        return $this->index($project)->setStatusCode(201);
    }

    public function update(Request $request, Project $project, ProjectSection $section): JsonResponse
    {
        abort_unless($section->project_id === $project->id, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:2048', 'regex:/\S/u'], 'direction' => ['sometimes', 'in:up,down']]);
        DB::transaction(function () use ($project, $section, $data): void {
            ProjectStructure::lock($project);
            if (isset($data['name'])) {
                $section->update(['name' => trim($data['name'])]);
            }
            if (isset($data['direction'])) {
                ProjectStructure::moveSection($project, $section, $data['direction']);
            }
        }, 3);

        return $this->index($project);
    }

    public function destroy(Project $project, ProjectSection $section): JsonResponse
    {
        abort_unless($section->project_id === $project->id, 404);
        DB::transaction(function () use ($project, $section): void {
            ProjectStructure::lock($project);
            if ($section->tasks()->exists()) {
                throw ValidationException::withMessages(['section' => 'Сначала переместите задачи в другой раздел.']);
            }
            $section->delete();
            ProjectStructure::normalize($project->briefSections()->get());
        }, 3);

        return $this->index($project);
    }
}
