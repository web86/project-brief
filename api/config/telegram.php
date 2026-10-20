<?php

return [
    'transport' => env('TELEGRAM_TRANSPORT', 'direct'),
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'bridge_url' => env('TELEGRAM_BRIDGE_URL'),
    'bridge_secret' => env('TELEGRAM_BRIDGE_SECRET'),
];
