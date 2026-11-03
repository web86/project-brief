<?php

namespace App\Services;

use App\Models\User;
use App\Services\Plans\PlanCommands;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CanaryRegistration
{
    public function issue(User $actor, string $email, string $key): array
    {
        $this->requireAvailable();
        $email = DeveloperInvitations::normalizeEmail($email);
        $emailHash = $this->emailHash($email);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $uuid = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now('UTC')->addMinutes(30);
        $result = app(PlanCommands::class)->run($actor, 'canary.registration.issue', $key, ['emailHash' => $emailHash], function (object $authority) use ($email, $emailHash, $hash, $uuid, $expiresAt): array {
            abort_if(User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists(), 409, 'canary_registration_unavailable');
            $id = DB::table('canary_registration_invites')->insertGetId([
                'uuid' => $uuid, 'email_hash' => $emailHash, 'token_hash' => $hash,
                'authority_revision' => $authority->revision, 'expires_at' => $expiresAt,
            ]);

            return ['type' => 'canary_registration', 'id' => $id, 'uuid' => $uuid, 'before' => 0, 'after' => 1];
        });
        abort_unless($result['uuid'] === $uuid, 409, 'canary_registration_link_already_issued');

        return ['url' => rtrim(config('app.url'), '/').'/register#invite='.$token, 'expiresAt' => $expiresAt->toISOString()];
    }

    public function available(#[\SensitiveParameter] string $token): bool
    {
        if (config('demo.enabled', false)) {
            return false;
        }

        return $this->active($token, DB::table('platform_plan_authority')->where('id', 1)->value('revision'))->exists();
    }

    /** Must run inside the same transaction as account creation. */
    public function lock(#[\SensitiveParameter] string $token, string $email): object
    {
        $this->requireAvailable();
        $revision = DB::table('platform_plan_authority')->where('id', 1)->sharedLock()->value('revision');
        $invite = $this->active($token, $revision)->where('email_hash', $this->emailHash($email))->lockForUpdate()->first();
        abort_unless($invite, 403, 'registration_disabled');

        return $invite;
    }

    public function consume(object $invite, User $user): void
    {
        DB::table('canary_registration_invites')->where('id', $invite->id)->update(['consumed_at' => now('UTC'), 'consumed_user_id' => $user->id]);
    }

    private function active(#[\SensitiveParameter] string $token, int|string|null $revision): Builder
    {
        return DB::table('canary_registration_invites')->where('token_hash', hash('sha256', $token))
            ->where('authority_revision', $revision)->whereNull('consumed_at')->where('expires_at', '>', now('UTC'));
    }

    private function emailHash(string $email): string
    {
        return app(TrialEligibility::class)->hash('dev2done.canary-registration', $email);
    }

    private function requireAvailable(): void
    {
        $days = filter_var(config('onboarding.subject_retention_days'), FILTER_VALIDATE_INT);
        abort_unless(! config('demo.enabled', false) && $days !== false && $days >= 1 && $days <= 3650
            && app(ProjectNotificationService::class)->mailAvailable(), 403, 'canary_registration_unavailable');
    }
}
