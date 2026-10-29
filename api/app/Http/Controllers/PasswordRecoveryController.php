<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountMail;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    private function staff(string $email): ?User
    {
        return User::whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($email))])->whereHas('workspaceMembers', fn ($members) => $members->active())->first();
    }

    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'token' => ['required', 'string', 'max:256']]);
        $user = $this->staff($data['email']);
        abort_unless($user && Password::broker('users')->tokenExists($user, $data['token']), 422, 'Invalid or expired password reset link.');

        return response()->json(['totp' => $user->hasEnabledTwoFactorAuthentication()]);
    }

    public function request(Request $request, AccountMail $mail): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $user = $this->staff($data['email']);
        if ($user) {
            Password::broker('users')->sendResetLink(['email' => $user->email], function (User $user, string $token) use ($mail): void {
                $mail->localized($user->email, $user->preferred_locale, 'reset',
                    rtrim(config('app.url'), '/').'/admin/reset-password#token='.$token.'&email='.rawurlencode($user->email));
            });
        }

        return response()->json(['status' => 'password_reset_requested']);
    }

    public function reset(Request $request, AccountProfile $profile): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'token' => ['required', 'string', 'max:256'], 'code' => ['nullable', 'string', 'max:6', 'prohibits:recovery_code'], 'recovery_code' => ['nullable', 'string', 'max:100', 'prohibits:code'], 'password' => ['required', 'string', 'min:9', 'max:1024', 'confirmed']]);
        $user = $this->staff($data['email']);
        abort_unless($user, 422, 'Invalid or expired password reset link.');
        if ($user) {
            $data['email'] = $user->email;
        }
        $status = DB::transaction(function () use ($data, $profile, $request): string {
            $locked = User::where('email', $data['email'])->lockForUpdate()->first();
            abort_unless($locked && $locked->workspaceMembers()->active()->exists(), 422, 'Invalid or expired password reset link.');

            return Password::broker('users')->reset(Arr::only($data, ['email', 'token', 'password', 'password_confirmation']), function (User $user, string $password) use ($profile, $data, $request): void {
                $security = app(AccountSecurity::class);
                if ($user->hasEnabledTwoFactorAuthentication()) {
                    $fresh = $request->user()?->id === $user->id && $security->fresh($request);
                    $security->valid($fresh || $security->verifyFactor($user, $data));
                }
                if (Hash::check($password, $user->password)) {
                    throw ValidationException::withMessages(['password' => ['Choose a different password.']]);
                }
                $user->forceFill(['password' => Hash::make($password), ...$profile->clearEmail()])->save();
                $profile->revokeSessions($user);
                event(new PasswordReset($user));
            });
        });
        abort_unless($status === Password::PASSWORD_RESET, 422, 'Invalid or expired password reset link.');

        return response()->json(['changed' => true]);
    }
}
