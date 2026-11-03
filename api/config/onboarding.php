<?php

return [
    'registration_enabled' => env('REGISTRATION_ENABLED', false),
    // A configuration value cannot enable an unfinished commercial lifecycle.
    'trial_activation_requested' => env('TRIAL_ACTIVATION_ENABLED', false),
    'subject_retention_days' => env('ONBOARDING_SUBJECT_RETENTION_DAYS'),
    'verification_minutes' => 60,
    'resend_seconds' => 60,
    'attempt_minutes' => 10,
    'providers' => array_combine(['google', 'yandex', 'github'], array_map(fn (string $provider): array => [
        'client_id' => env(strtoupper($provider).'_LOGIN_CLIENT_ID'),
        'client_secret' => env(strtoupper($provider).'_LOGIN_CLIENT_SECRET'),
        'callback' => env(strtoupper($provider).'_LOGIN_CALLBACK'),
    ], ['google', 'yandex', 'github'])),
];
