<?php

return [
    'max_per_minute' => env('BROWSER_PDF_RATE_LIMIT', 5),
    'max_tasks' => 1000,
    'max_text_bytes' => 2 * 1024 * 1024,
    'max_json_bytes' => 8 * 1024 * 1024,
];
