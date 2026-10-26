<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountMail;
use App\Services\AccountProfile;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryController extends Controller
{
    public function request(Request $request, AccountMail $mail): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
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
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'token' => ['required', 'string', 'max:256'], 'password' => ['required', 'string', 'min:9', 'max:1024', 'confirmed']]);
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if ($user) {
            $data['email'] = $user->email;
        }
        $status = DB::transaction(function () use ($data, $profile): string {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::broker('users')->reset($data, function (User $user, string $password) use ($profile): void {
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
