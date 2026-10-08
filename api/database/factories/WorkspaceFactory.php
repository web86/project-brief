<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Workspace> */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company()];
    }
}
