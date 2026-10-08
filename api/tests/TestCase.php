<?php

namespace Tests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function ownerFor(Project $project, array $attributes = []): User
    {
        return User::factory()->owner($project->workspace)->create($attributes);
    }
}
