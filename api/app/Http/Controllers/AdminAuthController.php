<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attemptWhen($request->validated(), fn (User $user) => $user->workspaceMembers()->active()->exists())) {
            return response()->json(['code' => 'invalid_credentials', 'errors' => ['email' => ['Неверный email или пароль.']]], 422);
        }
        $request->session()->forget(['client_project_id', 'project_client_id', 'client_access_token_id', CurrentWorkspace::SESSION_KEY]);
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        $context = app(CurrentWorkspace::class);
        $member = $context->resolve($request);

        return response()->json(['user' => ['name' => $request->user()->name, 'email' => $request->user()->email, 'role' => 'admin', 'membershipRole' => $member->role], 'workspace' => $context->state($member)])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Вы вышли из аккаунта.']);
    }
}
