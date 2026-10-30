<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
use App\Services\WorkspaceBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeveloperInvitationController extends Controller
{
    public function show(Request $request, DeveloperInvitations $invitations): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['token', 'name', 'password', 'password_confirmation']), 422);
        $data = $request->validate(['token' => [$request->session()->has('invitation_context') ? 'sometimes' : 'required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $invitation = $invitations->resolveRequest($request, $data['token'] ?? null);

        return response()->json(['email' => $invitation->email, 'name' => $invitation->name, 'branding' => app(WorkspaceBranding::class)->portal($invitation->workspace), 'workspace' => $invitation->workspace->name, 'projects' => $invitation->workspace->projects()->whereIn('id', $invitation->project_ids)->pluck('title'), 'authenticated' => (bool) $request->user(), 'authenticatedEmail' => $request->user()?->email, 'accountMatches' => $request->user() ? DeveloperInvitations::emailsMatch($request->user()->email, $invitation->email) : null, 'expiresAt' => $invitation->expires_at->toISOString()])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, DeveloperInvitations $invitations): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['token', 'name', 'password', 'password_confirmation']), 422);
        $data = $request->validate(['token' => [$request->session()->has('invitation_context') ? 'sometimes' : 'required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'name' => ['sometimes', 'string', 'max:255'], 'password' => ['sometimes', 'string', 'min:9', 'max:1024', 'confirmed']]);
        $data['password_confirmation'] = $request->input('password_confirmation');
        $accepted = $invitations->resolveRequest($request, $data['token'] ?? null);
        $user = $invitations->accept($request, $data);
        $security = app(AccountSecurity::class);
        if ($request->user()) {
            abort_unless($security->state($request) === 'authenticated', 403);
            Auth::login($user);
            $request->session()->forget(['security.step_up', 'security.recovery_grant', 'client_project_id', 'project_client_id', 'client_access_token_id', CurrentWorkspace::SESSION_KEY]);
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
        } else {
            $security->complete($request, $user, 'password');
        }
        $request->session()->forget('invitation_context');
        $request->session()->put(CurrentWorkspace::SESSION_KEY, (int) $accepted->workspace_id);
        $request->session()->put('auth_session_version', (int) $user->auth_session_version);

        return response()->json(['accepted' => true]);
    }
}
