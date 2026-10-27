<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountSecurityFailure;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializationException;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class AccountSecurityController extends Controller
{
    public function __construct(private AccountSecurity $security) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()?->fresh();
        $fresh = $this->security->fresh($request);

        return response()->json([
            'state' => $this->security->state($request),
            'signedIn' => (bool) $user,
            'owner' => $user?->isWorkspaceOwner() ?? false,
            'totp' => $user?->hasEnabledTwoFactorAuthentication() ?? false,
            'recoverySaved' => (bool) $user?->recovery_codes_acknowledged_at,
            'fresh' => $fresh,
            'freshFor' => $fresh ? max(0, min(300, (int) $request->session()->get('security.step_up.at') + 300 - now()->timestamp)) : 0,
            'confirmationContext' => $user ? hash_hmac('sha256', json_encode([$user->id, (int) $user->auth_session_version, $request->session()->get(CurrentWorkspace::SESSION_KEY), $this->security->policyFingerprint($user)], JSON_THROW_ON_ERROR), (string) config('app.key')) : null,
            'setupFresh' => $this->security->setupFresh($request),
            'recoveryFresh' => $this->security->recoveryFresh($request),
            'confirmationMethod' => $fresh ? $request->session()->get('security.step_up.method') : null,
            'recommendationsPending' => $user ? $this->security->recommendationsPending($user) : false,
            'passkeys' => $user ? $user->passkeys()->orderBy('id')->get()->map(fn (Passkey $key) => ['id' => $key->id, 'name' => $key->name, 'createdAt' => $key->created_at?->toISOString(), 'lastUsedAt' => $key->last_used_at?->toISOString()]) : [],
        ])->header('Cache-Control', 'no-store');
    }

    public function challenge(Request $request): JsonResponse
    {
        $data = $this->factorInput($request);
        $pending = $request->session()->get('security.pending');
        $row = DB::table('account_security_challenges')->where('id', $pending)->first();
        $this->security->valid((bool) $row);
        $user = DB::transaction(function () use ($request, $data, $row, $pending): User {
            $user = User::whereKey($row->user_id)->lockForUpdate()->firstOrFail();
            $this->security->consume($request, 'password_2fa', $pending);
            $this->security->valid($this->security->verifyFactor($user, $data));

            return $user;
        });
        $this->security->complete($request, $user, 'mfa');
        $this->security->audit($user, 'password_mfa_login');

        return $this->show($request);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $this->factorInput($request);
        $request->validate(['current_password' => ['nullable', 'string', 'max:1024']]);
        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->hasEnabledTwoFactorAuthentication()) {
                $this->security->valid($this->security->verifyFactor($user, $data));
            } else {
                $this->security->valid(Hash::check($request->string('current_password')->toString(), $user->password));
            }

            return $user;
        });
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $this->security->confirm($request, $user, $user->hasEnabledTwoFactorAuthentication() ? 'mfa' : 'password');

        return $this->show($request);
    }

    private function requireConfirmation(Request $request, bool $valid): void
    {
        if (! $valid) {
            $request->attributes->set('security.mutation_not_started', true);
            abort(423, trans('security.step_up_required'));
        }
    }

    private function factorInput(Request $request): array
    {
        return $request->validate(['code' => ['nullable', 'string', 'regex:/^\d{6}$/D'], 'recovery_code' => ['nullable', 'string', 'max:100']]);
    }

    public function setupTotp(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $this->requireConfirmation($request, $this->security->setupFresh($request));
        $user = DB::transaction(function () use ($request, $provider): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->requireConfirmation($request, $this->security->setupFresh($request));
            if (! $user->pending_two_factor_secret) {
                $user->forceFill(['pending_two_factor_secret' => Fortify::currentEncrypter()->encrypt($provider->generateSecretKey(32))])->save();
            }

            return $user;
        });
        $preview = clone $user;
        $preview->two_factor_secret = $user->pending_two_factor_secret;

        return response()->json(['svg' => $preview->twoFactorQrCodeSvg(), 'uri' => $preview->twoFactorQrCodeUrl()])->header('Cache-Control', 'no-store');
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $this->requireConfirmation($request, $this->security->setupFresh($request));
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);
        $codes = DB::transaction(function () use ($request): array {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->requireConfirmation($request, $this->security->setupFresh($request));
            $this->security->valid($user->pending_two_factor_secret && $this->security->verifyTotp($user, $user->pending_two_factor_secret, $request->input('code'), true));
            $user->forceFill(['two_factor_secret' => $user->pending_two_factor_secret, 'pending_two_factor_secret' => null, 'two_factor_confirmed_at' => now()])->save();
            $codes = $this->security->recoveryCodes($user);
            $operatorRecovery = $request->session()->get('security.operator_recovery') === true;
            $this->security->confirm($request, $user, 'mfa');
            if ($operatorRecovery) {
                $request->session()->put('security.operator_recovery', true);
            }
            $this->security->changed($user, $request, 'totp_confirmed');
            $this->security->grantRecovery($request, $user);

            return $codes;
        });

        return response()->json(['codes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function recovery(Request $request): JsonResponse
    {
        $this->requireConfirmation($request, $request->isMethod('POST') ? $this->security->fresh($request) : $this->security->recoveryFresh($request));
        $codes = DB::transaction(function () use ($request): array {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->requireConfirmation($request, $request->isMethod('POST') ? $this->security->fresh($request) : $this->security->recoveryFresh($request));
            abort_unless($user->hasEnabledTwoFactorAuthentication(), 403);
            if ($request->isMethod('POST')) {
                $codes = $this->security->recoveryCodes($user);
                $this->security->changed($user, $request, 'recovery_codes_regenerated');
                $this->security->grantRecovery($request, $user);

                return $codes;
            }

            return $user->recoveryCodes();
        });

        return response()->json(['codes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function acknowledgeRecovery(Request $request): JsonResponse
    {
        $this->requireConfirmation($request, $this->security->recoveryFresh($request));
        DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->requireConfirmation($request, $this->security->recoveryFresh($request));
            abort_unless($user->hasEnabledTwoFactorAuthentication() && count($user->recoveryCodes()) > 0, 403);
            $user->forceFill(['recovery_codes_acknowledged_at' => now(), 'security_recovery_token_hash' => null, 'security_recovery_expires_at' => null])->save();
            DB::table('account_security_challenges')->where('id', $request->session()->get('security.recovery_grant.id'))->where('user_id', $user->id)->where('purpose', 'recovery_enrollment')->delete();
            $request->session()->forget(['security.recovery_grant', 'security.operator_recovery']);
        });

        return $this->show($request);
    }

    public function options(Request $request, string $purpose): JsonResponse
    {
        $request->attributes->set('security.stage', 'options');
        $this->transport();
        $user = $request->user()?->fresh();
        if ($purpose !== 'login') {
            abort_unless($user, 401);
        }
        if ($purpose === 'register') {
            $this->security->requireFresh($request);
            abort_if($user->isWorkspaceOwner() && (! $user->hasEnabledTwoFactorAuthentication() || ! $user->recovery_codes_acknowledged_at), 403);
            abort_if($user->passkeys()->count() >= 20, 422);
        }
        $options = $purpose === 'register' ? app(GenerateRegistrationOptions::class)($user) : app(GenerateVerificationOptions::class)($purpose === 'confirm' ? $user : null);
        $id = $this->security->challenge($request, 'passkey_'.$purpose, WebAuthn::toJson($options), $purpose === 'login' ? null : $user);
        $request->session()->put('security.passkey_'.$purpose, $id);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)])->header('Cache-Control', 'no-store');
    }

    public function passkey(Request $request, string $purpose): JsonResponse
    {
        $request->attributes->set('security.stage', 'request');
        $this->transport();
        $request->validate(['credential' => ['required', 'array'], 'credential.rawId' => ['required', 'string', 'max:2048'], 'name' => [$purpose === 'register' ? 'required' : 'nullable', 'string', 'max:100']]);
        if ($purpose !== 'login') {
            abort_unless($request->user(), 401);
        }
        if ($purpose === 'register') {
            $this->security->requireFresh($request);
        }
        $row = $this->security->consume($request, 'passkey_'.$purpose, $request->session()->pull('security.passkey_'.$purpose));
        try {
            $request->attributes->set('security.stage', 'deserialize');
            $credential = WebAuthn::fromJson(json_encode($request->input('credential'), JSON_THROW_ON_ERROR), PublicKeyCredential::class);
            $request->attributes->set('security.stage', 'verify_store');
            if ($purpose === 'register') {
                $user = DB::transaction(function () use ($request, $row, $credential): User {
                    $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                    $this->security->requireFresh($request);
                    $this->security->valid((int) $row->user_id === $user->id && (int) $row->session_version === (int) $user->auth_session_version, 'PK_SESSION_CHANGED');
                    abort_if($user->isWorkspaceOwner() && (! $user->hasEnabledTwoFactorAuthentication() || ! $user->recovery_codes_acknowledged_at), 403);
                    abort_if($user->passkeys()->count() >= 20, 422);
                    app(StorePasskey::class)($user, $request->input('name'), $credential, WebAuthn::fromJson($row->options, PublicKeyCredentialCreationOptions::class));
                    if ($this->security->enrolled($user)) {
                        $user->forceFill(['security_recovery_token_hash' => null, 'security_recovery_expires_at' => null])->save();
                    }
                    $request->attributes->set('security.stage', 'session_update');
                    $this->security->changed($user, $request, 'passkey_registered');

                    return $user;
                });
            } else {
                $user = DB::transaction(function () use ($request, $row, $credential, $purpose): User {
                    $key = Passkey::where('credential_id', $request->input('credential.rawId'))->first();
                    $this->security->valid((bool) $key, 'PK_VERIFY_FAILED');
                    $user = User::whereKey($key->user_id)->lockForUpdate()->firstOrFail();
                    if ($purpose === 'confirm') {
                        $this->security->valid($user->id === $request->user()->id && (int) $row->user_id === $user->id && (int) $row->session_version === (int) $user->auth_session_version, 'PK_VERIFY_FAILED');
                    }
                    $this->security->valid($user->workspaceMembers()->active()->exists(), 'PK_VERIFY_FAILED');
                    $this->security->valid((string) ($credential->response->userHandle ?? '') === $user->getPasskeyUserHandle(), 'PK_VERIFY_FAILED');
                    app(VerifyPasskey::class)($credential, WebAuthn::fromJson($row->options, PublicKeyCredentialRequestOptions::class), $user);

                    return $user;
                });
            }
        } catch (AuthenticatorResponseVerificationException $exception) {
            $code = match ($exception->getMessage()) {
                'Invalid challenge.' => 'PK_CHALLENGE_MISMATCH',
                'rpId hash mismatch.' => 'PK_RP_MISMATCH',
                'User authentication required.' => 'PK_UV_REQUIRED',
                'Invalid origin.', 'Invalid scheme. HTTPS required.', 'Invalid origin. Subdomains are not allowed.', 'Invalid origin. Not in the list of allowed origins.', 'Invalid origin. Unable to parse the origin.', 'Invalid origin. Unable to determine the facet ID.' => 'PK_ORIGIN_MISMATCH',
                default => 'PK_VERIFY_FAILED',
            };
            throw new AccountSecurityFailure($code);
        } catch (InvalidPasskeyException) {
            throw new AccountSecurityFailure($request->attributes->get('security.stage') === 'uniqueness' ? 'PK_ALREADY_REGISTERED' : 'PK_VERIFY_FAILED');
        } catch (WebauthnException) {
            throw new AccountSecurityFailure($request->attributes->get('security.stage') === 'deserialize' ? 'PK_PAYLOAD_INVALID' : 'PK_VERIFY_FAILED', $request->attributes->get('security.stage') === 'deserialize' ? 400 : 422);
        } catch (SerializationException|\JsonException|\InvalidArgumentException $exception) {
            if ($exception instanceof \InvalidArgumentException && $request->attributes->get('security.stage') === 'verify_store' && str_starts_with($exception->getMessage(), 'Invalid data.')) {
                throw new AccountSecurityFailure('PK_VERIFY_FAILED');
            }
            if ($request->attributes->get('security.stage') !== 'deserialize') {
                throw $exception;
            }
            throw new AccountSecurityFailure('PK_PAYLOAD_INVALID', 400);
        }
        if ($purpose === 'login') {
            $this->security->complete($request, $user, 'passkey_uv');
            $this->security->audit($user, 'passkey_login');
        } elseif ($purpose === 'confirm') {
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
            $this->security->confirm($request, $user, 'passkey_uv');
        }

        return $this->show($request);
    }

    public function updatePasskey(Request $request, int $id): JsonResponse
    {
        $this->security->requireFresh($request);
        $request->validate(['name' => ['required', 'string', 'max:100']]);
        DB::transaction(function () use ($request, $id): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->security->requireFresh($request);
            $user->passkeys()->whereKey($id)->firstOrFail()->update(['name' => $request->input('name')]);
            $this->security->audit($user, 'passkey_renamed');
        });

        return $this->show($request);
    }

    public function deletePasskey(Request $request, int $id): JsonResponse
    {
        $this->security->requireFresh($request);
        DB::transaction(function () use ($request, $id): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $key = $user->passkeys()->whereKey($id)->firstOrFail();
            $this->security->requireFresh($request);
            if ($user->passkeys()->count() <= 1) {
                $this->security->valid(Hash::check($request->string('current_password')->toString(), $user->password), 'SEC_ALTERNATIVE_REQUIRED');
                abort_if($user->isWorkspaceOwner() && ! $this->security->enrolled($user), 409);
                if ($user->hasEnabledTwoFactorAuthentication() && $request->session()->get('security.step_up.method') !== 'mfa') {
                    $this->security->valid($this->security->verifyFactor($user, $this->factorInput($request)));
                }
            }
            $key->delete();
            $this->security->changed($user, $request, 'passkey_deleted');
        });

        return $this->show($request);
    }

    public function acknowledgeRecommendations(Request $request): JsonResponse
    {
        $request->user()->forceFill(['security_recommendations_seen_at' => now()])->save();

        return $this->show($request);
    }

    public function disableTotp(Request $request): JsonResponse
    {
        $this->security->requireFresh($request);
        DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->isWorkspaceOwner()) {
                throw new AccountSecurityFailure('SEC_OWNER_TOTP_REQUIRED', 403);
            }
            $this->security->requireFresh($request);
            $this->security->valid(Hash::check($request->string('current_password')->toString(), $user->password), 'SEC_ALTERNATIVE_REQUIRED');
            $user->forceFill(['two_factor_secret' => null, 'pending_two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'recovery_codes_acknowledged_at' => null, 'last_totp_step' => null])->save();
            $this->security->confirm($request, $user, 'password');
            $this->security->changed($user, $request, 'totp_disabled');
        });

        return $this->show($request);
    }

    private function transport(): void
    {
        $origin = config('fortify.passkeys.allowed_origins.0');
        $url = parse_url($origin);
        abort_unless(isset($url['host'], $url['scheme']) && (! isset($url['path']) || $url['path'] === '') && ! isset($url['query']) && ! isset($url['fragment']) && ! isset($url['user']) && ! isset($url['pass']) && ($url['scheme'] === 'https' || ($url['scheme'] === 'http' && app()->environment('local', 'testing') && in_array($url['host'], ['localhost', '127.0.0.1'], true))), 503, trans('security.unavailable'));
    }
}
