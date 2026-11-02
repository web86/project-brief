<?php

return [
    'enabled' => (bool) env('DEMO_ENABLED', false),
    'read_only' => (bool) env('DEMO_READ_ONLY', true),
    'installation_id' => env('DEMO_INSTALLATION_ID'),
    'password' => env('DEMO_ADMIN_PASSWORD'),
];
