<?php

return [
    // Diagnostic counters only. Never use these for billing or quota enforcement.
    'metrics_enabled' => env('PLATFORM_API_METRICS_ENABLED', true),
];
