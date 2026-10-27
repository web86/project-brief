<?php

namespace App\Services;

use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;

class StoreAccountPasskey extends StorePasskey
{
    protected function validate(AuthenticatorAttestationResponse $response, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        request()->attributes->set('security.stage', 'webauthn');

        return parent::validate($response, $options);
    }

    protected function ensureCredentialIsUnique(CredentialRecord $source): void
    {
        request()->attributes->set('security.stage', 'uniqueness');
        parent::ensureCredentialIsUnique($source);
    }

    public function createPasskey(PasskeyUser $user, string $name, CredentialRecord $source): Passkey
    {
        request()->attributes->set('security.stage', 'persist');

        return parent::createPasskey($user, $name, $source);
    }
}
