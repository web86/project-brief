<?php

namespace Tests\Support;

use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\Encoder;
use CBOR\MapObject;
use ParagonIE\ConstantTime\Base64UrlSafe;

/** A software authenticator used only for signed protocol tests. */
class WebAuthnDevice
{
    private \OpenSSLAsymmetricKey $key;

    public string $id;

    private int $count = 0;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->id = random_bytes(32);
    }

    public function registration(array $options, string $origin = 'http://localhost', bool $uv = true): array
    {
        $details = openssl_pkey_get_details($this->key)['ec'];
        $cose = (new Encoder)->encode([1 => 2, 3 => -7, -1 => 1, -2 => ByteStringObject::create($details['x']), -3 => ByteStringObject::create($details['y'])]);
        $auth = hash('sha256', $options['rp']['id'], true).chr($uv ? 0x45 : 0x41).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($this->id)).$this->id.$cose;
        $attestation = (new Encoder)->encode(['fmt' => 'none', 'attStmt' => MapObject::create(), 'authData' => ByteStringObject::create($auth)]);

        return $this->credential(['clientDataJSON' => $this->clientData('webauthn.create', $options['challenge'], $origin), 'attestationObject' => $this->b64($attestation), 'transports' => ['internal']]);
    }

    public function assertion(User $user, array $options, bool $uv = true, string $origin = 'http://localhost', ?string $rp = null, ?string $handle = null, ?int $count = null): array
    {
        $client = $this->clientData('webauthn.get', $options['challenge'], $origin);
        $auth = hash('sha256', $rp ?? $options['rpId'], true).chr($uv ? 0x05 : 0x01).pack('N', $count ?? ++$this->count);
        openssl_sign($auth.hash('sha256', Base64UrlSafe::decodeNoPadding($client), true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credential(['clientDataJSON' => $client, 'authenticatorData' => $this->b64($auth), 'signature' => $this->b64($signature), 'userHandle' => $this->b64($handle ?? $user->getPasskeyUserHandle())]);
    }

    public function b64(string $value): string
    {
        return Base64UrlSafe::encodeUnpadded($value);
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return $this->b64(json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]));
    }

    private function credential(array $response): array
    {
        return ['id' => $this->b64($this->id), 'rawId' => $this->b64($this->id), 'type' => 'public-key', 'response' => $response, 'clientExtensionResults' => [], 'authenticatorAttachment' => 'platform'];
    }
}
