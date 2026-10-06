<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectNotificationSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => fn () => Project::factory()->createQuietly()->id, 'events' => null, 'notification_email' => null];
    }
}
