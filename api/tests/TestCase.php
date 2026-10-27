<?php

namespace Tests;

use App\Models\Project;
use App\Models\User;
use App\Services\AccountSecurity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    /** Simulate a completed login for business tests. Protocol tests exercise the real endpoints. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User) {
            $this->withCredentials();
            $this->withSession(['auth_session_version' => (int) $user->auth_session_version]);
            $this->app['session.store']->forget(['security', 'workspace_id', 'client_project_id', 'project_client_id', 'client_access_token_id']);
            $request = Request::create('/');
            $request->setLaravelSession($this->app['session.store']);
            $this->app->make(AccountSecurity::class)->confirm($request, $user, $user->hasEnabledTwoFactorAuthentication() ? 'mfa' : 'password');
            $request->session()->save();
            $this->withCookie(config('session.cookie'), $request->session()->getId());
        }

        return parent::actingAs($user, $guard);
    }

    public function withSession(array $data)
    {
        parent::withSession($data);
        $this->app['session.store']->save();
        $this->withCookie(config('session.cookie'), $this->app['session.store']->getId());

        return $this;
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $response;
    }

    protected function ownerFor(Project $project, array $attributes = []): User
    {
        return User::factory()->owner($project->workspace)->create($attributes);
    }
}
