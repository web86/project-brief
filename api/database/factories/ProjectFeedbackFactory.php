<?php

namespace Database\Factories;

use App\Models\ProjectClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFeedbackFactory extends Factory
{
    public function definition(): array
    {
        return ['project_client_id' => ProjectClient::factory(),
            'project_id' => fn (array $attributes) => ProjectClient::findOrFail($attributes['project_client_id'])->project_id,
            'workspace_id' => fn (array $attributes) => ProjectClient::findOrFail($attributes['project_client_id'])->project->workspace_id,
            'requested_at' => now()];
    }
}
