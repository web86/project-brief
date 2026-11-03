<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\AuthOnboarding;
use App\Services\CanaryRegistration;
use App\Services\DeveloperInvitations;
use App\Services\RegistrationReadiness;
use App\Services\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function readiness(): JsonResponse
    {
        return response()->json(app(RegistrationReadiness::class)->publicState())->header('Cache-Control', 'no-store');
    }

    public function canaryReadiness(Request $request, CanaryRegistration $canary): JsonResponse
    {
        $data = $request->validate(['invitation_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return response()->json(['registrationEnabled' => ! $request->user()
            && ! $request->session()->has('invitation_context') && $canary->available($data['invitation_token'])])
            ->header('Cache-Control', 'no-store');
    }

    public function register(Request $request, AuthOnboarding $onboarding): JsonResponse
    {
        abort_unless($request->has('invitation_token') || app(RegistrationReadiness::class)->registrationEnabled(), 403, 'registration_disabled');
        abort_if($request->user() || $request->session()->has('invitation_context'), 409, 'onboarding_use_existing_flow');
        abort_if(array_diff(array_keys($request->all()), ['email', 'password', 'password_confirmation', 'locale', 'invitation_token']), 422);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'], 'locale' => ['sometimes', Rule::in(SupportedLocales::codes())], 'invitation_token' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $token = $data['invitation_token'] ?? null;
        unset($data['invitation_token']);
        $onboarding->register($data, $token);

        return response()->json(['status' => 'verification_requested'], 202)->header('Cache-Control', 'no-store');
    }

    public function state(Request $request, AuthOnboarding $onboarding): JsonResponse
    {
        return response()->json($onboarding->state($request))->header('Cache-Control', 'no-store');
    }

    public function resend(Request $request, AuthOnboarding $onboarding): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail($data['email'])])->first();
        if ($user) {
            $onboarding->sendVerification($user);
        }

        return response()->json(['status' => 'verification_requested'], 202)->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, AuthOnboarding $onboarding): JsonResponse
    {
        abort_unless($request->user(), 401);
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        $result = $onboarding->verify($request->user(), $data['token']);
        $user = $request->user()->fresh();
        if (! $result['replayed'] && ! $user->hasEnabledTwoFactorAuthentication()) {
            app(AccountSecurity::class)->complete($request, $user, 'password');
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function email(Request $request, AuthOnboarding $onboarding): JsonResponse
    {
        abort_unless($request->user(), 401);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'current_password' => ['nullable', 'string', 'max:1024']]);
        $onboarding->changePendingEmail($request->user(), $data['email'], $data['current_password'] ?? null);

        return response()->json(['status' => 'verification_requested'], 202)->header('Cache-Control', 'no-store');
    }
}
