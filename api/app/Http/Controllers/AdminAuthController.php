<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\DeveloperInvitation;
use App\Models\User;
use App\Services\AccountProfile;
use App\Services\CurrentWorkspace;
use App\Services\DeveloperInvitations;
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
        if (! Auth::attemptWhen($credentials, function (User $user) use ($request): bool {
            return $user->workspaceMembers()->active()->exists() || DeveloperInvitation::where('token_hash', hash('sha256', (string) $request->input('invitation_token')))->whereRaw('LOWER(email) = ?', [DeveloperInvitations::normalizeEmail($user->email)])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        })) {
            return response()->json(['code' => 'invalid_credentials', 'errors' => ['email' => ['Неверный email или пароль.']]], 422);
        }
        $request->session()->forget(['client_project_id', 'project_client_id', 'client_access_token_id', CurrentWorkspace::SESSION_KEY]);
        $request->session()->put('auth_session_version', (int) $request->user()->auth_session_version);
        $request->session()->regenerate();

        return $request->user()->workspaceMembers()->active()->exists() ? $this->me($request) : response()->json(['user' => app(AccountProfile::class)->state($request->user())]);
    }

    public function me(Request $request): JsonResponse
    {
        $context = app(CurrentWorkspace::class);
        $member = $context->resolve($request);

        return response()->json(['user' => [...app(AccountProfile::class)->state($request->user()), 'membershipRole' => $member->role], 'workspace' => $context->state($member)])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Вы вышли из аккаунта.']);
    }
}
