<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExternalAgentFactory extends Factory
{
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'name' => fake()->words(2, true), 'description' => 'Independent executor', 'kind' => 'ai', 'active' => true];
    }
}
