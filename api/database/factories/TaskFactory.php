<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => fake()->sentence(4), 'location' => 'Главная', 'section' => 'Главная', 'description' => fake()->paragraph(), 'priority' => 'normal', 'status' => 'new'];
    }
}
