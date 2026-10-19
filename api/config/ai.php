<?php

return [
    'enabled' => env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'groq'),
    'providers' => [
        'groq' => [
            'key' => env('GROQ_API_KEY', ''),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        ],
        'zveno' => [
            'key' => env('ZVENO_API_KEY', ''),
            'model' => env('AI_MODEL', 'nvidia/nemotron-3-super-120b-a12b:free'),
        ],
    ],
    'per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 5),
    'daily_limit' => (int) env('AI_WORKSPACE_DAILY_LIMIT', 50),
];
