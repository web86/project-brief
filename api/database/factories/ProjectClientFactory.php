<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectClient>
 */
class ProjectClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'name' => fake()->name(), 'email' => fake()->safeEmail(), 'active' => true];
    }
}
