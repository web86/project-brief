<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProfile
{
    public function state(User $user): array
    {
        $avatar = $user->avatar_source === 'upload' && $user->avatar_path ? '/api/admin/avatars/'.basename($user->avatar_path) : null;
        if (! $avatar && $user->avatar_source === 'gravatar') {
            $avatar = 'https://gravatar.com/avatar/'.hash('sha256', mb_strtolower(trim($user->email))).'?s=160&d=404';
        }

        return ['notificationPreferenceId' => hash_hmac('sha256', 'notifications:'.$user->getKey(), (string) config('app.key')), 'name' => $user->name, 'email' => $user->email, 'role' => 'admin', 'preferredLocale' => SupportedLocales::preference($user->preferred_locale), 'avatarSource' => $user->avatar_source ?? 'initials', 'avatarUrl' => $avatar];
    }

    public function clearEmail(): array
    {
        return ['pending_email' => null, 'email_change_token_hash' => null, 'email_change_expires_at' => null];
    }

    public function revokeSessions(User $user, ?Request $request = null): void
    {
        $user->forceFill(['auth_session_version' => (int) $user->auth_session_version + 1, 'remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)
                ->when($request, fn ($query) => $query->where('id', '!=', $request->session()->getId()))->delete();
        }
        if ($request) {
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
            $request->session()->put('security.version', (int) $user->auth_session_version);
            $request->session()->regenerate();
        }
    }

    public function image(UploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 16000000) {
            throw ValidationException::withMessages(['avatar' => ['Use a JPEG, PNG or WebP image up to 4096 × 4096 pixels.']]);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['avatar' => ['Invalid image.']]);
        }
        $square = imagecreatetruecolor(256, 256);
        $white = imagecolorallocate($square, 255, 255, 255);
        imagefill($square, 0, 0, $white);
        $side = min($info[0], $info[1]);
        imagecopyresampled($square, $source, 0, 0, (int) (($info[0] - $side) / 2), (int) (($info[1] - $side) / 2), 256, 256, $side, $side);
        ob_start();
        imagewebp($square, null, 82);
        $bytes = ob_get_clean();
        $path = 'avatars/'.bin2hex(random_bytes(24)).'.webp';
        abort_unless($bytes && Storage::disk('local')->put($path, $bytes), 500);

        return $path;
    }

    public function removeImage(?string $path): void
    {
        if ($path && ! User::where('avatar_path', $path)->exists()) {
            Storage::disk('local')->delete($path);
        }
    }
}
