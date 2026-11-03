<?php

namespace Tests\Support;

final class SocialProviderFixture
{
    private \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function jwks(): array
    {
        $rsa = openssl_pkey_get_details($this->key)['rsa'];

        return ['keys' => [['kty' => 'RSA', 'kid' => 'synthetic-google-key', 'use' => 'sig', 'alg' => 'RS256', 'n' => $this->base64($rsa['n']), 'e' => $this->base64($rsa['e'])]]];
    }

    public function token(string $nonce, array $claims = [], array $headers = []): string
    {
        $body = ['iss' => 'https://accounts.google.com', 'aud' => 'synthetic-google-client', 'sub' => 'stable-google-subject', 'email' => 'social@example.test', 'email_verified' => true, 'iat' => now()->timestamp, 'exp' => now()->addMinutes(10)->timestamp, 'nonce' => $nonce];
        $signing = $this->base64(json_encode(['alg' => 'RS256', 'kid' => 'synthetic-google-key', ...$headers], JSON_THROW_ON_ERROR)).'.'.$this->base64(json_encode([...$body, ...$claims], JSON_THROW_ON_ERROR));
        openssl_sign($signing, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $signing.'.'.$this->base64($signature);
    }
}
