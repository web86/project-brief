<?php

namespace Tests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Simulate a completed login for business tests. Protocol tests exercise the real endpoints. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User) {
            $this->withSession([
                'auth_session_version' => (int) $user->auth_session_version,
                'security' => ['user' => $user->id, 'version' => (int) $user->auth_session_version, 'method' => $user->hasEnabledTwoFactorAuthentication() ? 'mfa' : 'password', 'confirmed_at' => now()->timestamp],
            ]);
        }

        return parent::actingAs($user, $guard);
    }

    protected function ownerFor(Project $project, array $attributes = []): User
    {
        return User::factory()->owner($project->workspace)->create($attributes);
    }
}
