<?php

return [
    'enabled' => env('AI_ENABLED', false),
    'key' => env('GROQ_API_KEY', ''),
    'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    'per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 5),
    'daily_limit' => (int) env('AI_WORKSPACE_DAILY_LIMIT', 50),
];
