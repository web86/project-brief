<?php

return [
    'admin_locale' => env('NOTIFICATION_ADMIN_LOCALE', 'ru'),
    'vapid' => ['subject' => env('VAPID_SUBJECT'), 'public_key' => env('VAPID_PUBLIC_KEY'), 'private_key' => env('VAPID_PRIVATE_KEY')],
];
