<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSection;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectStructure
{
    public static function lock(Project $project): void
    {
        Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
    }

    public static function section(Project $project, string $uuid): ProjectSection
    {
        return $project->briefSections()->where('uuid', $uuid)->firstOrFail();
    }

    public static function defaultSection(Project $project): ProjectSection
    {
        return $project->briefSections()->where('name', 'Общее')->first()
            ?? $project->briefSections()->create(['name' => 'Общее', 'position' => ($project->briefSections()->max('position') ?? 0) + 1]);
    }

    public static function assignNewTask(Project $project, Task $task): void
    {
        $section = self::defaultSection($project);
        $task->forceFill(['project_section_id' => $section->id, 'position' => ($section->tasks()->max('position') ?? 0) + 1])->save();
    }

    /** @param iterable<ProjectSection|Task> $items */
    public static function normalize(iterable $items): void
    {
        $position = 0;
        foreach ($items as $item) {
            $item->forceFill(['position' => ++$position])->save();
        }
    }

    public static function moveSection(Project $project, ProjectSection $section, string $direction): void
    {
        DB::transaction(function () use ($project, $section, $direction): void {
            self::lock($project);
            $sections = $project->briefSections()->get()->all();
            self::swap($sections, $section->id, $direction);
            self::normalize($sections);
        }, 3);
    }

    /** @param array<ProjectSection|Task> $items */
    private static function swap(array &$items, int $id, string $direction): void
    {
        $index = array_search($id, array_map(fn ($item) => $item->id, $items), true);
        $target = $index + ($direction === 'up' ? -1 : 1);
        if ($index !== false && isset($items[$target])) {
            [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        }
    }

    /** Resolve ownership outside the transaction so MariaDB's first consistent read follows the project lock. */
    public static function moveTask(Request $request, string $uuid, string $target): Task
    {
        $project = TaskAccess::task($request, $uuid)->project;

        return DB::transaction(function () use ($request, $uuid, $target, $project): Task {
            self::lock($project);
            $task = TaskAccess::task($request, $uuid, true);
            $section = self::section($project, $target);
            if ($task->project_section_id === $section->id) {
                return $task;
            }
            $oldSection = $task->project_section_id;
            $oldNumber = self::number($task);
            $task->forceFill(['project_section_id' => $section->id, 'position' => ($section->tasks()->max('position') ?? 0) + 1])->save();
            self::normalize($project->tasks()->where('project_section_id', $oldSection)->orderBy('position')->orderBy('id')->get());
            self::normalize($section->tasks()->orderBy('position')->orderBy('id')->get());
            $task->refresh();
            TaskAudit::record($request, $task, 'section_moved', 'Разработчик переместил задачу в раздел «'.$section->name.'»', $oldNumber, self::number($task));

            return $task;
        }, 3);
    }

    /** Keep the same lock/read order as moveTask to avoid stale REPEATABLE READ snapshots. */
    public static function reorderTask(Request $request, string $uuid, string $direction): Task
    {
        $project = TaskAccess::task($request, $uuid)->project;

        return DB::transaction(function () use ($request, $uuid, $direction, $project): Task {
            self::lock($project);
            $task = TaskAccess::task($request, $uuid, true);
            if (! $task->project_section_id) {
                throw ValidationException::withMessages(['sectionId' => 'Сначала назначьте раздел.']);
            }
            $items = $project->tasks()->where('project_section_id', $task->project_section_id)->orderBy('position')->orderBy('id')->get()->all();
            $oldNumber = self::number($task);
            self::swap($items, $task->id, $direction);
            self::normalize($items);
            $task->refresh();
            if ($oldNumber !== self::number($task)) {
                TaskAudit::record($request, $task, 'reordered', 'Разработчик изменил порядок задачи', $oldNumber, self::number($task));
            }

            return $task;
        }, 3);
    }

    public static function number(Task $task): ?string
    {
        return $task->projectSection && $task->position ? $task->projectSection->position.'.'.$task->position : null;
    }

    /** @return array{id: string, uuid: string, name: string, position: int} */
    public static function sectionData(ProjectSection $section): array
    {
        return ['id' => $section->uuid, 'uuid' => $section->uuid, 'name' => $section->name, 'position' => $section->position];
    }
}
