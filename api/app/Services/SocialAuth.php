<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SocialAuth
{
    private function sessionHash(Request $request): string
    {
        return hash_hmac('sha256', 'social|'.$request->session()->getId(), (string) config('app.key'));
    }

    public function requireLinkProof(Request $request): User
    {
        $user = $request->user()?->fresh();
        abort_unless($user && ! $user->security_recovery_expires_at && app(AccountSecurity::class)->strong($request, $user), 403, 'social_fresh_proof_required');
        app(AccountSecurity::class)->requireFresh($request);

        return $user;
    }

    public function start(Request $request, string $provider, string $intent): string
    {
        abort_unless(in_array($intent, ['login', 'link'], true) && app(RegistrationReadiness::class)->provider($provider), 503, 'provider_unavailable');
        $actor = $intent === 'link' ? $this->requireLinkProof($request) : null;
        abort_if($intent === 'login' && $request->user(), 409, 'social_sign_out_first');
        $invitation = $request->session()->get('invitation_context');
        if ($invitation) {
            app(DeveloperInvitations::class)->resolveRequest($request, null);
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $nonce = bin2hex(random_bytes(32));
        DB::table('social_auth_attempts')->insert(['state_hash' => hash('sha256', $state), 'provider' => $provider, 'intent' => $intent, 'locale' => app()->getLocale(), 'session_hash' => $this->sessionHash($request), 'actor_user_id' => $actor?->id, 'session_version' => $actor?->auth_session_version, 'verifier_cipher' => Crypt::encryptString($verifier), 'nonce_hash' => hash('sha256', $nonce), 'invitation_cipher' => $invitation ? Crypt::encryptString(json_encode($invitation, JSON_THROW_ON_ERROR)) : null, 'state' => 'pending', 'expires_at' => CarbonImmutable::now('UTC')->addMinutes(config('onboarding.attempt_minutes'))->format('Y-m-d H:i:s.u'), 'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);

        return app(SocialProvider::class)->authorization($provider, $state, $verifier, $nonce);
    }

    /** Consumes the attempt before network IO. Duplicate/concurrent callbacks cannot exchange or authorize twice. */
    public function callback(Request $request, string $provider, string $state, #[\SensitiveParameter] ?string $code): array
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $state), 422, 'social_attempt_invalid');
        $attempt = DB::transaction(function () use ($request, $provider, $state): object {
            $row = DB::table('social_auth_attempts')->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            abort_unless($row && $row->provider === $provider && $row->state === 'pending' && hash_equals($row->session_hash, $this->sessionHash($request)) && CarbonImmutable::parse($row->expires_at, 'UTC') > CarbonImmutable::now('UTC'), 422, 'social_attempt_invalid');
            if ($row->intent === 'link') {
                $actor = $this->requireLinkProof($request);
                abort_unless($actor->id === (int) $row->actor_user_id && (int) $actor->auth_session_version === (int) $row->session_version, 403);
            } else {
                abort_if($request->user(), 409, 'social_sign_out_first');
            }
            DB::table('social_auth_attempts')->where('state_hash', $row->state_hash)->update(['state' => 'processing', 'consumed_at' => now('UTC'), 'verifier_cipher' => '']);

            return $row;
        }, 3);
        try {
            abort_unless(is_string($code) && $code !== '' && strlen($code) <= 4096, 422, 'social_cancelled');
            $facts = app(SocialProvider::class)->exchange($provider, $code, Crypt::decryptString($attempt->verifier_cipher), $attempt->nonce_hash, CarbonImmutable::parse($attempt->created_at, 'UTC')->timestamp);
            $bound = DB::table('user_external_identities')->where('provider_key', $facts['provider'])->where('issuer_key', $facts['issuer'])->where('subject_hash', $facts['subjectHash'])->exists();
            if ($attempt->intent === 'login' && ! $bound && ! $facts['email']) {
                abort_unless(app(RegistrationReadiness::class)->registrationEnabled(), 403, 'registration_disabled');
                DB::table('social_auth_attempts')->where('state_hash', $attempt->state_hash)->update(['state' => 'email_required', 'facts_cipher' => Crypt::encryptString(json_encode($facts, JSON_THROW_ON_ERROR))]);
                $request->session()->put('onboarding.social_pending', $attempt->state_hash);

                return ['path' => '/register?providerEmail=1', 'state' => 'email_required'];
            }

            return $this->finish($request, $attempt, $facts);
        } catch (\Throwable $e) {
            DB::table('social_auth_attempts')->where('state_hash', $attempt->state_hash)->update(['state' => 'failed', 'facts_cipher' => null, 'invitation_cipher' => null, 'verifier_cipher' => '']);
            throw $e;
        }
    }

    public function supplyEmail(Request $request, string $email): array
    {
        $row = DB::transaction(function () use ($request): object {
            $row = DB::table('social_auth_attempts')->where('state_hash', $request->session()->get('onboarding.social_pending'))->lockForUpdate()->first();
            abort_unless($row && $row->state === 'email_required' && $row->facts_cipher && hash_equals($row->session_hash, $this->sessionHash($request)) && CarbonImmutable::parse($row->expires_at, 'UTC') > CarbonImmutable::now('UTC'), 422, 'social_attempt_invalid');
            DB::table('social_auth_attempts')->where('state_hash', $row->state_hash)->update(['state' => 'processing', 'facts_cipher' => null]);

            return $row;
        }, 3);
        $request->session()->forget('onboarding.social_pending');
        $facts = json_decode(Crypt::decryptString($row->facts_cipher), true, 16, JSON_THROW_ON_ERROR);
        $facts['email'] = app(SocialProvider::class)->usableEmail($email);
        $facts['emailVerified'] = false;
        abort_unless($facts['email'], 422, 'onboarding_request_invalid');

        return $this->finish($request, $row, $facts);
    }

    private function finish(Request $request, object $attempt, array $facts): array
    {
        if ($attempt->invitation_cipher) {
            $request->session()->put('invitation_context', json_decode(Crypt::decryptString($attempt->invitation_cipher), true, 16, JSON_THROW_ON_ERROR));
        }
        $invitation = $attempt->invitation_cipher ? app(DeveloperInvitations::class)->resolveRequest($request, null) : null;
        $new = false;
        $user = DB::transaction(function () use ($request, $attempt, $facts, $invitation, &$new): User {
            $identityQuery = DB::table('user_external_identities')->where('provider_key', $facts['provider'])->where('issuer_key', $facts['issuer'])->where('subject_hash', $facts['subjectHash']);
            if ($attempt->intent === 'link') {
                $actor = $this->requireLinkProof($request);
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->id === (int) $attempt->actor_user_id && (int) $user->auth_session_version === (int) $attempt->session_version, 403);
                $identity = (clone $identityQuery)->lockForUpdate()->first();
                abort_if($identity && (int) $identity->user_id !== $user->id, 409, 'social_identity_already_linked');
            } else {
                $identity = (clone $identityQuery)->first();
                if ($identity) {
                    $user = User::whereKey($identity->user_id)->lockForUpdate()->firstOrFail();
                    $identity = (clone $identityQuery)->lockForUpdate()->first();
                    abort_unless($identity && (int) $identity->user_id === $user->id, 409, 'social_identity_already_linked');
                } else {
                    abort_unless($facts['email'], 422, 'social_email_required');
                    abort_unless(app(RegistrationReadiness::class)->registrationEnabled() || ($invitation && DeveloperInvitations::emailsMatch($invitation->email, $facts['email'])), 403, 'registration_disabled');
                    abort_if(User::whereRaw('LOWER(TRIM(email)) = ?', [$facts['email']])->exists(), 409, 'social_account_link_required');
                    $user = User::create(['name' => 'Dev2Done', 'email' => $facts['email'], 'password' => Hash::make(bin2hex(random_bytes(48))), 'preferred_locale' => SupportedLocales::storage($attempt->locale)]);
                    $user->forceFill(['password_login_enabled' => false, 'email_verified_at' => $facts['emailVerified'] ? now('UTC') : null])->save();
                    if (! $invitation) {
                        app(AuthOnboarding::class)->pending($user);
                    }
                    $new = true;
                }
            }
            if (! $identity) {
                DB::table('user_external_identities')->insert(['user_id' => $user->id, 'provider_key' => $facts['provider'], 'issuer_key' => $facts['issuer'], 'subject_hash' => $facts['subjectHash'], 'hash_key_version' => TrialEligibility::HASH_VERSION, 'verified_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }
            if ($new && ! $invitation && $user->email_verified_at) {
                app(AuthOnboarding::class)->prepare($user);
            }
            if ($user->email_verified_at) {
                app(TrialEligibility::class)->rememberVerifiedSubjects($user);
            }
            app(AuthOnboarding::class)->audit($user, $attempt->intent === 'link' ? 'social.linked' : 'social.authenticated', ['provider' => $facts['provider']]);
            DB::table('social_auth_attempts')->where('state_hash', $attempt->state_hash)->update(['state' => 'completed', 'facts_cipher' => null, 'invitation_cipher' => null, 'verifier_cipher' => '']);

            return $user;
        }, 3);
        if ($attempt->intent === 'link') {
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();

            return ['path' => $this->connectionsPath($request).'?connected='.$facts['provider'], 'state' => 'linked'];
        }
        $eligible = $user->platform_admin || $user->workspaceMembers()->active()->exists() || DB::table('account_onboardings')->where('user_id', $user->id)->where('state', '<>', 'invited_developer')->exists() || ($invitation && DeveloperInvitations::emailsMatch($user->email, $invitation->email));
        abort_unless($eligible, 403, 'social_account_unavailable');
        $context = $request->session()->get('invitation_context');
        $state = app(AccountSecurity::class)->passwordLogin($request, $user);
        if ($context) {
            $request->session()->put('invitation_context', $context);
        }
        if ($new && ! $invitation && ! $user->email_verified_at) {
            app(AuthOnboarding::class)->sendVerification($user);
        }
        $onboarding = app(AuthOnboarding::class)->state($request);

        return ['path' => $invitation ? '/invitation' : (in_array($state, ['pending_2fa', 'enrollment_required'], true) ? '/admin/security' : ($onboarding['nextPath'] ?? '/app')), 'state' => $state];
    }

    public function connectionsPath(Request $request): string
    {
        return app(AuthOnboarding::class)->state($request)['onboarding'] ? '/admin/onboarding' : '/admin/account/security';
    }

    public function connections(User $user): array
    {
        $identities = DB::table('user_external_identities')->where('user_id', $user->id)->pluck('provider_key')->all();

        return ['passwordEnabled' => (bool) $user->password_login_enabled, 'connections' => array_map(fn ($p) => ['provider' => $p, 'connected' => in_array($p, $identities, true), 'configured' => app(RegistrationReadiness::class)->provider($p)], RegistrationReadiness::PROVIDERS)];
    }

    public function disconnect(Request $request, string $provider): void
    {
        $actor = $this->requireLinkProof($request);
        DB::transaction(function () use ($request, $actor, $provider): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->requireLinkProof($request);
            $others = DB::table('user_external_identities')->where('user_id', $user->id)->where('provider_key', '<>', $provider)->pluck('provider_key')->filter(fn ($p) => app(RegistrationReadiness::class)->provider($p))->count();
            abort_unless($user->password_login_enabled || $user->passkeys()->exists() || $others > 0, 409, 'social_last_login_method');
            DB::table('user_external_identities')->where('user_id', $user->id)->where('provider_key', $provider)->delete();
            app(AuthOnboarding::class)->audit($user, 'social.unlinked', ['provider' => $provider]);
            app(AccountProfile::class)->revokeSessions($user, $request);
        }, 3);
    }
}
