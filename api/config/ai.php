<?php

return [
    'enabled' => env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'groq'),
    'providers' => [
        'groq' => [
            'key' => env('GROQ_API_KEY', ''),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        ],
        'groq_bridge' => [
            'url' => env('AI_BRIDGE_URL', 'https://projectbrief-ai-bridge.web86.deno.net/ai'),
            'key' => env('AI_BRIDGE_SECRET', ''),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        ],
        'zveno' => [
            'key' => env('ZVENO_API_KEY', ''),
            'model' => env('AI_MODEL', 'nvidia/nemotron-3-super-120b-a12b:free'),
            'output_mode' => env('ZVENO_OUTPUT_MODE', 'json_schema'),
        ],
    ],
    'per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 5),
    'daily_limit' => (int) env('AI_WORKSPACE_DAILY_LIMIT', 50),
];
