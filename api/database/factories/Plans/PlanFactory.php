<?php

namespace Database\Factories\Plans;

use App\Models\Plans\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return ['code' => 'test-'.fake()->unique()->lexify('????????'), 'status' => 'draft', 'visibility' => 'public', 'sort_order' => 0, 'revision' => 1];
    }
}
