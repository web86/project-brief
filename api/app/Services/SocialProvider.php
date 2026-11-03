<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

final class SocialProvider
{
    private const AUTH = ['google' => 'https://accounts.google.com/o/oauth2/v2/auth', 'yandex' => 'https://oauth.yandex.ru/authorize', 'github' => 'https://github.com/login/oauth/authorize'];

    private const TOKEN = ['google' => 'https://oauth2.googleapis.com/token', 'yandex' => 'https://oauth.yandex.ru/token', 'github' => 'https://github.com/login/oauth/access_token'];

    private const SCOPES = ['google' => 'openid email profile', 'yandex' => 'login:email login:info', 'github' => 'user:email'];

    private const ISSUER = ['google' => 'https://accounts.google.com', 'yandex' => 'https://login.yandex.ru', 'github' => 'https://github.com'];

    public function authorization(string $provider, string $state, string $verifier, string $nonce): string
    {
        abort_unless(app(RegistrationReadiness::class)->provider($provider), 503, 'provider_unavailable');
        $params = ['client_id' => config('onboarding.providers.'.$provider.'.client_id'), 'redirect_uri' => app(RegistrationReadiness::class)->callback($provider), 'response_type' => 'code', 'scope' => self::SCOPES[$provider], 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        if ($provider === 'google') {
            $params['nonce'] = $nonce;
        }

        return self::AUTH[$provider].'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withUserAgent('Dev2Done Login')->withoutRedirecting()->connectTimeout(3)->timeout(8)->withOptions(['verify' => true]);
    }

    private function json(Response $response): array
    {
        abort_unless($response->successful() && strlen($response->body()) <= 131072, 422, 'provider_response_invalid');
        $value = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
        abort_unless(is_array($value), 422, 'provider_response_invalid');

        return $value;
    }

    public function usableEmail(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 254 || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $email = DeveloperInvitations::normalizeEmail($value);
        if (preg_match('/(?:@users\.noreply\.github\.com$|@[^@]+\.invalid$|^noreply@|^no-reply@)/D', $email)) {
            return null;
        }

        return $email;
    }

    /** Tokens exist only within this call; callers receive a hashed stable identity and safe assurance facts. */
    public function exchange(string $provider, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $nonceHash, int $createdAt): array
    {
        abort_unless(app(RegistrationReadiness::class)->provider($provider), 503, 'provider_unavailable');
        $config = config('onboarding.providers.'.$provider);
        $token = $this->json($this->http()->asForm()->post(self::TOKEN[$provider], ['grant_type' => 'authorization_code', 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'], 'redirect_uri' => app(RegistrationReadiness::class)->callback($provider), 'code' => $code, 'code_verifier' => $verifier]));
        abort_unless(! isset($token['error']) && is_string($token['access_token'] ?? null) && strlen($token['access_token']) <= 8192 && $token['access_token'] !== '', 422, 'provider_response_invalid');
        if ($provider === 'google') {
            $claims = $this->google($token['id_token'] ?? null, $nonceHash, $createdAt, $config['client_id']);
            $subject = $claims['sub'];
            $email = $this->usableEmail($claims['email'] ?? null);
            $verified = $email !== null && in_array($claims['email_verified'] ?? null, [true, 'true'], true);
        } elseif ($provider === 'yandex') {
            $info = $this->json($this->http()->withHeaders(['Authorization' => 'OAuth '.$token['access_token']])->get('https://login.yandex.ru/info', ['format' => 'json']));
            abort_unless(is_string($info['client_id'] ?? null) && hash_equals($config['client_id'], $info['client_id']) && is_string($info['id'] ?? null) && preg_match('/^[1-9][0-9]{0,39}$/D', $info['id']), 422, 'provider_response_invalid');
            $subject = $info['id'];
            $email = $this->usableEmail($info['default_email'] ?? null);
            $verified = false;
        } else {
            $request = $this->http()->withToken($token['access_token'])->withHeaders(['X-GitHub-Api-Version' => '2026-03-10']);
            $info = $this->json($request->get('https://api.github.com/user'));
            abort_unless(is_int($info['id'] ?? null) && $info['id'] > 0, 422, 'provider_response_invalid');
            $subject = (string) $info['id'];
            $emails = $this->json($request->get('https://api.github.com/user/emails', ['per_page' => 100]));
            abort_unless(array_is_list($emails) && count($emails) <= 100, 422, 'provider_response_invalid');
            $candidates = collect($emails)->filter(fn ($e) => is_array($e) && $this->usableEmail($e['email'] ?? null) !== null);
            $trusted = $candidates->filter(fn ($e) => ($e['verified'] ?? null) === true)->sortByDesc(fn ($e) => ($e['primary'] ?? false) === true)->first();
            $email = $this->usableEmail($trusted['email'] ?? $candidates->first()['email'] ?? $info['email'] ?? null);
            $verified = $trusted !== null;
        }

        return ['provider' => $provider, 'issuer' => self::ISSUER[$provider], 'subjectHash' => app(TrialEligibility::class)->hash(self::ISSUER[$provider], $subject), 'email' => $email, 'emailVerified' => $verified];
    }

    private function google(mixed $token, string $nonceHash, int $createdAt, string $clientId): array
    {
        abort_unless(is_string($token) && strlen($token) <= 16384, 422, 'provider_response_invalid');
        $jws = (new CompactSerializer)->unserialize($token);
        $header = $jws->getSignature(0)->getProtectedHeader();
        abort_unless(($header['alg'] ?? null) === 'RS256' && is_string($header['kid'] ?? null) && strlen($header['kid']) <= 128 && ! array_diff(array_keys($header), ['alg', 'kid', 'typ']) && (! isset($header['typ']) || $header['typ'] === 'JWT'), 422, 'provider_response_invalid');
        $keys = Cache::remember('onboarding.google.jwks.v1', 300, fn () => $this->json($this->http()->get('https://www.googleapis.com/oauth2/v3/certs')));
        $key = collect($keys['keys'] ?? [])->first(fn ($k) => is_array($k) && ($k['kid'] ?? null) === $header['kid'] && ($k['kty'] ?? null) === 'RSA' && ($k['alg'] ?? 'RS256') === 'RS256' && ($k['use'] ?? 'sig') === 'sig');
        if (! $key) {
            Cache::forget('onboarding.google.jwks.v1');
            abort(422, 'provider_response_invalid');
        }
        abort_unless((new JWSVerifier(new AlgorithmManager([new RS256])))->verifyWithKey($jws, new JWK($key), 0), 422, 'provider_response_invalid');
        $claims = json_decode($jws->getPayload(), true, 16, JSON_THROW_ON_ERROR);
        $now = now('UTC')->timestamp;
        $aud = $claims['aud'] ?? null;
        abort_unless(is_array($claims) && in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true) && (is_string($aud) ? hash_equals($clientId, $aud) : (is_array($aud) && in_array($clientId, $aud, true) && ($claims['azp'] ?? null) === $clientId)) && (! isset($claims['azp']) || $claims['azp'] === $clientId) && is_int($claims['exp'] ?? null) && $claims['exp'] > $now && is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + 60 && $claims['iat'] >= $createdAt - 60 && $claims['exp'] > $claims['iat'] && is_string($claims['nonce'] ?? null) && hash_equals($nonceHash, hash('sha256', $claims['nonce'])) && is_string($claims['sub'] ?? null) && preg_match('/^[\x21-\x7E]{1,255}$/D', $claims['sub']), 422, 'provider_response_invalid');

        return $claims;
    }
}
