<?php

return [
    // Enable only after Jino peer-IP/proxy and shared-NAT preflight. Not a production threshold.
    'bootstrap_enabled' => (bool) env('HTTP_BOOTSTRAP_LIMIT_ENABLED', false),
    'bootstrap_per_minute' => (int) env('HTTP_BOOTSTRAP_LIMIT_PER_MINUTE', 120),
];
