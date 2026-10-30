<?php

namespace App\Services;

use App\Exceptions\AccountSecurityFailure;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

class AccountSecurity
{
    public function enrolled(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication() && $user->recovery_codes_acknowledged_at;
    }

    public function strong(Request $request, User $user): bool
    {
        return (int) $request->session()->get('security.user') === $user->id
            && (int) $request->session()->get('security.version', -1) === (int) $user->auth_session_version
            && in_array($request->session()->get('security.method'), ['mfa', 'passkey_uv'], true);
    }

    public function state(Request $request): string
    {
        $user = $request->user()?->fresh();
        if (! $user) {
            $pending = $request->session()->get('security.pending');
            if ($pending && DB::table('account_security_challenges')->where('id', $pending)->where('session_hash', $this->sessionHash($request))->where('purpose', 'password_2fa')->where('expires_at', '>', now())->exists()) {
                return 'pending_2fa';
            }
            $request->session()->forget('security.pending');

            return 'guest';
        }
        if (($user->platform_admin || $user->isWorkspaceOwner() || $user->hasEnabledTwoFactorAuthentication()) && ! $this->enrolled($user)) {
            return 'enrollment_required';
        }

        return $user->hasEnabledTwoFactorAuthentication() && ! $this->strong($request, $user) ? 'pending_2fa' : 'authenticated';
    }

    public function policyFingerprint(User $user): string
    {
        return hash('sha256', (int) $user->platform_admin.'|'.$user->workspaceMembers()->orderBy('id')->get(['id', 'workspace_id', 'role', 'status', 'can_create_projects', 'is_project_superadmin'])->toJson());
    }

    private function proofValid(Request $request, string $key): bool
    {
        $user = $request->user()?->fresh();
        $proof = $request->session()->get('security.'.$key, []);
        if (! $user || ! is_array($proof)) {
            return false;
        }

        return (int) ($proof['user'] ?? 0) === $user->id
            && (int) ($proof['version'] ?? -1) === (int) $user->auth_session_version
            && ($proof['session'] ?? '') === $this->sessionHash($request)
            && ($proof['policy'] ?? '') === $this->policyFingerprint($user)
            && DB::table('account_security_challenges')->where('id', $proof['id'] ?? null)->where('purpose', $key === 'step_up' ? 'step_up' : 'recovery_enrollment')->where('user_id', $user->id)->where('session_version', $user->auth_session_version)->where('session_hash', $this->sessionHash($request))->where('expires_at', '>', now())->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first(['id']) !== null
            && (int) ($proof['at'] ?? 0) > now()->subMinutes(5)->timestamp
            && (int) ($proof['at'] ?? 0) <= now()->timestamp
            && (! $user->security_recovery_expires_at || ($user->security_recovery_expires_at->isFuture() && $request->session()->get('security.operator_recovery') === true));
    }

    public function fresh(Request $request): bool
    {
        $user = $request->user()?->fresh();
        $method = $request->session()->get('security.step_up.method');

        $valid = $user && $this->proofValid($request, 'step_up');
        if (! $valid) {
            $request->session()->forget('security.step_up');
        }

        return $valid
            && (in_array($method, ['mfa', 'passkey_uv'], true) || ($method === 'password' && ! ($user->platform_admin || $user->isWorkspaceOwner()) && ! $user->hasEnabledTwoFactorAuthentication()));
    }

    public function setupFresh(Request $request): bool
    {
        return $this->fresh($request) || ($request->user() && ! $request->user()->fresh()->hasEnabledTwoFactorAuthentication() && $this->proofValid($request, 'step_up'));
    }

    public function recoveryFresh(Request $request): bool
    {
        return $this->fresh($request) || $this->proofValid($request, 'recovery_grant');
    }

    public function grantRecovery(Request $request, User $user): void
    {
        $request->session()->put('security.recovery_grant', $this->proof($request, $user, 'recovery_enrollment'));
    }

    private function proof(Request $request, User $user, string $method): array
    {
        return ['id' => $this->challenge($request, $method === 'recovery_enrollment' ? 'recovery_enrollment' : 'step_up', '{}', $user), 'user' => $user->id, 'version' => (int) $user->auth_session_version, 'session' => $this->sessionHash($request), 'policy' => $this->policyFingerprint($user), 'method' => $method, 'at' => now()->timestamp];
    }

    public function recommendationsPending(User $user): bool
    {
        return ! $user->security_recommendations_seen_at && (! $user->hasEnabledTwoFactorAuthentication() || ! $user->hasPasskeysEnabled());
    }

    public function requireFresh(Request $request): void
    {
        if (! $this->fresh($request)) {
            throw new HttpResponseException(response()->json(['code' => 'security_step_up_required', 'mutationNotStarted' => true, 'message' => trans('security.step_up_required')], 423)->header('Cache-Control', 'no-store'));
        }
    }

    public function complete(Request $request, User $user, string $method): void
    {
        Auth::login($user);
        $request->session()->forget(['security', 'client_project_id', 'project_client_id', 'client_access_token_id', CurrentWorkspace::SESSION_KEY]);
        $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $this->confirm($request, $user, $method);
    }

    public function confirm(Request $request, User $user, string $method): void
    {
        $operatorRecovery = $request->session()->get('security.operator_recovery') === true && (int) $request->session()->get('security.user') === $user->id;
        $request->session()->put('security', ['user' => $user->id, 'version' => (int) $user->auth_session_version, 'method' => $method, 'confirmed_at' => now()->timestamp, 'operator_recovery' => $operatorRecovery, 'step_up' => $this->proof($request, $user, $method)]);
    }

    public function passwordLogin(Request $request, User $user): string
    {
        $operatorRecovery = (bool) $user->security_recovery_expires_at;
        if ($operatorRecovery) {
            $user = DB::transaction(function () use ($user, $request): User {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $token = $request->input('operator_recovery_token');
                $this->valid(is_string($token) && strlen($token) === 64 && $locked->security_recovery_token_hash && $locked->security_recovery_expires_at?->isFuture() && hash_equals($locked->security_recovery_token_hash, hash('sha256', $token)));
                $locked->forceFill(['security_recovery_token_hash' => null])->save();

                return $locked;
            });
        }
        if ($user->hasEnabledTwoFactorAuthentication()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put('security.pending', $this->challenge($request, 'password_2fa', '{}', $user));

            return 'pending_2fa';
        }
        $this->complete($request, $user, 'password');
        if ($operatorRecovery) {
            $request->session()->put('security.operator_recovery', true);
        }

        return $this->state($request);
    }

    public function challenge(Request $request, string $purpose, string $options, ?User $user = null): string
    {
        DB::table('account_security_challenges')->where('expires_at', '<=', now())->delete();
        if (! str_starts_with($purpose, 'passkey_')) {
            DB::table('account_security_challenges')->where('session_hash', $this->sessionHash($request))->where('purpose', $purpose)->delete();
        }
        $id = (string) Str::uuid();
        DB::table('account_security_challenges')->insert(['id' => $id, 'user_id' => $user?->id, 'session_version' => $user ? (int) $user->auth_session_version : null, 'session_hash' => $this->sessionHash($request), 'purpose' => $purpose, 'context_hash' => in_array($purpose, ['passkey_confirm', 'passkey_register'], true) ? $this->confirmationContext($request) : null, 'options' => $options, 'expires_at' => now()->addMinutes(in_array($purpose, ['password_2fa', 'step_up', 'recovery_enrollment'], true) ? 5 : 2)]);

        return $id;
    }

    public function consume(Request $request, string $purpose, ?string $id): object
    {
        if (str_starts_with($purpose, 'passkey_')) {
            $request->attributes->set('security.stage', 'challenge');
            $this->valid((bool) $id, 'PK_CHALLENGE_MISSING');
            $row = DB::table('account_security_challenges')->where('id', $id)->where('purpose', $purpose)->first();
            $this->valid((bool) $row, 'PK_CHALLENGE_INVALID');
            $this->valid(hash_equals($row->session_hash, $this->sessionHash($request)), 'PK_CHALLENGE_SESSION');
            $this->valid($row->expires_at > now()->toDateTimeString(), 'PK_CHALLENGE_EXPIRED');
            if ($purpose !== 'passkey_login') {
                $this->valid($request->user()?->id === (int) $row->user_id, 'PK_VERIFY_FAILED');
            }
            if ($row->context_hash) {
                $this->valid(hash_equals($row->context_hash, (string) $this->confirmationContext($request)), 'PK_SESSION_CHANGED');
            }
            $this->valid(DB::table('account_security_challenges')->where('id', $row->id)->where('session_hash', $this->sessionHash($request))->where('purpose', $purpose)->where('expires_at', '>', now())->delete() === 1, 'PK_CHALLENGE_INVALID');
            if ($row->user_id) {
                $this->valid((int) User::whereKey($row->user_id)->value('auth_session_version') === (int) $row->session_version, 'PK_SESSION_CHANGED');
            }

            return $row;
        }
        $row = DB::table('account_security_challenges')->where('id', $id)->where('purpose', $purpose)->where('session_hash', $this->sessionHash($request))->where('expires_at', '>', now())->first();
        $this->valid($row && DB::table('account_security_challenges')->where('id', $row->id)->delete() === 1);
        if ($row->user_id) {
            $this->valid((int) User::whereKey($row->user_id)->value('auth_session_version') === (int) $row->session_version);
        }

        return $row;
    }

    public function confirmationContext(Request $request): ?string
    {
        $user = $request->user()?->fresh();

        return $user ? hash_hmac('sha256', json_encode([$user->id, (int) $user->auth_session_version, $request->session()->get(CurrentWorkspace::SESSION_KEY), $this->policyFingerprint($user)], JSON_THROW_ON_ERROR), (string) config('app.key')) : null;
    }

    public function rotateConfirmationSession(Request $request): void
    {
        $user = $request->user()->fresh();
        $previous = $this->sessionHash($request);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        DB::table('account_security_challenges')->whereIn('purpose', ['passkey_confirm', 'passkey_register'])->where('session_hash', $previous)
            ->where('user_id', $user->id)->where('session_version', (int) $user->auth_session_version)->where('expires_at', '>', now())
            ->update(['session_hash' => $this->sessionHash($request)]);
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    /** Called inside a transaction after locking the user row. */
    public function verifyFactor(User $user, array $data): bool
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return false;
        }
        if (! empty($data['recovery_code'])) {
            $codes = $user->recoveryCodes();
            foreach ($codes as $index => $code) {
                if (hash_equals($code, (string) $data['recovery_code'])) {
                    unset($codes[$index]);
                    $user->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_values($codes)))])->save();
                    $this->audit($user, 'recovery_code_used');

                    return true;
                }
            }

            return false;
        }

        return $this->verifyTotp($user, $user->two_factor_secret, (string) ($data['code'] ?? ''));
    }

    public function verifyTotp(User $user, string $encrypted, string $code, bool $rotation = false): bool
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return false;
        }
        $step = app(Google2FA::class)->verifyKeyNewer(Fortify::currentEncrypter()->decrypt($encrypted), $code, $rotation ? 0 : ($user->last_totp_step ?? 0), 1, (int) floor(now()->timestamp / 30));
        if ($step === false) {
            return false;
        }
        $user->forceFill(['last_totp_step' => $step])->save();

        return true;
    }

    public function recoveryCodes(User $user): array
    {
        $codes = array_map(fn () => RecoveryCode::generate(), range(1, 8));
        $user->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes)), 'recovery_codes_acknowledged_at' => null])->save();

        return $codes;
    }

    public function changed(User $user, Request $request, string $event): void
    {
        app(AccountProfile::class)->revokeSessions($user, $request);
        $request->session()->put('security.version', (int) $user->auth_session_version);
        $request->session()->forget(['security.step_up', 'security.recovery_grant']);
        $request->session()->regenerateToken();
        DB::table('account_security_challenges')->where('user_id', $user->id)->delete();
        $this->audit($user, $event);
    }

    public function audit(User $user, string $event): void
    {
        if ($user->platform_admin) {
            app(PlatformAudit::class)->record($user->id, $event, 'user', (string) $user->id);
        }
        Log::notice('account_security', ['user_id' => $user->id, 'event' => $event]);
    }

    public function valid(bool $condition, ?string $diagnosticCode = null): void
    {
        if (! $condition) {
            if ($diagnosticCode) {
                throw new AccountSecurityFailure($diagnosticCode);
            }
            throw ValidationException::withMessages(['code' => [trans('security.invalid')]]);
        }
    }
}
