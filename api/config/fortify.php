<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'username' => 'email',
    'views' => false,
    'features' => [Features::twoFactorAuthentication(['confirm' => true]), Features::passkeys()],
    'passkeys' => [
        'relying_party_id' => env('PASSKEY_RP_ID', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        'allowed_origins' => [rtrim(env('PASSKEY_ORIGIN', env('APP_URL', 'http://localhost')), '/')],
        'user_handle_secret' => env('PASSKEY_USER_HANDLE_SECRET', env('APP_KEY')),
        'timeout' => 60000,
    ],
];
