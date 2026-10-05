<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectStructure;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Task $task): void {
            if (! $task->project_section_id) {
                $section = $task->project->briefSections()->where('name', $task->section ?: 'Общее')->first() ?? ProjectStructure::defaultSection($task->project);
                $task->update(['project_section_id' => $section->id, 'position' => ($section->tasks()->max('position') ?? 0) + 1]);
            }
        });
    }

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => fake()->sentence(4), 'location' => 'Главная', 'section' => 'Главная', 'description' => fake()->paragraph(), 'priority' => 'normal', 'status' => 'new'];
    }
}
