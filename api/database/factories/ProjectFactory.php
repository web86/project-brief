<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            foreach ($project->sections ?? ['Главная', 'Общее'] as $index => $name) {
                $project->briefSections()->create(['name' => $name, 'position' => $index + 1]);
            }
            $project->clients()->create(['name' => $project->client_name ?: 'Клиент', 'email' => $project->client_email]);
        });
    }

    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'currency' => 'RUB', 'status' => 'active', 'sections' => ['Главная', 'Общее']];
    }
}
