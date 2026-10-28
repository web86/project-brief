<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\DeveloperInvitation;
use App\Models\User;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
use App\Services\ProjectPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $registeredEmail = User::whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail($credentials['email'])])->value('email');
        $credentials['email'] = $registeredEmail ?? $credentials['email'];
        $validCredentials = Auth::validate($credentials);
        $user = Auth::getLastAttempted();
        $eligible = $user && ($user->workspaceMembers()->active()->exists() || DeveloperInvitation::where('token_hash', hash('sha256', (string) $request->input('invitation_token')))->whereRaw('LOWER(email) = ?', [DeveloperInvitations::normalizeEmail($user->email)])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists());
        if (! $validCredentials || ! $eligible) {
            return response()->json(['code' => 'invalid_credentials', 'errors' => ['email' => [trans('interface.invalid_credentials')]]], 422);
        }
        Auth::getProvider()->rehashPasswordIfRequired($user, $credentials);
        $state = app(AccountSecurity::class)->passwordLogin($request, $user);
        if ($state !== 'authenticated') {
            return response()->json(['state' => $state])->header('Cache-Control', 'no-store');
        }

        return $request->user()->workspaceMembers()->active()->exists() ? $this->me($request) : response()->json(['user' => app(AccountProfile::class)->state($request->user())]);
    }

    public function me(Request $request): JsonResponse
    {
        $context = app(CurrentWorkspace::class);
        $member = $context->resolve($request);

        return response()->json(['securityRecommended' => app(AccountSecurity::class)->recommendationsPending($request->user()), 'user' => [...app(AccountProfile::class)->state($request->user()), 'membershipRole' => $member->role, 'canCreateProjects' => ProjectPermissions::canCreateProjects($member)], 'workspace' => $context->state($member, true)])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => trans('interface.logged_out')]);
    }
}
