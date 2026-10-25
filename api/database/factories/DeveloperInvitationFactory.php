<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeveloperInvitationFactory extends Factory
{
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'invited_by' => User::factory(), 'email' => fake()->unique()->safeEmail(), 'token_hash' => hash('sha256', random_bytes(32)), 'project_ids' => [], 'locale' => 'ru', 'expires_at' => now()->addDays(7)];
    }
}
