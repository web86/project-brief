<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'currency' => 'RUB', 'status' => 'active', 'sections' => ['Главная', 'Общее']];
    }
}
