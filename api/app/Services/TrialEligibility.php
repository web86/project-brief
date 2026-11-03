<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Plans\SubscriptionClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrialEligibility
{
    public const HASH_VERSION = 1;

    public function hash(string $issuer, string $subject): string
    {
        abort_unless(strlen((string) config('app.key')) >= 32, 503);

        return hash_hmac('sha256', 'trial-v1|'.$issuer.'|'.$subject, (string) config('app.key'));
    }

    public function aliases(User $user): array
    {
        $aliases = [['subject_kind' => 'email', 'issuer_key' => 'dev2done.email', 'subject_hash' => $this->hash('dev2done.email', DeveloperInvitations::normalizeEmail($user->email))]];
        foreach (DB::table('user_external_identities')->where('user_id', $user->id)->orderBy('id')->get() as $i) {
            $aliases[] = ['subject_kind' => 'provider', 'issuer_key' => $i->issuer_key, 'subject_hash' => $i->subject_hash];
        }

        return $aliases;
    }

    /** Caller owns the user lock; only a newly verified self-service owner can prepare a claim. Never starts a clock. */
    public function prepare(User $user, Workspace $workspace, CarbonImmutable $verifiedAt): object
    {
        $existing = DB::table('owner_trial_claims')->where('owner_user_id', $user->id)->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }
        $onboarding = DB::table('account_onboardings')->where('user_id', $user->id)->first();
        abort_unless($onboarding && $user->email_verified_at && (int) $onboarding->workspace_id === $workspace->id && $workspace->members()->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists(), 403);
        $denied = DB::table('workspace_subscriptions')->where('workspace_id', $workspace->id)->exists() || $workspace->projects()->exists() || $workspace->members()->count() !== 1;
        $days = filter_var(config('onboarding.subject_retention_days'), FILTER_VALIDATE_INT);
        $retentionReady = $days !== false && $days >= 1 && $days <= 3650;
        $fields = ['uuid' => (string) Str::uuid(), 'owner_user_id' => $user->id, 'workspace_id' => $workspace->id, 'registered_at' => $onboarding->created_at, 'verified_at' => $denied || ! $retentionReady ? null : $verifiedAt->format('Y-m-d H:i:s.u'), 'state' => $denied ? 'denied' : 'pending', 'reason_code' => $denied ? 'legacy_or_imported_workspace' : ($retentionReady ? 'activation_unavailable' : 'claim_retention_unconfigured')];
        try {
            $id = DB::transaction(function () use ($user, $fields, $retentionReady, $denied, $days): int {
                $id = DB::table('owner_trial_claims')->insertGetId($fields);
                if ($retentionReady && ! $denied) {
                    $this->append($id, $user, $days);
                }

                return $id;
            });
        } catch (UniqueConstraintViolationException|\DomainException) {
            $id = DB::table('owner_trial_claims')->insertGetId([...$fields, 'verified_at' => null, 'state' => 'denied', 'reason_code' => 'verified_subject_previously_claimed']);
        }

        return DB::table('owner_trial_claims')->where('id', $id)->first();
    }

    private function append(int $claimId, User $user, int $days): void
    {
        foreach ($this->aliases($user) as $alias) {
            $existing = DB::table('owner_trial_claim_subjects')->where($alias)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->claim_id !== $claimId) {
                    throw new \DomainException('verified_subject_previously_claimed');
                }

                continue;
            }
            DB::table('owner_trial_claim_subjects')->insert($alias + ['claim_id' => $claimId, 'hash_key_version' => self::HASH_VERSION, 'retained_until' => CarbonImmutable::now('UTC')->addDays($days)->format('Y-m-d H:i:s.u')]);
        }
    }

    /** Alias addition never replaces the claim, dates or identity owner. No alias is auto-pruned. */
    public function rememberVerifiedSubjects(User $user): void
    {
        $claim = DB::table('owner_trial_claims')->where('owner_user_id', $user->id)->lockForUpdate()->first();
        $days = filter_var(config('onboarding.subject_retention_days'), FILTER_VALIDATE_INT);
        if (! $claim || ! $user->email_verified_at || $days === false || $days < 1 || $days > 3650) {
            return;
        }
        try {
            DB::transaction(fn () => $this->append($claim->id, $user, $days));
        } catch (UniqueConstraintViolationException|\DomainException) {
            DB::table('owner_trial_claims')->where('id', $claim->id)->update(['reason_code' => 'verified_subject_conflict']);
        }
    }

    /** Pure proposal. The lifecycle executor validates eligibility inside the admission transaction. */
    public function proposedTrial(object $claim): array
    {
        abort_unless($claim->state === 'pending' && $claim->reason_code === 'activation_unavailable' && $claim->verified_at !== null && $claim->period_id === null, 409, 'trial_eligibility_unavailable');
        $targets = app(RegistrationReadiness::class)->targets();
        abort_unless($targets, 409, 'published_targets_required');
        $at = CarbonImmutable::parse($claim->verified_at, 'UTC');

        return ['workspaceId' => (int) $claim->workspace_id, 'ownerId' => (int) $claim->owner_user_id, 'claimId' => (int) $claim->id, 'policyId' => $targets['policy']->id, 'policyHash' => $targets['policy']->content_hash, 'versionId' => $targets['studio']->id, 'fallbackId' => $targets['basic']->id, 'period' => app(SubscriptionClock::class)->period('trial', $at, 7), 'executable' => false];
    }
}
