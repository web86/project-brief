<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\User;
use App\Services\DemoEnvironment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DemoIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoEnvironment::readOnly() || ! $request->hasSession()) {
            return $next($request);
        }
        DemoEnvironment::assertIsolated();
        $role = $request->session()->get('demo.role');
        // Never adopt an ordinary login or a caller-supplied credential in public demo.
        Auth::forgetGuards();
        $request->setUserResolver(fn () => null);
        if ($role === 'developer') {
            $user = User::where('email', 'morgan@harborline.test')->where('platform_admin', false)->firstOrFail();
            Auth::guard()->setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
            $request->session()->put('security', ['user' => $user->id, 'version' => (int) $user->auth_session_version, 'method' => 'mfa']);
            $request->session()->put('workspace_id', $user->workspaceMembers()->active()->firstOrFail()->workspace_id);
        } elseif ($role === 'client') {
            $project = Project::where('title', 'Northstar Commerce')->firstOrFail();
            $client = $project->clients()->orderBy('id')->firstOrFail();
            $access = $project->accessTokens()->where('project_client_id', $client->id)->whereNull('revoked_at')->firstOrFail();
            $request->session()->put(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $access->id]);
        } elseif ($request->is('api/admin/*', 'api/client/*', 'api/security/*')) {
            abort(401);
        }

        return $next($request);
    }
}
