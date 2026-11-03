<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AuthOnboarding
{
    public function register(array $data): void
    {
        abort_unless(app(RegistrationReadiness::class)->registrationEnabled(), 403, 'registration_disabled');
        $email = DeveloperInvitations::normalizeEmail($data['email']);
        $password = Hash::make($data['password']);
        try {
            $user = DB::transaction(function () use ($data, $email, $password): ?User {
                if (User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
                    return null;
                }
                $user = User::create(['name' => 'Dev2Done', 'email' => $email, 'password' => $password, 'preferred_locale' => SupportedLocales::storage($data['locale'] ?? 'en')]);
                $this->pending($user);
                $this->audit($user, 'registration.created');

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }
        if ($user) {
            $this->sendVerification($user);
        }
    }

    public function pending(User $user): void
    {
        DB::table('account_onboardings')->insert(['user_id' => $user->id, 'uuid' => (string) Str::uuid(), 'state' => 'email_verification_pending', 'revision' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    public function sendVerification(User $user): void
    {
        $delivery = DB::transaction(function () use ($user): ?array {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('account_onboardings')->where('user_id', $locked->id)->lockForUpdate()->first();
            if (! $row || $locked->email_verified_at || ($row->sent_at && CarbonImmutable::parse($row->sent_at, 'UTC')->addSeconds(config('onboarding.resend_seconds')) > CarbonImmutable::now('UTC'))) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('account_onboardings')->where('user_id', $locked->id)->update(['verification_hash' => hash('sha256', $token), 'consumed_hash' => null, 'email_hash' => app(TrialEligibility::class)->hash('dev2done.email', DeveloperInvitations::normalizeEmail($locked->email)), 'expires_at' => CarbonImmutable::now('UTC')->addMinutes(config('onboarding.verification_minutes'))->format('Y-m-d H:i:s.u'), 'sent_at' => now('UTC'), 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
            $this->audit($locked, 'registration.verification_issued');

            return [$locked->email, $locked->preferred_locale, $token];
        }, 3);
        if ($delivery) {
            app(AccountMail::class)->localized($delivery[0], $delivery[1], 'registration_verify', rtrim(config('app.url'), '/').'/admin/verify-email#token='.$delivery[2]);
        }
    }

    public function verify(User $actor, #[\SensitiveParameter] string $token): array
    {
        return DB::transaction(function () use ($actor, $token): array {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('account_onboardings')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $hash = hash('sha256', $token);
            if ($user->email_verified_at && $row->consumed_hash && hash_equals($row->consumed_hash, $hash)) {
                return ['verified' => true, 'replayed' => true];
            }
            abort_unless(! $user->email_verified_at && $row->verification_hash && hash_equals($row->verification_hash, $hash) && $row->email_hash === app(TrialEligibility::class)->hash('dev2done.email', DeveloperInvitations::normalizeEmail($user->email)) && CarbonImmutable::parse($row->expires_at, 'UTC') > CarbonImmutable::now('UTC'), 410, 'verification_expired');
            $at = CarbonImmutable::now('UTC');
            $user->forceFill(['email_verified_at' => $at])->save();
            DB::table('account_onboardings')->where('user_id', $user->id)->update(['state' => 'identity_verified', 'verified_at' => $at->format('Y-m-d H:i:s.u'), 'verification_hash' => null, 'consumed_hash' => $hash, 'expires_at' => null, 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
            $this->prepare($user);
            $this->audit($user, 'registration.verified');

            return ['verified' => true, 'replayed' => false];
        }, 3);
    }

    /** Called only after validated provider email assurance or matching authenticated mailbox proof. */
    public function prepare(User $user): Workspace
    {
        return DB::transaction(function () use ($user): Workspace {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->email_verified_at, 403);
            $row = DB::table('account_onboardings')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($row, 403);
            $workspace = app(OwnerProvisioning::class)->prepareVerifiedOwner($locked);
            $at = $row->verified_at ? CarbonImmutable::parse($row->verified_at, 'UTC') : CarbonImmutable::now('UTC');
            DB::table('account_onboardings')->where('user_id', $user->id)->update(['state' => 'workspace_prepared', 'verified_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => now('UTC')]);
            app(TrialEligibility::class)->prepare($locked, $workspace, $at);

            return $workspace;
        }, 3);
    }

    public function changePendingEmail(User $actor, string $email, ?string $password): void
    {
        DB::transaction(function () use ($actor, $email, $password): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('account_onboardings')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($row && ! $user->email_verified_at && ! $row->workspace_id, 403);
            abort_if($user->password_login_enabled && (! is_string($password) || ! Hash::check($password, $user->password)), 422, 'onboarding_request_invalid');
            $email = DeveloperInvitations::normalizeEmail($email);
            abort_if(User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->where('id', '<>', $user->id)->exists(), 422, 'onboarding_request_invalid');
            $user->forceFill(['email' => $email, ...app(AccountProfile::class)->clearEmail()])->save();
            DB::table('account_onboardings')->where('user_id', $user->id)->update(['verification_hash' => null, 'consumed_hash' => null, 'sent_at' => null, 'expires_at' => null, 'email_hash' => null, 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
            $this->audit($user, 'registration.email_changed');
        }, 3);
        $this->sendVerification($actor->fresh());
    }

    public function state(Request $request): array
    {
        $user = $request->user()?->fresh();
        $row = $user ? DB::table('account_onboardings')->where('user_id', $user->id)->first() : null;
        if (! $row || $row->state === 'invited_developer') {
            return ['onboarding' => false, 'state' => $user ? 'existing_account' : 'guest'];
        }
        $security = app(AccountSecurity::class)->state($request);
        $verified = $user->email_verified_at !== null;
        $member = $row->workspace_id ? $user->workspaceMembers()->active()->with('workspace')->where('workspace_id', $row->workspace_id)->first() : null;
        $agreementRequired = $member && app(WorkspaceAgreements::class)->required($member);
        $checks = ['emailVerified' => $verified, 'totpConfirmed' => $user->hasEnabledTwoFactorAuthentication(), 'recoveryAcknowledged' => (bool) $user->recovery_codes_acknowledged_at, 'agreementAccepted' => ! $agreementRequired, 'workspacePrepared' => (bool) $member];
        $ready = ! in_array(false, $checks, true) && $security === 'authenticated';
        $step = match (true) {
            ! $verified => 'email',
            ! $checks['totpConfirmed'] => 'authenticator',
            ! $checks['recoveryAcknowledged'] => 'recovery',
            $security !== 'authenticated' => 'security',
            $agreementRequired => 'agreement',
            ! $checks['workspacePrepared'] => 'preparing',
            default => 'complete',
        };
        $nextPath = match ($step) {
            'email' => '/admin/verify-email',
            'agreement' => '/admin/agreement',
            'complete', 'preparing' => '/admin/onboarding',
            default => '/admin/security',
        };

        return ['onboarding' => true, 'state' => ! $verified ? 'email_verification_pending' : ($ready ? 'ready_for_trial_activation' : 'security_and_agreement_onboarding'), 'step' => $step, 'checks' => $checks, 'agreementRequired' => (bool) $agreementRequired, 'registrationEnabled' => app(RegistrationReadiness::class)->registrationEnabled(), 'email' => $user->email, 'emailVerified' => $verified, 'passwordEnabled' => (bool) $user->password_login_enabled, 'workspacePrepared' => $checks['workspacePrepared'], 'securityState' => $security, 'trialActivationEnabled' => app(RegistrationReadiness::class)->activationEnabled(), 'resendAfter' => $row->sent_at ? CarbonImmutable::parse($row->sent_at, 'UTC')->addSeconds(config('onboarding.resend_seconds'))->toISOString() : null, 'nextPath' => $nextPath];
    }

    public function audit(User $user, string $event, array $metadata = []): void
    {
        $uuid = DB::table('account_onboardings')->where('user_id', $user->id)->value('uuid') ?? (string) Str::uuid();
        DB::table('plan_events')->insert(['uuid' => (string) Str::uuid(), 'aggregate_type' => 'account_onboarding', 'aggregate_uuid' => $uuid, 'actor_kind' => 'owner', 'actor_user_id' => $user->id, 'action' => $event, 'result' => 'prepared', 'diff_json' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
