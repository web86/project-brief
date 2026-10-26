<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountMail;
use App\Services\AccountProfile;
use App\Services\SupportedLocales;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountController extends Controller
{
    public function show(Request $request, AccountProfile $profile): JsonResponse
    {
        $user = $request->user()->fresh();

        return response()->json(['user' => $profile->state($user), 'pendingEmail' => $user->pending_email, 'emailExpiresAt' => $user->email_change_expires_at?->toISOString()])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, AccountProfile $profile): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['name', 'preferredLocale']), 422);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'preferredLocale' => ['required', Rule::in(SupportedLocales::codes())]]);
        $request->user()->forceFill(['name' => $data['name'], 'preferred_locale' => $data['preferredLocale']])->save();

        return $this->show($request, $profile);
    }

    public function email(Request $request, AccountProfile $profile, AccountMail $mail): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'current_password' => ['required', 'string', 'max:1024', 'current_password:web']]);
        $email = mb_strtolower(trim($data['email']));
        $sent = DB::transaction(function () use ($request, $email, $mail, $data): bool {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Incorrect password.']]);
            }
            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => ['Email is already in use.']]);
            }
            $token = bin2hex(random_bytes(32));
            $user->forceFill(['pending_email' => $email, 'email_change_token_hash' => hash('sha256', $token), 'email_change_expires_at' => now()->addMinutes(60)])->save();

            return $mail->localized($email, $user->preferred_locale, 'confirm', rtrim(config('app.url'), '/').'/admin/account#email='.$token);
        });

        return response()->json(['sent' => $sent, 'code' => $sent ? null : 'account_mail_failed'], $sent ? 200 : 503);
    }

    public function cancelEmail(Request $request, AccountProfile $profile): JsonResponse
    {
        DB::transaction(function () use ($request, $profile): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail()->forceFill($profile->clearEmail())->save();
        });

        return $this->show($request, $profile);
    }

    public function confirmEmail(Request $request, AccountProfile $profile, AccountMail $mail): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        try {
            $old = DB::transaction(function () use ($request, $data, $profile): string {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->pending_email && $user->email_change_expires_at?->isFuture() && hash_equals((string) $user->email_change_token_hash, hash('sha256', $data['token'])), 410);
                if (User::whereRaw('LOWER(email) = ?', [$user->pending_email])->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['email' => ['Email is already in use.']]);
                }
                $old = $user->email;
                Password::broker('users')->deleteToken($user);
                $user->forceFill(['email' => $user->pending_email, 'email_verified_at' => now(), ...$profile->clearEmail()])->save();

                return $old;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Email is already in use.']]);
        }
        $mail->localized($old, $request->user()->fresh()->preferred_locale, 'changed');

        return $this->show($request, $profile);
    }

    public function password(Request $request, AccountProfile $profile): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024', 'current_password:web'], 'password' => ['required', 'string', 'min:9', 'max:1024', 'confirmed']]);
        DB::transaction(function () use ($request, $data, $profile): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Incorrect password.']]);
            }
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => ['Choose a different password.']]);
            }
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            $profile->revokeSessions($user, $request);
        });

        return response()->json(['changed' => true]);
    }

    public function avatar(Request $request, AccountProfile $profile): JsonResponse
    {
        $data = $request->validate(['source' => ['required', 'in:initials,gravatar,upload'], 'avatar' => ['required_if:source,upload', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $data['source'] === 'upload' ? $profile->image($request->file('avatar')) : null;
        try {
            $old = DB::transaction(function () use ($request, $data, $path): ?string {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $old = $user->avatar_path;
                $user->forceFill(['avatar_source' => $data['source'], 'avatar_path' => $path])->save();

                return $old;
            });
        } catch (\Throwable $e) {
            $profile->removeImage($path);
            throw $e;
        }
        $profile->removeImage($old);

        return $this->show($request, $profile);
    }

    public function image(Request $request, string $key): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{48}\.webp$/', $key), 404);
        $user = User::where('avatar_path', 'avatars/'.$key)->firstOrFail();
        $viewer = $request->user();
        abort_unless($viewer->id === $user->id || $viewer->workspaceMembers()->active()->whereIn('workspace_id', $user->workspaceMembers()->active()->select('workspace_id'))->exists(), 404);
        abort_unless(Storage::disk('local')->exists($user->avatar_path), 404);

        return response()->file(Storage::disk('local')->path($user->avatar_path), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
