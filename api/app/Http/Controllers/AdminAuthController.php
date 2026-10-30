<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
use App\Services\LiveSync;
use App\Services\PlatformAudit;
use App\Services\ProjectPermissions;
use App\Services\ProjectRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminAuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $registeredEmail = User::whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail($credentials['email'])])->value('email');
        $credentials['email'] = $registeredEmail ?? $credentials['email'];
        $validCredentials = Auth::validate($credentials);
        $user = Auth::getLastAttempted();
        $invited = false;
        if ($user && ($request->filled('invitation_token') || $request->session()->has('invitation_context'))) {
            try {
                $invitation = app(DeveloperInvitations::class)->resolveRequest($request, $request->input('invitation_token'));
                $invited = DeveloperInvitations::emailsMatch($user->email, $invitation->email);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 410) {
                    throw $exception;
                }
            }
        }
        $eligible = $user && ($user->platform_admin || $user->workspaceMembers()->active()->exists() || $invited);
        if (! $validCredentials || ! $eligible) {
            if ($user?->platform_admin) {
                app(PlatformAudit::class)->record($user->id, 'login_denied', result: 'invalid_credentials');
            }

            return response()->json(['code' => 'invalid_credentials', 'errors' => ['email' => [trans('interface.invalid_credentials')]]], 422);
        }
        Auth::getProvider()->rehashPasswordIfRequired($user, $credentials);
        $invitationContext = $request->session()->get('invitation_context');
        $state = app(AccountSecurity::class)->passwordLogin($request, $user);
        if ($invitationContext) {
            $request->session()->put('invitation_context', $invitationContext);
        }
        if ($state !== 'authenticated') {
            return response()->json(['state' => $state])->header('Cache-Control', 'no-store');
        }

        return $request->user()->workspaceMembers()->active()->exists() ? $this->me($request) : response()->json(['user' => app(AccountProfile::class)->state($request->user())]);
    }

    public function me(Request $request): JsonResponse
    {
        $context = app(CurrentWorkspace::class);
        $member = $context->resolve($request);

        return response()->json(['pendingRequests' => $member->role === 'owner' ? app(ProjectRequests::class)->count($member->workspace_id) : 0, 'syncCursor' => app(LiveSync::class)->seed($request), 'securityRecommended' => app(AccountSecurity::class)->recommendationsPending($request->user()), 'user' => [...app(AccountProfile::class)->state($request->user()), 'isProjectSuperadmin' => (bool) $member->is_project_superadmin, 'membershipRole' => $member->role, 'canCreateProjects' => ProjectPermissions::canCreateProjects($member)], 'workspace' => $context->state($member, true)])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        $invitationContext = $request->session()->get('invitation_context');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($invitationContext) {
            $request->session()->put('invitation_context', $invitationContext);
        }

        return response()->json(['message' => trans('interface.logged_out')]);
    }
}
