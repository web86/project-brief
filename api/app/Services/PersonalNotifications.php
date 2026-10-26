<?php

namespace App\Services;

use App\Mail\VerifyNotificationEmail;
use App\Models\ProjectNotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class PersonalNotifications
{
    public function legacyEmails(User $user): array
    {
        $workspaceIds = $user->workspaceMembers()->active()->where('role', 'owner')->pluck('workspace_id');

        return ProjectNotificationSetting::whereNotNull('notification_email')->whereHas('project', fn ($query) => $query->whereIn('workspace_id', $workspaceIds))
            ->with('project.workspace')->get()->filter(fn ($setting) => app(ProjectNotificationService::class)->primaryOwner($setting->project)?->id === $user->id)
            ->map(fn ($setting) => ['projectId' => $setting->project->uuid, 'projectName' => $setting->project->title, 'workspaceName' => $setting->project->workspace->name,
                'email' => $setting->notification_email, 'active' => $setting->delivery_mode === 'legacy' && $user->notification_email_resolved_at === null])->values()->all();
    }

    public function changeEmail(User $user, string $email, ProjectNotificationService $delivery): array
    {
        $email = mb_strtolower(trim($email));
        if (strcasecmp($email, $user->email) === 0) {
            $user->forceFill(['notification_email' => null, 'notification_email_resolved_at' => now(), ...$this->clearPending()])->save();

            return ['status' => 'saved', 'error_code' => null];
        }
        if ($user->notification_email && strcasecmp($email, $user->notification_email) === 0) {
            $user->forceFill($this->clearPending())->save();

            return ['status' => 'saved', 'error_code' => null];
        }
        if (! $delivery->mailAvailable()) {
            return ['status' => 'failed', 'error_code' => 'smtp_unconfigured'];
        }
        $limitKey = 'notification-email:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return ['status' => 'failed', 'error_code' => 'email_rate_limited'];
        }
        RateLimiter::hit($limitKey, 60);
        $token = bin2hex(random_bytes(32));
        $user->forceFill(['pending_notification_email' => $email, 'notification_email_token_hash' => hash('sha256', $token), 'notification_email_expires_at' => now()->addMinutes(30)])->save();
        $url = rtrim(config('app.url'), '/').'/admin/settings/notifications#verify='.$token;
        try {
            Mail::to($email)->send(new VerifyNotificationEmail($url, SupportedLocales::resolve($user->preferred_locale)));

            return ['status' => 'verification_sent', 'error_code' => null];
        } catch (Throwable) {
            Log::warning('Notification email verification failed', ['error_code' => 'email_delivery_failed']);

            return ['status' => 'failed', 'error_code' => 'email_delivery_failed'];
        }
    }

    public function verify(User $user, string $token): bool
    {
        return DB::transaction(function () use ($user, $token): bool {
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $current->pending_notification_email || ! $current->notification_email_expires_at?->isFuture() || ! hash_equals((string) $current->notification_email_token_hash, hash('sha256', $token))) {
                return false;
            }
            $current->forceFill(['notification_email' => $current->pending_notification_email, 'notification_email_resolved_at' => now(), ...$this->clearPending()])->save();

            return true;
        });
    }

    public function clearPending(): array
    {
        return ['pending_notification_email' => null, 'notification_email_token_hash' => null, 'notification_email_expires_at' => null];
    }
}
